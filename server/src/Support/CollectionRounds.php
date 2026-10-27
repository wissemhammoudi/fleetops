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
    public static function plan(string $company): array
    {
        return self::transaction($company, function () use ($company) {
            $rounds = [];
            $warnings = [];
            $calendar = CollectionSchedule::forCompany($company);
            $orders = Order::where('company_uuid', $company)->where('meta->collection_workflow->state', 'ready_for_pickup')
                ->orderBy('uuid')->lockForUpdate()->get();
            foreach ($orders as $order) {
                try {
                    CollectionReadiness::assertReadyForPlanning($order);
                    $planned = Carbon::parse(data_get($order->meta, 'collection_workflow.planned_collection_at'));
                    $cutoff = $planned->copy()->setTimezone($calendar['timezone'])->setTimeFromTimeString($calendar['cutoff_time']);
                    if (now()->lessThan($cutoff)) {
                        continue;
                    }
                    self::assertCalendar($company, $planned);
                    $stops = CollectionReadiness::pickupStops($order);
                } catch (HttpException $exception) {
                    if ($exception->getStatusCode() !== 409) {
                        throw $exception;
                    }
                    $warnings[] = ['order_uuid' => $order->uuid, 'message' => $exception->getMessage()];
                    continue;
                }
                $round = CollectionRound::where('company_uuid', $company)->where('scheduled_at', $planned)->lockForUpdate()->first();
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
                self::audit($round, 'order_added', ['order_uuid' => $order->uuid]);
                $order->recordCollectionRound($round->uuid, 'pickup_scheduled');
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
                $depot = DB::connection($round->getConnectionName())->table('places')->where('company_uuid', $company)
                    ->where('uuid', $input['depot_uuid'])->whereNull('deleted_at')->select('*')->selectRaw('ST_AsText(location) as location_wkt')->first();
                abort_unless($depot && trim((string) $depot->street1) !== '', 422, 'Select a depot with a complete street address from this company.');
                $round->driver_uuid = $driver->uuid;
                $round->depot = \Illuminate\Support\Arr::only((array) $depot, ['uuid', 'name', 'street1', 'street2', 'city', 'province', 'postal_code', 'country', 'phone', 'location_wkt']);
            } elseif ($action === 'release') {
                abort_unless($round->status === 'draft' && $round->driver_uuid && $round->depot && count($round->stops), 409, 'Assign a driver and depot to a nonempty draft before release.');
                self::assertCalendar($company, $round->scheduled_at);
                abort_unless(Driver::where('uuid', $round->driver_uuid)->where('company_uuid', $company)->exists(), 409, 'The assigned driver is no longer available.');
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
            self::audit($round, $action, ['driver_uuid' => $round->driver_uuid, 'depot' => $round->depot]);

            return $round;
        });
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
                self::assertCalendar($company, $round->scheduled_at);
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
        abort_unless(data_get($order->meta, 'collection_workflow.collection_round_uuid') === $round->uuid
            && in_array(data_get($order->meta, 'collection_workflow.state'), ['pickup_scheduled', 'pickup_in_progress'], true)
            && in_array($order->status, ['created', 'pending'], true)
            && data_get($order->meta, 'collection_workflow.content_fingerprint') === CollectionReadiness::fingerprint($order),
            409, 'A pickup contract changed or closed. Stop and contact operations; do not collect against stale instructions.');
    }

    private static function revision(CollectionRound $round, array $input): void
    {
        abort_unless($round->revision === (int) $input['revision'], 409, 'The round changed. Refresh before continuing.');
    }

    private static function assertCalendar(string $company, Carbon $planned): void
    {
        $calendar = CollectionSchedule::forCompany($company);
        $localDay = $planned->copy()->setTimezone($calendar['timezone'])->startOfDay();
        abort_unless(CollectionSchedule::pickupAt($localDay, $calendar)->equalTo($planned), 409,
            'The collection calendar changed. Recall the unstarted round and ask the customer to confirm the new pickup date.');
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
