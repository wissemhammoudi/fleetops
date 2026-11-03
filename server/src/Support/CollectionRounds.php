<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CollectionRounds
{
    public static function plan(string $company, array $input = []): array
    {
        return self::transaction($company, function () use ($company, $input) {
            $rounds = [];
            $warnings = [];
            $calendar = CollectionSchedule::forCompany($company);
            $capacities = $calendar['capacity_planning'] ? CollectionCapacity::candidates($company, $calendar) : [];
            $query = Order::where('company_uuid', $company)->where('meta->collection_workflow->state', 'ready_for_pickup');
            if (isset($input['order_uuids'])) $query->whereIn('uuid', $input['order_uuids']);
            $orders = $query
                ->orderBy('uuid')->lockForUpdate()->get();
            if (isset($input['order_uuids'])) {
                abort_unless($orders->count() === count(array_unique($input['order_uuids'])), 409, 'Some selected orders are no longer ready. Refresh before planning.');
            }
            foreach ($orders as $order) {
                try {
                    CollectionReadiness::assertReadyForPlanning($order);
                    $dispatcher = data_get($order->meta, 'collection_workflow.scheduling_mode') === 'dispatcher';
                    $planned = $dispatcher ? CollectionSchedule::pickupAt(now(), $calendar) : Carbon::parse(data_get($order->meta, 'collection_workflow.planned_collection_at'));
                    if (!empty($input['pickup_date'])) {
                        abort_unless($dispatcher, 409, 'A historical customer pickup window cannot be moved. Reconfirm readiness before choosing a new date.');
                        $planned = self::departure($input['pickup_date'], $calendar);
                    }
                    $cutoff = $planned->copy()->setTimezone($calendar['timezone'])->setTimeFromTimeString($calendar['cutoff_time']);
                    if (!$dispatcher && now()->lessThan($cutoff)) {
                        continue;
                    }
                    self::assertCalendar($company, $planned, data_get($order->meta, 'collection_workflow.pickup_window.end'));
                    abort_if(data_get($order->meta, 'collection_workflow.pickup_window.end')
                        && now()->greaterThanOrEqualTo(Carbon::parse(data_get($order->meta, 'collection_workflow.pickup_window.end'))),
                        409, 'The legacy pickup window has expired. Ask the customer to withdraw and reconfirm readiness; the Dispatcher will organize the new date.');
                    $stops = CollectionReadiness::pickupStops($order);
                    abort_if($calendar['capacity_planning'] && collect($stops)->contains(fn ($stop) => !isset($stop['location'])),
                        409, 'A pickup map location is missing. Staff must verify the address before capacity-aware planning.');
                } catch (HttpException $exception) {
                    if ($exception->getStatusCode() !== 409) {
                        throw $exception;
                    }
                    $warnings[] = ['order_uuid' => $order->uuid, 'message' => $exception->getMessage()];
                    continue;
                }
                $capacity = $capacities[0]['parcel_capacity'] ?? null;
                if ($calendar['capacity_planning'] && (!$capacity || CollectionCapacity::parcels($stops) > $capacity || count($stops) > $calendar['max_pickup_stops'])) {
                    $warnings[] = ['order_uuid' => $order->uuid, 'message' => $capacity
                        ? 'This complete order exceeds the available parcel or stop capacity. Staff must review; parcels have not been split.'
                        : 'No driver has a vehicle parcel capacity or staff fallback limit. Configure capacity before planning.'];
                    continue;
                }
                $existing = CollectionRound::where('company_uuid', $company)->where('scheduled_at', $planned)->lockForUpdate()->get();
                $round = $calendar['capacity_planning']
                    ? $existing->first(fn ($candidate) => $candidate->status === 'draft'
                        && !$candidate->driver_uuid
                        && CollectionCapacity::parcels([...$candidate->stops, ...$stops]) <= $capacity
                        && count($candidate->stops) + count($stops) <= $calendar['max_pickup_stops'])
                    : $existing->first();
                if ($round && $round->status !== 'draft') {
                    $warnings[] = ['order_uuid' => $order->uuid, 'message' => 'The daily round is already released or closed. Operations must review this missed collection.'];
                    continue;
                }
                if (!$round) {
                    $round = new CollectionRound(['uuid' => (string) Str::uuid(), 'company_uuid' => $company,
                        'scheduled_at' => $planned, 'timezone' => $calendar['timezone'], 'status' => 'draft',
                        'stops' => [], 'history' => [], 'revision' => 0]);
                }
                $round->stops = [...$round->stops, ...$stops];
                if ($calendar['capacity_planning']) {
                    $round->stops = CollectionCapacity::nearby($round->stops);
                }
                self::audit($round, 'order_added', ['order_uuid' => $order->uuid]);
                $order->recordCollectionRound($round->uuid, 'pickup_scheduled');
                if ($dispatcher) {
                    $meta = $order->meta;
                    $meta['collection_workflow']['planned_collection_at'] = $planned->toISOString();
                    $meta['collection_workflow']['timezone'] = $calendar['timezone'];
                    $order->meta = $meta;
                }
                $order->save();
                $rounds[$round->uuid] = $round;
            }
            foreach ($warnings as $warning) {
                Log::warning('Collection round planning requires review.', ['company_uuid' => $company] + $warning);
            }

            return ['rounds' => array_values($rounds), 'warnings' => $warnings];
        });
    }

    public static function operate(string $company, string $id, array $input): CollectionRound
    {
        return self::transaction($company, function () use ($company, $id, $input) {
            $round = self::find($company, $id);
            self::revision($round, $input);
            $action = $input['action'];
            if ($action === 'assign') {
                abort_unless(in_array($round->status, ['draft', 'released'], true), 409, 'A started round cannot be reassigned. Escalate custody changes to operations.');
                $driver = Driver::where('company_uuid', $company)->where('uuid', $input['driver_uuid'])->first();
                abort_unless($driver, 422, 'Select an active driver from this company.');
                CollectionCapacity::assertAssignment($round, $driver, CollectionSchedule::forCompany($company));
                $depot = DB::connection($round->getConnectionName())->table('places')->where('company_uuid', $company)
                    ->where('uuid', $input['depot_uuid'])->whereNull('deleted_at')->select('*')->selectRaw('ST_AsText(location) as location_wkt')->first();
                abort_unless($depot && trim((string) $depot->street1) !== '', 422, 'Select a depot with a complete street address from this company.');
                $round->driver_uuid = $driver->uuid;
                $round->depot = \Illuminate\Support\Arr::only((array) $depot, ['uuid', 'name', 'street1', 'street2', 'city', 'province', 'postal_code', 'country', 'phone', 'location_wkt']);
            } elseif ($action === 'reschedule') {
                abort_unless($round->status === 'draft' && count($round->stops), 409, 'Only nonempty draft pickups can be moved. Recall released work before changing its date.');
                $calendar = CollectionSchedule::forCompany($company);
                $orders = self::orders($round);
                foreach ($orders as $order) {
                    self::assertContract($order, $round);
                    abort_unless(data_get($order->meta, 'collection_workflow.scheduling_mode') === 'dispatcher', 409,
                        'Historical customer pickup windows cannot be moved. Reconfirm readiness first.');
                }
                $planned = self::departure($input['pickup_date'], $calendar);
                abort_if(!$calendar['capacity_planning'] && CollectionRound::where('company_uuid', $company)
                    ->where('uuid', '!=', $round->uuid)->where('scheduled_at', $planned)->exists(), 409,
                    'A round already occupies this date. Choose another operating date.');
                $round->scheduled_at = $planned;
                $round->timezone = $calendar['timezone'];
                if (!empty($input['driver_uuid'])) {
                    $round->driver_uuid = $input['driver_uuid'];
                }
                if ($round->driver_uuid) {
                    $driver = Driver::where('company_uuid', $company)->where('uuid', $round->driver_uuid)->first();
                    abort_unless($driver, 422, 'Select an active driver from this company.');
                    CollectionCapacity::assertAssignment($round, $driver, $calendar);
                }
                foreach ($orders as $order) {
                    $order->recordCollectionPlanDate('pickup', $planned->toISOString(), $calendar['timezone']);
                    $order->save();
                }
            } elseif ($action === 'release') {
                abort_unless($round->status === 'draft' && $round->driver_uuid && $round->depot && count($round->stops), 409, 'Assign a driver and depot to a nonempty draft before release.');
                self::assertCalendar($company, $round->scheduled_at, data_get(self::orders($round)->first()?->meta, 'collection_workflow.pickup_window.end'));
                abort_unless(Driver::where('uuid', $round->driver_uuid)->where('company_uuid', $company)->exists(), 409, 'The assigned driver is no longer available.');
                CollectionCapacity::assertAssignment($round, Driver::where('uuid', $round->driver_uuid)->where('company_uuid', $company)->firstOrFail(), CollectionSchedule::forCompany($company));
                $orders = self::orders($round);
                foreach ($orders as $order) {
                    self::assertContract($order, $round);
                }

                CollectionDepot::prepare($round, $orders);
                $round->status = 'released';
            } elseif ($action === 'cancel') {
                abort_unless(in_array($round->status, ['draft', 'released'], true), 409, 'Only an unstarted round can be recalled.');
                foreach (self::orders($round) as $order) {
                    $order->recordCollectionRound($round->uuid, 'awaiting_preparation', true);
                    $order->save();
                }
                self::audit($round, 'recalled_stops', ['stops' => $round->stops, 'custody' => $round->custody]);
                // Keep the unique daily slot: recall empties the draft, allowing fresh readiness to refill it.
                $round->status = 'draft';
                $round->stops = [];
                $round->driver_uuid = null;
                $round->depot = null;
                $round->custody = null;
            }
            self::audit($round, $action, ['driver_uuid' => $round->driver_uuid, 'depot' => $round->depot, 'scheduled_at' => $round->scheduled_at->toISOString()]);

            return $round;
        });
    }

    private static function departure(string $date, array $calendar): \Carbon\CarbonInterface
    {
        CollectionSchedule::pickupSlots(now(), $calendar, $date);

        return Carbon::parse($date . ' ' . $calendar['departure_time'], $calendar['timezone'])->utc();
    }

    public static function drive(string $company, string $driver, string $id, array $input): CollectionRound
    {
        return self::transaction($company, function () use ($company, $driver, $id, $input) {
            $round = self::find($company, $id);
            abort_unless($round->driver_uuid === $driver && $round->status !== 'draft', 404, 'Pickup round not found.');
            if ($input['action'] === 'start') {
                if ($round->status === 'in_progress') {
                    return $round;
                }
                self::revision($round, $input);
                abort_unless($round->status === 'released' && now()->greaterThanOrEqualTo($round->scheduled_at), 409, 'The round is not released or its departure time has not arrived.');
                self::assertCalendar($company, $round->scheduled_at, data_get(self::orders($round)->first()?->meta, 'collection_workflow.pickup_window.end'));
                foreach (self::orders($round) as $order) {
                    self::assertContract($order, $round);
                    $order->recordCollectionRound($round->uuid, 'pickup_in_progress');
                    $order->save();
                }
                $round->status = 'in_progress';
                self::audit($round, 'started', ['driver_uuid' => $driver]);

                return $round;
            }
            $stops = $round->stops;
            $index = collect($stops)->search(fn ($stop) => $stop['uuid'] === $input['stop_uuid']);
            abort_if($index === false, 404, 'Pickup stop not found on this round.');
            $stop = $stops[$index];
            $requestHash = hash('sha256', json_encode(\Illuminate\Support\Arr::except($input, ['revision']), JSON_THROW_ON_ERROR));
            if (($stop['request_id'] ?? null) === $input['request_id']) {
                abort_unless(($stop['request_hash'] ?? null) === $requestHash, 409, 'This retry identifier was already used with a different pickup result.');

                return $round;
            }
            self::revision($round, $input);
            abort_unless($round->status === 'in_progress' && $stop['status'] === 'pending', 409, 'This stop is closed or the round is not in progress.');
            $order = Order::where('company_uuid', $company)->where('uuid', $stop['order_uuid'])->lockForUpdate()->firstOrFail();
            $outcome = $input['outcome'];
            $inspection = null;
            if (in_array($outcome, ['collected', 'packaging_refused'], true)) {
                $inspection = PackagingInspection::result($input, $driver, PackagingInspection::fingerprint($order));
                abort_unless(($inspection['status'] === 'accepted') === ($outcome === 'collected'), 422, 'The packaging check must match the pickup result.');
            }
            if ($outcome === 'collected') {
                self::assertContract($order, $round);
            }
            $stops[$index] = $stop + ['request_id' => $input['request_id'], 'request_hash' => $requestHash,
                'reason' => trim($input['reason'] ?? ''), 'inspection' => $inspection, 'at' => now()->toISOString(), 'driver_uuid' => $driver];
            $stops[$index]['status'] = $outcome;
            $round->stops = $stops;
            $orderStops = collect($stops)->where('order_uuid', $order->uuid);
            if (!$orderStops->contains('status', 'pending')) {
                $collected = $orderStops->where('status', 'collected')->count();
                $state = $collected === $orderStops->count() ? 'collected' : ($collected ? 'pickup_exception' : 'awaiting_preparation');
                $order->recordCollectionRound($round->uuid, $state, $state === 'awaiting_preparation');
                $order->save();
            }
            if (!collect($stops)->contains('status', 'pending')) {
                $round->status = collect($stops)->contains('status', 'collected') ? 'awaiting_depot' : 'closed';
            }
            self::audit($round, 'stop_recorded', ['stop_uuid' => $stop['uuid'], 'outcome' => $outcome, 'driver_uuid' => $driver]);

            return $round;
        });
    }

    private static function find(string $company, string $id): CollectionRound
    {
        return CollectionRound::where('company_uuid', $company)->where('uuid', $id)->lockForUpdate()->firstOrFail();
    }

    private static function orders(CollectionRound $round)
    {
        $ids = array_unique(array_column($round->stops, 'order_uuid'));
        $orders = Order::where('company_uuid', $round->company_uuid)->whereIn('uuid', $ids)->orderBy('uuid')->lockForUpdate()->get();
        abort_unless(count($ids) === $orders->count(), 409, 'A round order is missing. Operations review is required.');

        return $orders;
    }

    private static function assertContract(Order $order, CollectionRound $round): void
    {
        self::assertCalendar($round->company_uuid, $round->scheduled_at, data_get($order->meta, 'collection_workflow.pickup_window.end'));
        abort_if(data_get($order->meta, 'collection_workflow.pickup_window.end')
            && now()->greaterThanOrEqualTo(Carbon::parse(data_get($order->meta, 'collection_workflow.pickup_window.end'))),
            409, 'The legacy pickup window has expired. Recall this unstarted round, then ask the customer to withdraw and reconfirm readiness for Dispatcher planning.');
        abort_unless(data_get($order->meta, 'collection_workflow.collection_round_uuid') === $round->uuid
            && in_array(data_get($order->meta, 'collection_workflow.state'), ['pickup_scheduled', 'pickup_in_progress'], true)
            && in_array($order->status, ['created', 'pending', 'pickup_scheduled', 'pickup_in_progress'], true)
            && data_get($order->meta, 'collection_workflow.content_fingerprint') === CollectionReadiness::fingerprint($order),
            409, 'A pickup contract changed or closed. Stop and contact operations; do not collect against stale instructions.');
    }

    private static function revision(CollectionRound $round, array $input): void
    {
        abort_unless($round->revision === (int) $input['revision'], 409, 'The round changed. Refresh before continuing.');
    }

    private static function assertCalendar(string $company, \Carbon\CarbonInterface $planned, ?string $end = null): void
    {
        $calendar = CollectionSchedule::forCompany($company);
        abort_unless(CollectionSchedule::containsPickup($planned, $calendar, $end), 409,
            'The collection calendar changed. Recall any unstarted round, then ask the customer to withdraw and reconfirm readiness for Dispatcher planning.');
    }

    private static function audit(CollectionRound $round, string $event, array $detail = []): void
    {
        $round->history = [...$round->history, ['event' => $event, 'at' => now()->toISOString(), 'actor_uuid' => session('user')] + $detail];
        $round->revision++;
        $round->save();
    }

    private static function transaction(string $company, callable $work)
    {
        $connection = DB::connection(config('fleetbase.connection.db', 'mysql'));

        return $connection->transaction(function () use ($connection, $company, $work) {
            // Serialize planning and round mutations per tenant, including creation of the first daily round.
            abort_unless($connection->table('companies')->where('uuid', $company)->lockForUpdate()->first(['uuid']), 404, 'Company not found.');

            return $work();
        }, 3);
    }
}
