<?php

namespace Fleetbase\FleetOps\Support;

use Carbon\CarbonImmutable;
use Fleetbase\FleetOps\Models\CollectionDeliveryLeg;
use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Orchestration\Engines\VroomOrchestrationEngine;
use Fleetbase\FleetOps\Orchestration\Support\OrchestrationPayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CollectionRoutePlans
{
    public static function propose(string $company, array $input): array
    {
        $context = self::context($company, $input);
        $solution = app(VroomOrchestrationEngine::class)->solveCollectionRoutes($company, $context['payload']);
        $proposal = self::solution($solution, $context, $input);
        $id = (string) Str::uuid();
        self::db()->table('collection_route_plans')->insert([
            'uuid' => $id, 'company_uuid' => $company, 'created_by' => session('user'),
            'input' => json_encode($input, JSON_THROW_ON_ERROR), 'fingerprint' => $context['fingerprint'],
            'proposal' => json_encode($proposal, JSON_THROW_ON_ERROR), 'expires_at' => now()->addMinutes(15),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['plan_uuid' => $id, 'expires_at' => now()->addMinutes(15)->toISOString()] + $proposal;
    }

    public static function commit(string $company, string $id, string $requestId): array
    {
        return self::db()->transaction(function () use ($company, $id, $requestId) {
            abort_unless(self::db()->table('companies')->where('uuid', $company)->lockForUpdate()->first(), 404);
            $plan = self::db()->table('collection_route_plans')->where('uuid', $id)->where('company_uuid', $company)->lockForUpdate()->first();
            abort_unless($plan, 404, 'Route proposal not found.');
            if ($plan->request_id) {
                abort_unless($plan->request_id === $requestId, 409, 'This proposal was already committed.');
                return json_decode($plan->receipt, true, 512, JSON_THROW_ON_ERROR);
            }
            abort_unless(now()->lessThan($plan->expires_at), 409, 'Route proposal expired. Orchestrate and review again.');
            $input = json_decode($plan->input, true, 512, JSON_THROW_ON_ERROR);
            $context = self::context($company, $input, true);
            abort_unless(hash_equals($plan->fingerprint, $context['fingerprint']), 409, 'Orders, vehicles, depot or availability changed. Orchestrate and review again.');
            $proposal = json_decode($plan->proposal, true, 512, JSON_THROW_ON_ERROR);
            $receipt = ['plan_uuid' => $id, 'rounds' => [], 'deliveries' => [], 'dispatch' => false];
            if ($input['kind'] === 'delivery') {
                $routed = collect($proposal['routes'])->pluck('order_uuids')->flatten()->all();
                foreach (array_intersect_key($context['moves'], array_flip($routed)) as $orderId => $roundId) {
                    self::moveDeliveryDate($company, $context['orders'][$orderId], $roundId, $input['date'], $context['calendar']['timezone']);
                }
            }
            foreach ($proposal['routes'] as $route) {
                $routing = $route + ['plan_uuid' => $id, 'actor_uuid' => session('user'), 'recorded_at' => now()->toISOString()];
                if ($input['kind'] === 'pickup') {
                    $round = new CollectionRound([
                        'uuid' => (string) Str::uuid(), 'company_uuid' => $company,
                        'scheduled_at' => $context['departure'], 'timezone' => $context['calendar']['timezone'],
                        'status' => 'draft', 'driver_uuid' => $route['driver_uuid'],
                        'depot' => $context['depot_legacy'], 'routing' => $routing,
                        'stops' => array_map(fn ($stop) => $context['tasks'][$stop['task_id']]['pickup_stop'], $route['stops']),
                        'history' => [['event' => 'optimized_draft', 'plan_uuid' => $id, 'actor_uuid' => session('user'), 'at' => now()->toISOString()]], 'revision' => 1,
                    ]);
                    $round->save();
                    foreach ($route['order_uuids'] as $orderId) {
                        $order = $context['orders'][$orderId];
                        $order->recordCollectionRound($round->uuid, 'pickup_scheduled');
                        $meta = $order->meta;
                        $meta['collection_workflow']['planned_collection_at'] = $context['departure']->toISOString();
                        $meta['collection_workflow']['timezone'] = $context['calendar']['timezone'];
                        $order->meta = $meta;
                        $order->save();
                    }
                    $receipt['rounds'][] = $round->uuid;
                } else {
                    foreach ($route['order_uuids'] as $sequence => $orderId) {
                        $leg = CollectionDelivery::change($company, $orderId, [
                            'action' => 'assign', 'revision' => $context['legs'][$orderId]?->revision ?? 0,
                            'driver_uuid' => $route['driver_uuid'], 'request_id' => $id . '-' . $sequence,
                        ], null, $route['order_uuids']);
                        $manifest = $leg->manifest;
                        $manifest['routing'] = $routing + ['order_sequence' => $sequence + 1];
                        $leg->manifest = $manifest;
                        $leg->history = [...$leg->history, ['action' => 'optimized_route', 'plan_uuid' => $id,
                            'at' => now()->toISOString(), 'actor_uuid' => session('user')]];
                        $leg->save();
                        $order = $context['orders'][$orderId];
                        $order->recordDeliveryRoute([
                            'plan_uuid' => $id, 'driver_uuid' => $route['driver_uuid'], 'sequence' => $sequence + 1,
                            'departure_at' => $route['departure_at'], 'arrival_at' => $route['stops'][$sequence]['arrival_at'],
                        ]);
                        $order->save();
                        $receipt['deliveries'][] = $leg->uuid;
                    }
                }
            }
            self::db()->table('collection_route_plans')->where('uuid', $id)->update([
                'request_id' => $requestId, 'receipt' => json_encode($receipt, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);

            return $receipt;
        }, 3);
    }

    private static function moveDeliveryDate(string $company, Order $order, string $roundId, string $date, string $timezone): void
    {
        $order->recordCollectionPlanDate('delivery', $date, $timezone);
        $order->save();
        $round = CollectionRound::where('company_uuid', $company)->where('uuid', $roundId)->lockForUpdate()->firstOrFail();
        $custody = $round->custody;
        $custody['receipts'][$order->uuid]['delivery_date'] = $date;
        $round->custody = $custody;
        $round->revision++;
        $round->save();
    }

    private static function context(string $company, array $input, bool $lock = false): array
    {
        $calendar = CollectionSchedule::forCompany($company);
        $date = CarbonImmutable::parse($input['date'], $calendar['timezone']);
        abort_unless(in_array($date->dayOfWeekIso, $calendar['operating_days'], true) && !in_array($input['date'], $calendar['closed_dates'], true),
            422, 'Choose an operating date.');
        $departure = $date->setTimeFromTimeString($input['kind'] === 'pickup' ? $calendar['departure_time'] : $input['start_time'])->utc();
        $end = $date->setTimeFromTimeString($input['end_time'])->utc();
        $localDeparture = $departure->setTimezone($calendar['timezone'])->format('Y-m-d H:i');
        abort_unless($departure->greaterThan(now()), 422, $input['kind'] === 'pickup'
            ? "The pickup departure ({$localDeparture}, from Settings > Collection schedule) has already passed. Choose a later date."
            : "The delivery departure ({$localDeparture}) has already passed. Choose a later date or start time.");
        abort_unless($end->greaterThan($departure), 422,
            "The latest depot return ({$input['end_time']}) must be after the departure ({$localDeparture}) on the same day.");
        if ($input['kind'] === 'pickup') {
            abort_unless(in_array($input['date'], CollectionSchedule::pickupDates(now(), $calendar), true), 422, 'Choose an available pickup operating date.');
        }
        $depot = PlanningPlaces::depot($company, $input['depot_uuid']);
        $depotRow = self::db()->table('places')->where('company_uuid', $company)->where('uuid', $depot['uuid'])
            ->select('*')->selectRaw('ST_AsText(location) as location_wkt')->first();
        $depotLegacy = \Illuminate\Support\Arr::only((array) $depotRow,
            ['uuid', 'name', 'street1', 'street2', 'city', 'province', 'postal_code', 'country', 'phone', 'location_wkt']);
        $orders = Order::where('company_uuid', $company)->whereIn('uuid', $input['order_uuids'])->orderBy('uuid');
        $orders = ($lock ? $orders->lockForUpdate() : $orders)->get()->keyBy('uuid');
        abort_unless($orders->count() === count($input['order_uuids']), 422, 'Some selected company orders are unavailable.');
        if ($lock) {
            $payloadIds = $orders->pluck('payload_uuid')->all();
            $payloads = self::db()->table('payloads')->whereIn('uuid', $payloadIds)->orderBy('uuid')->lockForUpdate()->get();
            $waypoints = self::db()->table('waypoints')->whereIn('payload_uuid', $payloadIds)->orderBy('uuid')->lockForUpdate()->get();
            self::db()->table('entities')->whereIn('payload_uuid', $payloadIds)->orderBy('uuid')->lockForUpdate()->get();
            $places = $payloads->flatMap(fn ($row) => [$row->pickup_uuid, $row->dropoff_uuid, $row->return_uuid])->merge($waypoints->pluck('place_uuid'))->filter()->unique();
            self::db()->table('places')->whereIn('uuid', $places)->orderBy('uuid')->lockForUpdate()->get();
        }
        $tasks = [];
        $warnings = [];
        $fingerprints = [];
        $legs = [];
        $moves = [];
        foreach ($orders as $order) {
            abort_unless(CollectionWorkflow::applies($order) && !$order->dispatched && !$order->started && !$order->driver_assigned_uuid,
                409, 'Select unstarted collection orders only.');
            $fingerprints['orders'][$order->uuid] = hash('sha256', serialize($order->getAttributes()) . CollectionReadiness::fingerprint($order));
            if ($input['kind'] === 'pickup') {
                CollectionReadiness::assertReadyForPlanning($order);
                abort_unless(data_get($order->meta, 'collection_workflow.scheduling_mode') === 'dispatcher', 409, 'Reconfirm historical readiness before optimized pickup planning.');
                $stops = CollectionReadiness::pickupStops($order);
                abort_unless(collect($stops)->every(fn ($stop) => collect($stop['order_parcels'])->every(fn ($parcel) =>
                    filter_var($parcel['quantity'], FILTER_VALIDATE_INT) !== false && (int) $parcel['quantity'] > 0)),
                    422, 'Parcel quantities must be positive integers.');
                $count = CollectionCapacity::parcels($stops);
                if (count($stops) !== 1) {
                    $warnings[] = ['order_uuid' => $order->uuid, 'reason' => 'Multiple pickup visits require manual whole-order planning. No parcels were split.'];
                    continue;
                }
                $stop = $stops[0];
                $location = ['lat' => $stop['location']['latitude'] ?? null, 'lng' => $stop['location']['longitude'] ?? null];
                $label = $stop['contact']['street1'] ?? $order->public_id;
            } else {
                $roundQuery = CollectionRound::where('company_uuid', $company)->where('uuid', data_get($order->meta, 'collection_workflow.collection_round_uuid'));
                $round = ($lock ? $roundQuery->lockForUpdate() : $roundQuery)->firstOrFail();
                abort_unless(data_get($round->depot, 'uuid') === $depot['uuid'], 409, 'Selected deliveries must belong to this depot.');
                CollectionDelivery::assertReceipt($order, $round);
                $deliveryDate = data_get($order->meta, 'collection_workflow.planned_delivery_date');
                if ($deliveryDate !== $input['date']) {
                    // The reviewed route date becomes the delivery date on commit, never earlier than depot eligibility.
                    $earliest = CollectionSchedule::deliveryDate(\Illuminate\Support\Carbon::parse(data_get($round->custody, 'receipts.' . $order->uuid . '.at')), $calendar);
                    abort_unless($input['date'] >= $earliest, 409, "Order {$order->public_id} can be delivered from {$earliest}. Choose that date or later.");
                    $moves[$order->uuid] = $round->uuid;
                }
                $legQuery = CollectionDeliveryLeg::where('company_uuid', $company)->where('order_uuid', $order->uuid);
                $leg = ($lock ? $legQuery->lockForUpdate() : $legQuery)->first();
                abort_unless(!$leg || $leg->status === 'recalled'
                    || ($leg->status === 'staging' && !data_get($leg->manifest, 'routing.plan_uuid')
                        && !collect($leg->manifest['units'])->contains(fn ($unit) => !empty($unit['staged_at']))),
                    409, 'Recall staged, held or accepted handovers before optimizing.');
                $legs[$order->uuid] = $leg;
                $fingerprints['rounds'][$round->uuid] = hash('sha256', serialize($round->getAttributes()));
                $fingerprints['legs'][$order->uuid] = $leg ? hash('sha256', serialize($leg->getAttributes())) : null;
                $payload = self::db()->table('payloads')->where('uuid', $order->payload_uuid)->first();
                $visits = self::db()->table('waypoints')->where('payload_uuid', $order->payload_uuid)->whereNull('deleted_at')->orderBy('order')->get();
                if ($visits->isNotEmpty() || !$payload->dropoff_uuid || $payload->return_uuid) {
                    $warnings[] = ['order_uuid' => $order->uuid, 'reason' => 'Multiple-recipient or return routes require manual planning. Original visit identity is preserved.'];
                    continue;
                }
                $place = PlanningPlaces::snapshot($company, $payload->dropoff_uuid);
                $location = $place['location'];
                $label = $place['street1'];
                $count = collect($round->custody['units'])->where('order_uuid', $order->uuid)->count();
            }
            if (!self::coordinates($location)) {
                $warnings[] = ['order_uuid' => $order->uuid, 'reason' => 'Verify this order map location before optimizing.'];
                continue;
            }
            abort_unless(is_int($count) && $count > 0, 422, 'Parcel quantities must be positive integers.');
            $window = [$departure->timestamp, $end->timestamp - 300];
            if ($order->time_window_start) $window[0] = max($window[0], CarbonImmutable::parse($order->time_window_start)->timestamp);
            if ($order->time_window_end) $window[1] = min($window[1], CarbonImmutable::parse($order->time_window_end)->timestamp);
            if ($window[1] < $window[0]) {
                $warnings[] = ['order_uuid' => $order->uuid, 'reason' => 'The order time window does not fit the selected working window.'];
                continue;
            }
            $taskId = count($tasks) + 1;
            $tasks[$taskId] = ['order_uuid' => $order->uuid, 'order_id' => $order->public_id, 'location' => $location,
                'label' => $label, 'quantity' => $count, 'window' => $window,
                'skills' => OrchestrationPayloadBuilder::resolveSkills($order->required_skills ?? []),
                'pickup_stop' => $input['kind'] === 'pickup' ? $stop : null];
        }
        abort_unless($tasks, 422, 'No selected orders have eligible, supported routing stops. Review addresses and multi-stop orders.');
        $vehicles = [];
        $resources = [];
        $usedVehicles = [];
        foreach ($input['drivers'] as $index => $selected) {
            $driverQuery = Driver::where('company_uuid', $company)->where('uuid', $selected['driver_uuid']);
            $driver = ($lock ? $driverQuery->lockForUpdate() : $driverQuery)->firstOrFail();
            $vehicleQuery = Vehicle::where('company_uuid', $company)->where('uuid', $driver->vehicle_uuid);
            $vehicle = ($lock ? $vehicleQuery->lockForUpdate() : $vehicleQuery)->first();
            abort_unless($vehicle && !in_array($vehicle->uuid, $usedVehicles, true), 422, 'Each selected driver needs a different active company vehicle.');
            $usedVehicles[] = $vehicle->uuid;
            $capacity = CollectionCapacity::driverLimit($driver, $calendar);
            abort_unless($capacity, 422, 'Configure parcel capacity for every selected vehicle.');
            // Conservatively reserve an entire operating date, rather than inventing travel-time availability.
            $vehicleDrivers = Driver::where('company_uuid', $company)->where('vehicle_uuid', $vehicle->uuid)->pluck('uuid')->all();
            $busyRounds = CollectionRound::where('company_uuid', $company)->whereIn('driver_uuid', $vehicleDrivers)
                ->whereBetween('scheduled_at', [$date->startOfDay()->utc(), $date->endOfDay()->utc()])
                ->whereIn('status', ['draft', 'released', 'in_progress', 'awaiting_depot'])->whereJsonLength('stops', '>', 0)->exists();
            $busyLegs = CollectionDeliveryLeg::where('company_uuid', $company)->whereIn('driver_uuid', $vehicleDrivers)
                ->whereIn('status', ['staging', 'accepted', 'released'])->where('manifest->delivery_date', $input['date'])
                ->whereNotIn('order_uuid', $input['kind'] === 'delivery' ? $input['order_uuids'] : [])->exists();
            $busyOrders = Order::where('company_uuid', $company)->whereIn('driver_assigned_uuid', $vehicleDrivers)
                ->whereNotIn('status', ['completed', 'done', 'delivered', 'cancelled', 'canceled', 'order_completed', 'order_delivered', 'order_canceled'])
                ->where(fn ($query) => $query->where('started', true)->orWhere('dispatched', true)->orWhereIn('status', ['dispatched', 'started', 'out_for_delivery']))->exists();
            abort_if($busyRounds || $busyLegs || $busyOrders, 409, 'A selected driver already has pickup, delivery or active order work. Review availability first.');
            self::assertNoOptimizedReservation($company, $driver->uuid, $vehicle->uuid, $input['date'], null,
                $input['kind'] === 'delivery' ? $input['order_uuids'] : []);
            if (self::db()->getSchemaBuilder()->hasTable('manifests')) {
                $reserved = self::db()->table('manifests')->where('company_uuid', $company)->whereNull('deleted_at')
                    ->where(fn ($query) => $query->where('vehicle_uuid', $vehicle->uuid)->orWhereIn('driver_uuid', $vehicleDrivers))
                    ->where(fn ($query) => $query->whereIn('status', ['active', 'in_progress'])
                        ->orWhere(fn ($draft) => $draft->where('status', 'draft')->whereDate('scheduled_date', $input['date'])))->exists();
                abort_if($reserved, 409, 'A selected vehicle or driver is already reserved by a direct-order manifest.');
            }
            if (self::db()->getSchemaBuilder()->hasTable('schedule_items')) {
                $shiftsQuery = $driver->scheduleItems()->where('company_uuid', $company)->whereIn('status', Driver::LIVE_SHIFT_STATUSES)
                    ->where('start_at', '<', $end)->where('end_at', '>', $departure);
                $shifts = ($lock ? $shiftsQuery->lockForUpdate() : $shiftsQuery)->get();
                abort_unless($shifts->contains(fn ($shift) => CarbonImmutable::parse($shift->start_at)->lessThanOrEqualTo($departure)
                    && CarbonImmutable::parse($shift->end_at)->greaterThanOrEqualTo($end)), 409, 'The selected route window must fit a scheduled driver shift.');
                abort_if($shifts->contains(fn ($shift) => $shift->break_start_at && $shift->break_end_at
                    && CarbonImmutable::parse($shift->break_start_at)->lessThan($end)
                    && CarbonImmutable::parse($shift->break_end_at)->greaterThan($departure)),
                    409, 'Choose a route window outside the scheduled break.');
                $fingerprints['shifts'][$driver->uuid] = hash('sha256', serialize($shifts->map->getAttributes()->all()));
            }
            if (self::db()->getSchemaBuilder()->hasTable('schedule_availability')) {
                $unavailable = $driver->availabilities()->where('is_available', false)->where('start_at', '<', $end)
                    ->where(fn ($query) => $query->where('end_at', '>', $departure)->orWhereNotNull('rrule'))->exists();
                abort_if($unavailable, 409, 'Driver time off or recurring unavailability overlaps this planning period. Review the schedule first.');
            }
            foreach ([$driver, $vehicle] as $resource) {
                abort_if(($resource->time_window_start && CarbonImmutable::parse($resource->time_window_start)->greaterThan($departure))
                    || ($resource->time_window_end && CarbonImmutable::parse($resource->time_window_end)->lessThan($end)),
                    409, 'The selected window exceeds driver or vehicle availability.');
            }
            $skills = array_values(array_unique(array_merge(OrchestrationPayloadBuilder::resolveSkills($driver->skills ?? []),
                OrchestrationPayloadBuilder::resolveSkills($vehicle->skills ?? []))));
            $origin = $input['kind'] === 'delivery' ? $depot : PlanningPlaces::snapshot($company, $selected['start_place_uuid']);
            $resourceId = $index + 1;
            $resources[$resourceId] = ['driver_uuid' => $driver->uuid, 'driver_name' => $driver->name,
                'vehicle_uuid' => $vehicle->uuid, 'origin' => $origin, 'destination' => $depot, 'capacity' => $capacity, 'skills' => $skills];
            $fingerprints['drivers'][$driver->uuid] = hash('sha256', serialize($driver->getAttributes()) . serialize($vehicle->getAttributes()));
            $vehicles[] = ['id' => $resourceId, 'start' => [$origin['location']['lng'], $origin['location']['lat']],
                'end' => [$depot['location']['lng'], $depot['location']['lat']], 'capacity' => [$capacity],
                'time_window' => [$departure->timestamp, $end->timestamp], 'skills' => $skills, 'profile' => env('VROOM_PROFILE', 'driving'),
                'max_tasks' => $calendar['max_pickup_stops'] ?? 100];
        }
        $jobs = [];
        foreach ($tasks as $id => $task) {
            $jobs[] = ['id' => $id, 'location' => [$task['location']['lng'], $task['location']['lat']],
                ($input['kind'] === 'pickup' ? 'pickup' : 'delivery') => [$task['quantity']],
                'service' => 300, 'time_windows' => [$task['window']], 'skills' => $task['skills']];
        }
        $fingerprint = hash('sha256', json_encode([$fingerprints, $calendar, $depot, $resources, $tasks], JSON_THROW_ON_ERROR));

        return ['payload' => ['jobs' => $jobs, 'vehicles' => $vehicles, 'options' => ['g' => true]],
            'tasks' => $tasks, 'resources' => $resources, 'warnings' => $warnings, 'orders' => $orders, 'legs' => $legs, 'moves' => $moves,
            'fingerprint' => $fingerprint, 'calendar' => $calendar, 'departure' => $departure, 'end' => $end,
            'depot_legacy' => $depotLegacy];
    }

    private static function solution(array $solution, array $context, array $input): array
    {
        $seen = [];
        $seenVehicles = [];
        $routes = [];
        foreach ($solution['routes'] as $route) {
            abort_unless(is_array($route) && is_array($route['steps'] ?? null) && empty($route['violations']), 502, 'Routing engine returned a malformed or constraint-violating route.');
            $resourceId = $route['vehicle'] ?? null;
            abort_unless(is_int($resourceId) && isset($context['resources'][$resourceId]) && !isset($seenVehicles[$resourceId]), 502, 'Routing engine returned an unknown or duplicate vehicle.');
            $seenVehicles[$resourceId] = true;
            $resource = $context['resources'][$resourceId];
            $stops = [];
            $quantity = 0;
            $previous = $context['departure']->timestamp;
            foreach ($route['steps'] ?? [] as $step) {
                if (in_array($step['type'] ?? null, ['start', 'end'], true)) continue;
                $id = $step['id'] ?? null;
                $arrival = $step['arrival'] ?? null;
                $waiting = $step['waiting_time'] ?? 0;
                abort_unless(($step['type'] ?? null) === 'job' && is_int($id) && isset($context['tasks'][$id])
                    && !isset($seen[$id]) && is_int($arrival) && $arrival >= $previous
                    && is_int($waiting) && $waiting >= 0
                    && $arrival + $waiting + 300 <= $context['end']->timestamp, 502, 'Routing engine returned invalid, duplicate or late stops.');
                $seen[$id] = true;
                $arrival += $waiting;
                $previous = $arrival + 300;
                $task = $context['tasks'][$id];
                abort_unless($arrival >= $task['window'][0] && $arrival <= $task['window'][1]
                    && !array_diff($task['skills'], $resource['skills']), 502, 'The routing solution violates an order time window or required driver skills.');
                $quantity += $task['quantity'];
                $stops[] = \Illuminate\Support\Arr::except($task, ['pickup_stop', 'quantity']) + [
                    'task_id' => $id, 'sequence' => count($stops) + 1, 'arrival_at' => CarbonImmutable::createFromTimestampUTC($arrival)->toISOString(),
                ];
            }
            abort_unless($stops && $quantity <= $resource['capacity']
                && count($stops) <= ($context['calendar']['max_pickup_stops'] ?? 100), 502, 'Routing engine exceeded the parcel or stop capacity.');
            foreach (['distance', 'duration'] as $metric) {
                abort_unless(is_int($route[$metric] ?? null) && $route[$metric] >= 0, 502, 'Routing engine omitted valid route metrics.');
            }
            $last = collect($route['steps'])->last();
            abort_unless(($last['type'] ?? null) === 'end' && is_int($last['arrival'] ?? null)
                && $last['arrival'] >= $previous && $last['arrival'] <= $context['end']->timestamp, 502, 'Route does not return to the depot within its working window.');
            $routes[] = $resource + ['kind' => $input['kind'], 'date' => $input['date'], 'timezone' => $context['calendar']['timezone'], 'stops' => $stops,
                'order_uuids' => array_column($stops, 'order_uuid'), 'distance_meters' => $route['distance'],
                'duration_seconds' => $last['arrival'] - $context['departure']->timestamp, 'travel_seconds' => $route['duration'],
                'geometry' => is_string($route['geometry'] ?? null) ? $route['geometry'] : null,
                'departure_at' => $context['departure']->toISOString(), 'ends_at' => CarbonImmutable::createFromTimestampUTC($last['arrival'])->toISOString()];
        }
        $unassigned = $context['warnings'];
        foreach ($solution['unassigned'] as $item) {
            $id = $item['id'] ?? null;
            abort_unless(is_int($id) && isset($context['tasks'][$id]) && !isset($seen[$id]), 502, 'Routing engine returned an unknown or duplicate unassigned order.');
            $seen[$id] = true;
            $unassigned[] = ['order_uuid' => $context['tasks'][$id]['order_uuid'], 'reason' => 'No feasible route within the selected capacity and time window.'];
        }
        abort_unless(count($seen) === count($context['tasks']), 502, 'Routing engine omitted selected orders. No plan was saved.');
        if ($unassigned) Log::notice('Collection routing left orders unassigned.', ['company_uuid' => session('company'), 'count' => count($unassigned)]);

        return ['kind' => $input['kind'], 'date' => $input['date'], 'routes' => $routes, 'unassigned' => $unassigned];
    }

    public static function assertNoOptimizedReservation(string $company, ?string $driver, ?string $vehicle, string $date, ?string $excludeRound = null, array $excludeOrders = []): void
    {
        $schema = self::db()->getSchemaBuilder();
        if ($schema->hasTable('collection_rounds') && $schema->hasColumn('collection_rounds', 'routing')) {
            $calendar = CollectionSchedule::forCompany($company);
            $scheduledDate = CarbonImmutable::parse($date, $calendar['timezone'])->setTimeFromTimeString($calendar['departure_time'])->utc()->toDateString();
            $rounds = CollectionRound::where('company_uuid', $company)->whereNotNull('routing->plan_uuid')
                ->where(fn ($query) => $query->where('driver_uuid', $driver)->orWhere('routing->vehicle_uuid', $vehicle))
                ->whereJsonLength('stops', '>', 0)->where('uuid', '!=', $excludeRound ?? '')
                ->where(fn ($query) => $query->whereIn('status', ['released', 'in_progress', 'awaiting_depot'])
                    ->orWhere(fn ($draft) => $draft->where('status', 'draft')->whereDate('scheduled_at', $scheduledDate)));
            abort_if($rounds->exists(), 409, 'Vehicle or driver is reserved by an optimized pickup route.');
        }
        if ($schema->hasTable('collection_delivery_legs')) {
            $legs = CollectionDeliveryLeg::where('company_uuid', $company)->whereNotNull('manifest->routing->plan_uuid')
                ->whereNotIn('order_uuid', $excludeOrders)->whereIn('status', ['staging', 'accepted', 'released'])
                ->where('manifest->delivery_date', $date)
                ->where(fn ($query) => $query->where('driver_uuid', $driver)->orWhere('manifest->routing->vehicle_uuid', $vehicle));
            abort_if($legs->exists(), 409, 'Vehicle or driver is reserved by an optimized depot delivery route.');
        }
    }

    public static function assertDeliveryRelease(string $company, CollectionDeliveryLeg $leg): void
    {
        $route = data_get($leg->manifest, 'routing');
        if (!$route) return;
        abort_unless($route['driver_uuid'] === $leg->driver_uuid && now()->lessThan($route['ends_at']),
            409, 'The reviewed delivery route is stale. Recall and orchestrate again.');
        $driver = Driver::where('company_uuid', $company)->where('uuid', $leg->driver_uuid)->lockForUpdate()->firstOrFail();
        abort_unless($driver->vehicle_uuid === $route['vehicle_uuid'], 409, 'The delivery vehicle changed. Recall and orchestrate again.');
        abort_unless(Vehicle::where('company_uuid', $company)->where('uuid', $route['vehicle_uuid'])->lockForUpdate()->first(),
            409, 'The delivery vehicle is no longer available. Recall and review this route.');
        PlanningPlaces::assertCurrent($company, $route['origin']);
        PlanningPlaces::assertCurrent($company, $route['destination']);
        $peers = CollectionDeliveryLeg::where('company_uuid', $company)->whereIn('order_uuid', $route['order_uuids'])
            ->orderBy('uuid')->lockForUpdate()->get();
        abort_unless($peers->count() === count($route['order_uuids']) && $peers->every(fn ($peer) =>
            in_array($peer->status, ['accepted', 'released'], true) && $peer->driver_uuid === $driver->uuid
            && data_get($peer->manifest, 'routing.plan_uuid') === $route['plan_uuid']
            && data_get($peer->manifest, 'acceptance.status') === 'accepted'
            && data_get($peer->manifest, 'acceptance.driver_uuid') === $driver->uuid
            && collect($peer->manifest['units'])->every(fn ($unit) => !empty($unit['staged_at']))),
            409, 'All orders on this route must be loaded and accepted by the assigned driver before any departure.');
        $capacity = CollectionCapacity::driverLimit($driver, CollectionSchedule::forCompany($company));
        abort_unless($capacity && $peers->sum(fn ($peer) => count($peer->manifest['units'])) <= $capacity,
            409, 'Vehicle parcel capacity changed. Recall and review this route.');
        foreach ($peers as $peer) {
            if ($peer->status === 'released') continue;
            $order = Order::where('company_uuid', $company)->where('uuid', $peer->order_uuid)->lockForUpdate()->firstOrFail();
            $round = CollectionRound::where('company_uuid', $company)->where('uuid', $peer->round_uuid)->lockForUpdate()->firstOrFail();
            CollectionDelivery::assertManifestCurrent($order, $round, $peer);
        }
    }

    private static function coordinates(array $location): bool
    {
        return is_numeric($location['lat']) && is_numeric($location['lng'])
            && is_finite((float) $location['lat']) && is_finite((float) $location['lng'])
            && abs($location['lat']) <= 90 && abs($location['lng']) <= 180;
    }

    private static function db()
    {
        return DB::connection(config('fleetbase.connection.db', 'mysql'));
    }
}
