<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
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
                        && !$candidate->routing
                        && !$candidate->driver_uuid
                        && CollectionCapacity::parcels([...$candidate->stops, ...$stops]) <= $capacity
                        && count($candidate->stops) + count($stops) <= $calendar['max_pickup_stops'])
                    : $existing->first(fn ($candidate) => !$candidate->routing);
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
            abort_if(data_get($round->routing, 'plan_uuid') && in_array($action, ['assign', 'reschedule'], true),
                409, 'Recall this optimized draft and orchestrate again to change its driver, route or date.');
            if ($action === 'assign') {
                abort_unless(in_array($round->status, ['draft', 'released'], true), 409, 'A started round cannot be reassigned. Escalate custody changes to operations.');
                $driver = Driver::where('company_uuid', $company)->where('uuid', $input['driver_uuid'])->lockForUpdate()->first();
                abort_unless($driver, 422, 'Select an active driver from this company.');
                CollectionCapacity::assertAssignment($round, $driver, CollectionSchedule::forCompany($company));
                $depot = DB::connection($round->getConnectionName())->table('places')->where('company_uuid', $company)
                    ->where('uuid', $input['depot_uuid'])->whereNull('deleted_at')->select('*')->selectRaw('ST_AsText(location) as location_wkt')->first();
                abort_unless($depot && trim((string) $depot->street1) !== '', 422, 'Select a depot with a complete street address from this company.');
                if (isset($input['start_place_uuid']) || $round->routing) {
                    abort_unless($round->status === 'draft', 409, 'Route origins can only change on draft pickups. Recall released work before replanning.');
                    abort_if($round->routing && !isset($input['start_place_uuid'])
                        && ($round->routing['driver_uuid'] !== $driver->uuid || $round->routing['vehicle_uuid'] !== $driver->vehicle_uuid
                            || $round->routing['destination']['uuid'] !== $input['depot_uuid']),
                        409, 'Review and explicitly select the vehicle starting place when changing the driver, vehicle or depot.');
                    self::assertRoutingVehicle($driver);
                    $destination = PlanningPlaces::snapshot($company, $input['depot_uuid']);
                    $origin = PlanningPlaces::snapshot($company, $input['start_place_uuid'] ?? $round->routing['origin']['uuid']);
                    $round->routing = ['origin' => $origin, 'destination' => $destination,
                        'driver_uuid' => $driver->uuid, 'vehicle_uuid' => $driver->vehicle_uuid,
                        'recorded_at' => now()->toISOString(), 'actor_uuid' => session('user')];
                }
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
                self::assertCalendar($company, $round->scheduled_at, data_get(self::orders($round)->first()?->meta, 'collection_workflow.pickup_window.end'), (bool) data_get($round->routing, 'plan_uuid'));
                $driver = Driver::where('uuid', $round->driver_uuid)->where('company_uuid', $company)->lockForUpdate()->first();
                abort_unless($driver, 409, 'The assigned driver is no longer available.');
                CollectionCapacity::assertAssignment($round, $driver, CollectionSchedule::forCompany($company));
                if ($round->routing) {
                    abort_unless($round->routing['driver_uuid'] === $driver->uuid
                        && $round->routing['vehicle_uuid'] === $driver->vehicle_uuid
                        && $round->routing['destination']['uuid'] === $round->depot['uuid'],
                        409, 'The driver, vehicle or depot changed. Review the route origin before releasing this pickup.');
                    self::assertRoutingVehicle($driver);
                    PlanningPlaces::assertCurrent($company, $round->routing['origin']);
                    PlanningPlaces::assertCurrent($company, $round->routing['destination']);
                    if (data_get($round->routing, 'plan_uuid')) {
                        abort_unless(now()->lessThan($round->routing['ends_at']), 409, 'The reviewed pickup route is stale. Recall and orchestrate again.');
                        $capacity = CollectionCapacity::driverLimit($driver, CollectionSchedule::forCompany($company));
                        abort_unless($capacity && CollectionCapacity::parcels($round->stops) <= $capacity,
                            409, 'Vehicle capacity changed. Recall and orchestrate again.');
                    }
                }
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
                $round->routing = null;
            }
            self::audit($round, $action, ['driver_uuid' => $round->driver_uuid, 'depot' => $round->depot,
                'routing' => $round->routing, 'scheduled_at' => $round->scheduled_at->toISOString()]);

            return $round;
        });
    }

    private static function assertRoutingVehicle(Driver $driver): void
    {
        abort_unless($driver->vehicle_uuid && Vehicle::where('uuid', $driver->vehicle_uuid)->where('company_uuid', $driver->company_uuid)->lockForUpdate()->first(['uuid']),
            422, 'Assign an active vehicle from this company to the driver before recording or releasing a route origin.');
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
            $startingRound = $input['action'] === 'start_visit' && $round->status === 'released';
            if ($input['action'] === 'start' || ($input['action'] === 'start_visit' && $round->status === 'released')) {
                if ($round->status === 'in_progress') {
                    return $round;
                }
                self::revision($round, $input);
                abort_unless($round->status === 'released' && now()->greaterThanOrEqualTo($round->scheduled_at), 409, 'The round is not released or its departure time has not arrived.');
                self::assertCalendar($company, $round->scheduled_at, data_get(self::orders($round)->first()?->meta, 'collection_workflow.pickup_window.end'), (bool) data_get($round->routing, 'plan_uuid'));
                foreach (self::orders($round) as $order) {
                    self::assertContract($order, $round);
                    $order->recordCollectionRound($round->uuid, 'pickup_in_progress');
                    $order->save();
                }
                $round->status = 'in_progress';
                self::audit($round, 'started', ['driver_uuid' => $driver]);

                if ($input['action'] === 'start') return $round;
            }
            $stops = $round->stops;
            $index = collect($stops)->search(fn ($stop) => $stop['uuid'] === $input['stop_uuid']);
            abort_if($index === false, 404, 'Pickup stop not found on this round.');
            $stop = $stops[$index];
            if ($input['action'] === 'start_visit') {
                abort_unless($round->status === 'in_progress' && $stop['status'] === 'pending', 409, 'This pickup is no longer available to start.');
                $next = collect($stops)->firstWhere('status', 'pending');
                foreach (data_get($round->routing, 'stops', []) as $visit) {
                    if (($visit['kind'] ?? 'pickup') === 'delivery') {
                        $delivery = Order::where('company_uuid', $company)->where('uuid', $visit['order_uuid'])->first();
                        abort_unless($delivery && in_array($delivery->status, DriverNavigation::TERMINAL_STATUSES), 409, 'Complete the earlier delivery before starting this pickup.');
                    } else {
                        $pending = collect($stops)->first(fn ($item) => $item['order_uuid'] === $visit['order_uuid'] && $item['status'] === 'pending');
                        if ($pending) { $next = $pending; break; }
                    }
                }
                abort_unless(($next['uuid'] ?? null) === $stop['uuid'], 409, 'Start the next pickup in the saved route order.');
                $order = Order::where('company_uuid', $company)->where('uuid', $stop['order_uuid'])->firstOrFail();
                self::assertContract($order, $round);
                $alreadyStarted = data_get($round->routing, 'active_stop_uuid') === $stop['uuid'];
                if (!$startingRound && !$alreadyStarted) self::revision($round, $input);
                DriverNavigation::pickup($round, $stop);
                if ($alreadyStarted) return $round;
                $routing = $round->routing ?? [];
                $routing['active_stop_uuid'] = $stop['uuid'];
                $round->routing = $routing;
                self::audit($round, 'visit_started', ['stop_uuid' => $stop['uuid'], 'driver_uuid' => $driver]);
                return $round;
            }
            $requestHash = hash('sha256', json_encode(\Illuminate\Support\Arr::except($input, ['revision']), JSON_THROW_ON_ERROR));
            if (($stop['request_id'] ?? null) === $input['request_id']) {
                abort_unless(($stop['request_hash'] ?? null) === $requestHash, 409, 'This retry identifier was already used with a different pickup result.');

                return $round;
            }
            self::revision($round, $input);
            abort_unless($round->status === 'in_progress' && $stop['status'] === 'pending', 409, 'This stop is closed or the round is not in progress.');
            $assignedDriver = Driver::where('company_uuid', $company)->where('uuid', $driver)->firstOrFail();
            $activeVisit = DriverNavigation::active($assignedDriver);
            abort_unless(($activeVisit['kind'] ?? null) === 'pickup' && ($activeVisit['round_uuid'] ?? null) === $round->uuid
                && ($activeVisit['stop_uuid'] ?? null) === $stop['uuid'], 409, 'Press Start pickup for this visit before recording its result.');
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
            if ($outcome === 'collected') {
                $stops[$index] = CollectionHandovers::report($round, $order, $stops[$index]);
            }
            $round->stops = $stops;
            $routing = $round->routing ?? [];
            if (($routing['active_stop_uuid'] ?? null) === $stop['uuid']) {
                unset($routing['active_stop_uuid']);
                $round->routing = $routing;
            }
            $orderStops = collect($stops)->where('order_uuid', $order->uuid);
            if (!$orderStops->contains('status', 'pending')) {
                $collected = $orderStops->where('status', 'collected')->count();
                $state = $collected === $orderStops->count() ? 'collected' : ($collected ? 'pickup_exception' : 'awaiting_preparation');
                $order->recordCollectionRound($round->uuid, $state, $state === 'awaiting_preparation');
            }
            if ($order->isDirty()) {
                $order->save();
            }
            if (!collect($stops)->contains('status', 'pending')) {
                $round->status = collect($stops)->contains('status', 'collected') ? 'awaiting_depot' : 'closed';
            }
            self::audit($round, 'stop_recorded', ['stop_uuid' => $stop['uuid'], 'outcome' => $outcome, 'driver_uuid' => $driver]);

            return $round;
        });
    }

    public static function customerHandover(string $company, callable $resolveOwnedOrder, array $input): Order
    {
        abort_unless(session('user') && !session('is_driver'), 403, 'An authenticated customer is required.');

        return self::transaction($company, function () use ($company, $resolveOwnedOrder, $input) {
            // Use the driver's company -> round -> order lock order to avoid deadlocks.
            $round = self::find($company, $input['round_uuid']);
            $order = $resolveOwnedOrder();
            abort_unless($order->company_uuid === $company, 404, 'Order not found.');
            if (CollectionHandovers::decide($round, $order, $input)) {
                self::audit($round, 'customer_handover_' . ($input['action'] === 'confirm' ? 'confirmed' : 'disputed'), [
                    'stop_uuid' => $input['stop_uuid'], 'order_uuid' => $order->uuid, 'customer_uuid' => $order->customer_uuid,
                    'reason' => trim($input['reason'] ?? ''), 'request_id' => $input['request_id'],
                ]);
            }

            return $order;
        });
    }

    /**
     * Return orders that the former automatic planner placed in unassigned, unoptimized drafts to "ready for pickup".
     * Customer readiness is kept, so the dispatcher can select and optimize them again.
     */
    public static function releaseAutomaticDrafts(string $company): array
    {
        return self::transaction($company, function () use ($company) {
            $released = [];
            $rounds = CollectionRound::where('company_uuid', $company)->where('status', 'draft')
                ->whereNull('driver_uuid')->whereNull('routing')->orderBy('uuid')->lockForUpdate()->get();
            foreach ($rounds as $round) {
                if (!$round->stops) {
                    continue;
                }
                foreach (self::orders($round) as $order) {
                    if (data_get($order->meta, 'collection_workflow.collection_round_uuid') !== $round->uuid
                        || data_get($order->meta, 'collection_workflow.state') !== 'pickup_scheduled') {
                        continue;
                    }
                    $order->recordCollectionRound($round->uuid, 'ready_for_pickup');
                    if (data_get($order->meta, 'collection_workflow.scheduling_mode') === 'dispatcher') {
                        $meta = $order->meta;
                        $meta['collection_workflow']['planned_collection_at'] = null;
                        $order->meta = $meta;
                    }
                    $order->save();
                    $released[] = $order->uuid;
                }
                self::audit($round, 'released_automatic_draft', ['stops' => $round->stops]);
                $round->stops = [];
                $round->depot = null;
                $round->custody = null;
                $round->save();
            }

            return $released;
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
        self::assertCalendar($round->company_uuid, $round->scheduled_at, data_get($order->meta, 'collection_workflow.pickup_window.end'), (bool) data_get($round->routing, 'plan_uuid'));
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

    private static function assertCalendar(string $company, \Carbon\CarbonInterface $planned, ?string $end = null, bool $routed = false): void
    {
        $calendar = CollectionSchedule::forCompany($company);
        // Route-planner rounds carry a dispatcher-chosen departure time, so only the operating day is enforced.
        $valid = $routed && $end === null ? CollectionSchedule::operatesOn($planned, $calendar) : CollectionSchedule::containsPickup($planned, $calendar, $end);
        abort_unless($valid, 409,
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
