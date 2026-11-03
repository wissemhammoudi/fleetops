<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Resources\v1\Orchestrator\Order as OrchestratorOrderResource;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Orchestration\Engines\DriverAssignmentEngine;
use Fleetbase\FleetOps\Orchestration\Engines\RouteSequencingEngine;
use Fleetbase\FleetOps\Orchestration\OrchestrationEngineRegistry;
use Fleetbase\FleetOps\Support\Authorization;
use Fleetbase\FleetOps\Support\CollectionWorkflow;
use Fleetbase\FleetOps\Support\OrchestrationPlan;
use Fleetbase\FleetOps\Support\OrchestrationResourceGuard;
use Fleetbase\FleetOps\Traits\AuthorizesMethods;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OrchestrationController.
 *
 * HTTP interface for the Orchestrator Workbench.
 *
 * Responsibilities:
 *   - Serving orders for the workbench (with custom field values)
 *   - Running orchestration phases (assign_vehicles, assign_drivers, optimize, optimize_routes, allocate)
 *   - Committing a proposed plan to Manifests and ManifestStops
 *   - Listing available orchestration engines
 *   - Providing order-config custom field definitions for card configuration
 *   - Importing orders from parsed CSV/Excel data
 */
class OrchestrationController extends Controller
{
    use AuthorizesMethods;

    public function __construct(protected OrchestrationEngineRegistry $registry)
    {
        $this->authorizeMethods([
            'orders'                => 'list order',
            'run'                   => 'optimize order',
            'preview'               => 'optimize order',
            'commit'                => ['assign-driver-for order', 'assign-vehicle-for order', 'update-route-for order'],
            'engines'               => 'list order',
            'orderConfigFields'     => 'list order',
            'importOrders'          => 'import order',
        ]);
    }

    /**
     * Return orders for the Orchestrator Workbench.
     *
     * This endpoint uses the dedicated OrchestratorOrderResource which includes
     * custom_field_values — unlike the lightweight Index/Order resource used by
     * the tabular orders view, which intentionally omits them for performance.
     *
     * GET /int/v1/fleet-ops/orchestrator/orders
     */
    public function orders(Request $request): JsonResponse
    {
        $companyUuid = $this->companyUuid();

        $query = $this->orchestratorOrdersQuery($companyUuid);

        $query->whereHas('payload', function ($payloadQuery) {
            $payloadQuery->where(function ($q) {
                $q->whereHas('waypoints', function ($w) {
                    $w->whereNotNull('waypoints.uuid');
                });
                $q->orWhereHas('pickup', function ($p) {
                    $p->whereNotNull('places.uuid');
                });
                $q->orWhereHas('dropoff', function ($d) {
                    $d->whereNotNull('places.uuid');
                });
            });
        });

        $query->whereHas('trackingNumber', function ($q) {
            $q->select('uuid');
        });

        $query->whereHas('trackingStatuses', function ($q) {
            $q->select('uuid');
        });

        if ($request->boolean('unassigned')) {
            $query->whereNull('vehicle_assigned_uuid');
        }

        $query->with([
            'payload.entities',
            'payload.waypoints',
            'payload.pickup',
            'payload.dropoff',
            'payload.return',
            'trackingNumber',
            'trackingStatuses',
            'driverAssigned' => function ($query) {
                $query->without(['jobs', 'currentJob']);
            },
            'vehicleAssigned' => function ($query) {
                $query->without(['fleets', 'vendor']);
            },
            'customer',
            'facilitator',
            'customFieldValues.customField',
        ]);

        $limit  = min((int) $request->input('limit', 500), 1000);
        $orders = $query->limit($limit)->get();

        return response()->json([
            'orders' => OrchestratorOrderResource::collection($orders)->resolve(),
        ]);
    }

    /**
     * Run an orchestration phase for the given mode.
     *
     * POST /int/v1/fleet-ops/orchestrator/run
     */
    public function run(Request $request): JsonResponse
    {
        $this->authorizeOrchestrationAction('optimize order');
        $request->validate([
            'mode' => 'sometimes|in:assign_vehicles,assign_drivers,optimize,optimize_routes,allocate',
            'order_ids' => 'sometimes|array|max:500', 'order_ids.*' => 'required|string|distinct|max:191',
            'vehicle_ids' => 'sometimes|array|max:500', 'vehicle_ids.*' => 'required|string|distinct|max:191',
            'driver_ids' => 'sometimes|array|max:500', 'driver_ids.*' => 'required|string|distinct|max:191',
            'prior_assignments' => 'sometimes|array|max:500',
            'prior_assignments.*.order_id' => 'required|string|distinct|max:191',
            'prior_assignments.*.vehicle_id' => 'nullable|string|max:191',
            'prior_assignments.*.driver_id' => 'nullable|string|max:191',
            'plan_version' => 'nullable|uuid', 'options' => 'sometimes|array',
        ]);
        $companyUuid       = $this->companyUuid();
        $previousSnapshot = null;
        if ($request->filled('prior_assignments')) {
            abort_unless($request->filled('plan_version'), 422, 'Prior assignments require their plan version.');
            $previousSnapshot = OrchestrationPlan::assertFresh(OrchestrationPlan::load($companyUuid, $request->input('plan_version')), $companyUuid);
            foreach ($request->input('prior_assignments') as $assignment) {
                $this->assertSnapshotAssignment($assignment, $previousSnapshot);
            }
        }
        $mode              = $request->input('mode', 'assign_vehicles');
        $orderIds          = $request->input('order_ids', []);
        $vehicleIds        = $request->input('vehicle_ids', []);
        $driverIds         = $request->input('driver_ids', []);
        $options           = $request->input('options', []);
        // prior_assignments: assignments from previous phases that have not yet
        // been committed to the database. Keyed by order_id (public_id).
        $priorAssignments  = collect($request->input('prior_assignments', []))
            ->keyBy('order_id');
        $candidateOrderIds = !empty($orderIds) ? $orderIds : DB::table('orders')->where('company_uuid', $companyUuid)
            ->where('status', 'created')->whereNull('deleted_at')->orderBy('uuid')->limit(OrchestrationPlan::LIMIT + 1)->pluck('public_id')->all();
        $snapshot = OrchestrationPlan::capture($companyUuid, [
            'orders' => $candidateOrderIds,
            ...(!empty($vehicleIds) ? ['vehicles' => $vehicleIds] : []),
            ...(!empty($driverIds) ? ['drivers' => $driverIds] : []),
        ]);
        if ($previousSnapshot) {
            abort_unless($snapshot === $previousSnapshot, 409, 'Plan resources changed between phases. Run a new plan.');
        }

        // ── Resolve orders ────────────────────────────────────────────────────
        $ordersQuery = $this->orchestrationRunOrdersQuery($companyUuid)->whereIn('public_id', $candidateOrderIds);

        if ($mode === 'assign_vehicles' || $mode === 'allocate') {
            // Exclude orders that already have a vehicle assigned in the DB
            // OR in a prior uncommitted phase.
            $priorVehicleAssignedOrderIds = $priorAssignments
                ->filter(fn ($a) => !empty($a['vehicle_id']))
                ->keys()
                ->toArray();
            $ordersQuery->whereNull('vehicle_assigned_uuid');
            if (!empty($priorVehicleAssignedOrderIds)) {
                $ordersQuery->whereNotIn('public_id', $priorVehicleAssignedOrderIds);
            }
        } elseif ($mode === 'optimize') {
            $ordersQuery->whereNotNull('vehicle_assigned_uuid');
        } elseif ($mode === 'optimize_routes') {
            // optimize_routes: re-sequence stops for selected orders.
            // No vehicle-assignment filter — the user picks the orders explicitly.
        } elseif ($mode === 'assign_drivers') {
            // For assign_drivers we need orders that have a vehicle assigned
            // (either committed to DB or from a prior phase) but no driver yet.
            $priorVehicleAssignedOrderIds = $priorAssignments
                ->filter(fn ($a) => !empty($a['vehicle_id']))
                ->keys()
                ->toArray();

            if (!empty($priorVehicleAssignedOrderIds)) {
                // Use the prior phase's vehicle assignments — fetch those orders
                // regardless of their DB vehicle_assigned_uuid.
                $ordersQuery->whereIn('public_id', $priorVehicleAssignedOrderIds)
                    ->whereNull('driver_assigned_uuid');
            } else {
                // Standalone assign_drivers (no prior vehicle phase):
                // Use all selected orders regardless of vehicle assignment.
                // The engine will assign both a vehicle and a driver together.
                $ordersQuery->whereNull('driver_assigned_uuid');
            }
        }

        if (!empty($orderIds)) {
            $ordersQuery->whereIn('public_id', $orderIds);
        }
        $orders = $ordersQuery->limit(OrchestrationPlan::LIMIT + 1)->get();
        abort_if($orders->count() > OrchestrationPlan::LIMIT, 422, 'Select at most 500 orders.');
        foreach ($orders as $order) {
            abort_if(CollectionWorkflow::applies($order), 422, 'Use the dedicated collection/depot flow for collection-enrolled orders.');
            abort_unless($order->status === 'created' && !$order->started && !$order->dispatched && !$order->started_at && !$order->dispatched_at, 409, 'Only unstarted, undispatched orders can be planned.');
        }

        // Augment orders with prior-phase vehicle AND driver assignments so the
        // engines can group by vehicle_id and preserve driver_id even before the
        // plan is committed to the database.
        if ($priorAssignments->isNotEmpty()) {
            // Pre-load all drivers referenced in prior assignments so we can
            // attach them to vehicles without N+1 queries.
            $priorDriverIds = $priorAssignments
                ->pluck('driver_id')
                ->filter()
                ->unique()
                ->values()
                ->toArray();
            $priorDriverMap = collect();
            if (!empty($priorDriverIds)) {
                $priorDriverMap = $this->driversByPublicId($priorDriverIds);
            }

            foreach ($orders as $order) {
                $prior = $priorAssignments->get($order->public_id);
                if (!$prior) {
                    continue;
                }

                // Temporarily set the vehicle_assigned_uuid on the model
                // so engines that group by this field work correctly.
                if (!empty($prior['vehicle_id']) && !$order->vehicle_assigned_uuid) {
                    // Resolve the Vehicle model and attach it
                    $vehicle = $this->vehicleByPublicIdWithDriver($prior['vehicle_id']);
                    if ($vehicle) {
                        $order->vehicle_assigned_uuid = $vehicle->uuid;

                        // If the prior phase assigned a driver that is not yet
                        // linked to this vehicle in the DB, attach that driver
                        // to the vehicle relation so RouteSequencingEngine (and
                        // any other engine) can read $vehicle->driver correctly.
                        if (!empty($prior['driver_id'])) {
                            $priorDriver = $priorDriverMap->get($prior['driver_id']);
                            if ($priorDriver && (!$vehicle->driver || $vehicle->driver->public_id !== $prior['driver_id'])) {
                                $vehicle->setRelation('driver', $priorDriver);
                            }
                        }

                        $order->setRelation('vehicle', $vehicle);
                    }
                }

                // Also temporarily set driver_assigned_uuid so engines that
                // check this field (e.g. for deduplication) see the prior assignment.
                if (!empty($prior['driver_id']) && !$order->driver_assigned_uuid) {
                    $priorDriver = $priorDriverMap->get($prior['driver_id']);
                    if ($priorDriver) {
                        $order->driver_assigned_uuid = $priorDriver->uuid;
                        $order->setRelation('driverAssigned', $priorDriver);
                    }
                }
            }
        }

        // ── Resolve vehicles ──────────────────────────────────────────────────
        $vehiclesQuery = $this->orchestrationRunVehiclesQuery($companyUuid)->whereIn('public_id', array_keys($snapshot['vehicles']));

        if (!empty($vehicleIds)) {
            $vehiclesQuery->whereIn('public_id', $vehicleIds);
        } elseif (!empty($driverIds)) {
            $vehiclesQuery->whereHas('driver', fn ($q) => $q->whereIn('public_id', $driverIds));
        }

        // assign_vehicles, assign_drivers and optimize_routes do not require an
        // online/assigned driver — use all matching vehicles as-is.
        if (in_array($mode, ['assign_vehicles', 'assign_drivers', 'optimize_routes'])) {
            $vehicles = $vehiclesQuery->limit(OrchestrationPlan::LIMIT + 1)->get();
        } else {
            // Legacy allocate / optimize modes require a driver to be linked.
            $vehicles = $vehiclesQuery->limit(OrchestrationPlan::LIMIT + 1)->get()->filter(fn ($v) => $v->driver !== null);
        }
        abort_if($vehicles->count() > OrchestrationPlan::LIMIT, 422, 'Select at most 500 vehicles.');

        if ($orders->isEmpty()) {
            return response()->json([
                'message'     => 'No orders found for the given criteria.',
                'assignments' => [],
                'unassigned'  => [],
            ], 200);
        }

        if ($vehicles->isEmpty() && $mode !== 'assign_drivers') {
            return response()->json([
                'message'     => 'No available vehicles found.',
                'assignments' => [],
                'unassigned'  => $orders->pluck('public_id'),
            ], 200);
        }

        // ── Run engine ────────────────────────────────────────────────────────
        $engineId = $mode === 'assign_drivers'
            ? 'driver_assignment'
            : ($request->input('options.engine') ?? $this->orchestratorEngineSetting());

        try {
            if ($mode === 'assign_drivers') {
                $engine = $this->driverAssignmentEngine();
                $result = $engine->assign($orders, $vehicles, $options);
            } elseif ($mode === 'optimize_routes') {
                // optimize_routes sequences stops within each vehicle's already-assigned
                // order group — it does NOT re-assign orders to different vehicles.
                //
                // IMPORTANT: Do NOT call $orders->load(['vehicle', 'vehicle.driver']) here.
                // The augmentation loop above already called setRelation('vehicle', $vehicle)
                // with the prior-phase driver attached via setRelation('driver', $priorDriver).
                // Calling ->load() would reload from the DB and OVERWRITE those in-memory
                // relations, losing the uncommitted driver assignment from a prior phase.
                //
                // Instead, only load the vehicle relation for orders that have a
                // vehicle_assigned_uuid in the DB but no in-memory relation set yet
                // (i.e. standalone optimize_routes without a prior assign_drivers phase).
                foreach ($orders as $order) {
                    if (!$order->relationLoaded('vehicle') && $order->vehicle_assigned_uuid) {
                        $vehicle = $this->vehicleByUuidWithDriver($order->vehicle_assigned_uuid);
                        if ($vehicle) {
                            $order->setRelation('vehicle', $vehicle);
                        }
                    }
                }
                $engine = $this->routeSequencingEngine();
                $result = $engine->sequence($orders, $options);
            } else {
                $engine = $this->registry->resolve($engineId);
                $result = $engine->allocate($orders, $vehicles, $options);
            }
        } catch (\RuntimeException $e) {
            // Engine is unavailable (e.g. VROOM not reachable).
            // Return a structured JSON 503 so the frontend can display a
            // user-friendly message instead of an unhandled exception page.
            return response()->json([
                'error'  => $e->getMessage(),
                'hint'   => 'If you are using the VROOM engine, ensure the VROOM service is running and VROOM_HOST is configured correctly. Alternatively, switch to the built-in "greedy" engine in Orchestrator Settings.',
                'engine' => $engineId,
            ], 503);
        }

        foreach ($result['assignments'] ?? [] as $assignment) {
            $this->assertSnapshotAssignment($assignment, $snapshot);
        }
        $result['plan_version'] = OrchestrationPlan::propose($companyUuid, $snapshot);
        $result['requires_review'] = true;

        return response()->json($result);
    }

    /**
     * Preview an orchestration run without committing any assignments.
     *
     * GET /int/v1/fleet-ops/orchestrator/preview
     */
    public function preview(Request $request): JsonResponse
    {
        return $this->run($request);
    }

    protected function companyUuid(): ?string
    {
        abort_unless(session('company'), 403, 'A company session is required.');
        abort_if(session('is_customer') || session('is_driver'), 403, 'The orchestrator requires an operations staff session.');

        return session('company');
    }

    protected function orchestratorOrdersQuery(?string $companyUuid): mixed
    {
        return Order::where('company_uuid', $companyUuid)->whereIn('status', ['created', 'dispatched', 'started']);
    }

    protected function orchestrationRunOrdersQuery(?string $companyUuid): mixed
    {
        return Order::where('company_uuid', $companyUuid)
            ->whereIn('status', ['created', 'dispatched'])
            ->with(['payload.dropoff', 'payload.pickup', 'payload.waypoints', 'payload.waypointMarkers', 'payload.entities']);
    }

    protected function orchestrationRunVehiclesQuery(?string $companyUuid): mixed
    {
        return Vehicle::where('company_uuid', $companyUuid)
            ->with(['driver' => fn ($q) => $q->where('company_uuid', $companyUuid)->with(['scheduleItems'])]);
    }

    protected function driversByPublicId(array $publicIds)
    {
        return Driver::where('company_uuid', $this->companyUuid())->whereIn('public_id', $publicIds)
            ->get()
            ->keyBy('public_id');
    }

    protected function vehicleByPublicIdWithDriver(string $publicId): ?Vehicle
    {
        return Vehicle::where('company_uuid', $this->companyUuid())->where('public_id', $publicId)
            ->with(['driver' => fn ($q) => $q->where('company_uuid', $this->companyUuid())->with(['scheduleItems'])])
            ->first();
    }

    protected function vehicleByUuidWithDriver(string $uuid): ?Vehicle
    {
        return Vehicle::where('company_uuid', $this->companyUuid())->where('uuid', $uuid)
            ->with(['driver' => fn ($q) => $q->where('company_uuid', $this->companyUuid())])
            ->first();
    }

    protected function orchestratorEngineSetting(): string
    {
        return Setting::lookup('fleetops.orchestrator_engine', 'greedy');
    }

    protected function driverAssignmentEngine(): DriverAssignmentEngine
    {
        return new DriverAssignmentEngine();
    }

    protected function routeSequencingEngine(): RouteSequencingEngine
    {
        return new RouteSequencingEngine();
    }

    /**
     * Commit an orchestration plan — creates Manifests and ManifestStops.
     *
     * Does NOT trigger dispatch or update order status. That is the
     * responsibility of the operational flow (driver actions / dispatcher).
     *
     * POST /int/v1/fleet-ops/orchestrator/commit
     */
    public function commit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'request_id' => 'required|uuid', 'plan_version' => 'required|uuid',
            'reviewed' => 'required|accepted', 'scheduled_date' => 'sometimes|date_format:Y-m-d',
            'assignments' => 'required|array|min:1|max:500',
            'assignments.*.order_id' => 'required|string|distinct|max:191',
            'assignments.*.vehicle_id' => 'required|string|max:191',
            'assignments.*.driver_id' => 'nullable|string|max:191',
            'assignments.*.sequence' => 'nullable|integer|min:1|max:500',
            'assignments.*.arrival' => 'nullable|integer|min:0|max:4102444800',
            'assignments.*.distance' => 'nullable|numeric|min:0|max:1000000000',
            'assignments.*.duration' => 'nullable|numeric|min:0|max:1000000000',
            'assignments.*.waypoint_sequence' => 'nullable|array|max:500',
            'assignments.*.waypoint_sequence.*' => 'required|string|distinct|max:191',
        ]);
        $companyUuid = $this->companyUuid();
        $assignments = array_map(fn ($a) => \Illuminate\Support\Arr::only($a, [
            'order_id', 'vehicle_id', 'driver_id', 'sequence', 'arrival', 'distance', 'duration', 'waypoint_sequence',
        ]), $validated['assignments']);
        // Do not include an implicit date in the digest: a midnight retry is the same request.
        $hash = hash('sha256', json_encode([$validated['plan_version'], $validated['scheduled_date'] ?? null, $assignments], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($validated, $companyUuid, $assignments, $hash) {
            abort_unless(DB::table('companies')->where('uuid', $companyUuid)->lockForUpdate()->first(), 403, 'Company unavailable.');
            $receipt = DB::table('orchestration_plans')->where('company_uuid', $companyUuid)
                ->where('request_id', $validated['request_id'])->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->request_hash, $hash), 409, 'Request ID already used for a different commit.');
                $stored = json_decode($receipt->response, true, 512, JSON_THROW_ON_ERROR);
                foreach ($stored['permissions'] as $permission) {
                    $this->authorizeOrchestrationAction($permission);
                }

                return response()->json($stored['result']);
            }
            $plan = OrchestrationPlan::load($companyUuid, $validated['plan_version'], true);
            $snapshot = OrchestrationPlan::assertFresh($plan, $companyUuid, true);
            $permissions = ['update-route-for order'];
            $byVehicle = [];
            $driverVehicles = [];
            $orders = [];
            foreach ($assignments as $assignment) {
                $this->assertSnapshotAssignment($assignment, $snapshot);
                $order = $this->findOrderByPublicId($assignment['order_id']);
                $vehicle = $this->findVehicleByPublicId($assignment['vehicle_id']);
                $driver = !empty($assignment['driver_id']) ? $this->findDriverByPublicId($assignment['driver_id']) : null;
                abort_unless($order && $vehicle && (empty($assignment['driver_id']) || $driver), 422, 'Unavailable company resource.');
                abort_unless($order->status === 'created' && !$order->started && !$order->dispatched && !$order->started_at && !$order->dispatched_at, 409, 'Only unstarted, undispatched orders can be planned.');
                abort_if(CollectionWorkflow::applies($order), 422, 'Use the dedicated collection/depot flow for collection-enrolled orders; generic manifests cannot manage custody.');
                abort_unless(in_array($vehicle->status, ['available', 'active'], true), 409, 'Vehicle is not available.');
                if (!$driver && $order->driver_assigned_uuid) {
                    $driver = Driver::where('company_uuid', $companyUuid)->where('uuid', $order->driver_assigned_uuid)->first();
                    abort_unless($driver && isset($snapshot['drivers'][$driver->public_id]), 422, 'Existing driver is outside the reviewed plan.');
                }
                abort_if($driver && !in_array($driver->status, ['available', 'active'], true), 409, 'Driver is not available.');
                if ($order->vehicle_assigned_uuid !== $vehicle->uuid) {
                    $permissions[] = 'assign-vehicle-for order';
                }
                if ($driver && $order->driver_assigned_uuid !== $driver->uuid) {
                    $permissions[] = 'assign-driver-for order';
                }
                // Replacing an existing manifest would leave a second live stop behind.
                abort_if($order->manifest_uuid || ManifestStop::where('order_uuid', $order->uuid)->exists(), 409, 'Order already belongs to a manifest. Review it in the dedicated manifest flow.');
                if ($driver) {
                    abort_if(isset($driverVehicles[$driver->uuid]) && $driverVehicles[$driver->uuid] !== $vehicle->uuid, 422, 'A driver cannot serve multiple vehicles in one plan.');
                    $driverVehicles[$driver->uuid] = $vehicle->uuid;
                }
                $group = $byVehicle[$vehicle->public_id] ?? null;
                abort_if($group && ($group['driver']?->uuid !== $driver?->uuid), 422, 'All orders on a vehicle must use the same driver.');
                foreach ($assignment['waypoint_sequence'] ?? [] as $waypointId) {
                    abort_unless(DB::table('waypoints')->where('company_uuid', $companyUuid)
                        ->where('payload_uuid', $order->payload_uuid)->where('public_id', $waypointId)->whereNull('deleted_at')->exists(), 422, 'Waypoint is outside the order payload.');
                }
                $orders[$order->public_id] = $order;
                $byVehicle[$vehicle->public_id] ??= ['vehicle' => $vehicle, 'driver' => $driver, 'assignments' => []];
                $byVehicle[$vehicle->public_id]['assignments'][] = $assignment;
            }
            $permissions = array_values(array_unique($permissions));
            foreach ($permissions as $permission) {
                $this->authorizeOrchestrationAction($permission);
            }
            $committed = [];
            $manifests = [];
            foreach ($byVehicle as $group) {
                $vehicle = $group['vehicle'];
                $driver = $group['driver'];
                $vehicleAssignments = $group['assignments'];
                abort_if($vehicle->max_tasks && count($vehicleAssignments) > $vehicle->max_tasks, 422, 'Vehicle task capacity exceeded.');
                OrchestrationResourceGuard::validate($vehicle, $driver, collect($vehicleAssignments)->map(fn ($a) => $orders[$a['order_id']]));
                $busy = Order::where('company_uuid', $companyUuid)
                    ->where(fn ($q) => $q->whereIn('status', ['dispatched', 'started'])->orWhere('started', true)->orWhere('dispatched', true))
                    ->where(function ($q) use ($vehicle, $driver) {
                        $q->where('vehicle_assigned_uuid', $vehicle->uuid);
                        if ($driver) {
                            $q->orWhere('driver_assigned_uuid', $driver->uuid);
                        }
                    })->lockForUpdate()->exists();
                $reserved = Manifest::where('company_uuid', $companyUuid)
                    ->where(fn ($q) => $q->whereIn('status', ['active', 'in_progress'])
                        ->orWhere(fn ($draft) => $draft->where('status', 'draft')->whereDate('scheduled_date', $validated['scheduled_date'] ?? now()->toDateString())))
                    ->where(function ($q) use ($vehicle, $driver) {
                        $q->where('vehicle_uuid', $vehicle->uuid);
                        if ($driver) {
                            $q->orWhere('driver_uuid', $driver->uuid);
                        }
                    })->lockForUpdate()->exists();
                abort_if($busy || $reserved, 409, 'Vehicle or driver already has active or reserved work.');
                usort($vehicleAssignments, fn ($a, $b) => ($a['sequence'] ?? 0) <=> ($b['sequence'] ?? 0));
                $sequences = array_filter(array_column($vehicleAssignments, 'sequence'), fn ($s) => $s !== null);
                abort_if(count($sequences) !== count(array_unique($sequences)), 422, 'Stop sequences must be unique per vehicle.');
                $manifest = $this->createManifest([
                    'company_uuid' => $companyUuid, 'vehicle_uuid' => $vehicle->uuid, 'driver_uuid' => $driver?->uuid,
                    'status' => 'draft', 'scheduled_date' => $validated['scheduled_date'] ?? now()->toDateString(),
                    'total_distance_m' => (int) array_sum(array_column($vehicleAssignments, 'distance')),
                    'total_duration_s' => (int) array_sum(array_column($vehicleAssignments, 'duration')),
                    'stop_count' => count($vehicleAssignments),
                ]);
                foreach ($vehicleAssignments as $index => $assignment) {
                    $order = $orders[$assignment['order_id']];
                    $placeUuid = $order->payload_uuid ? DB::table('payloads')->where('company_uuid', $companyUuid)->where('uuid', $order->payload_uuid)->value('dropoff_uuid') : null;
                    $this->createManifestStop([
                        'manifest_uuid' => $manifest->uuid, 'order_uuid' => $order->uuid, 'place_uuid' => $placeUuid,
                        'sequence' => $index + 1, 'status' => 'pending',
                        'estimated_arrival' => isset($assignment['arrival']) ? Carbon::createFromTimestamp($assignment['arrival']) : null,
                        'distance_from_prev_m' => (int) ($assignment['distance'] ?? 0), 'duration_from_prev_s' => (int) ($assignment['duration'] ?? 0),
                    ]);
                    $order->vehicle_assigned_uuid = $vehicle->uuid;
                    $order->manifest_uuid = $manifest->uuid;
                    if ($driver) {
                        $order->driver_assigned_uuid = $driver->uuid;
                    }
                    if (isset($assignment['sequence'])) {
                        $order->is_route_optimized = true;
                    }
                    $order->save();
                    foreach ($assignment['waypoint_sequence'] ?? [] as $sequence => $waypointId) {
                        $this->updateWaypointSequence($order->payload_uuid, $waypointId, $sequence);
                    }
                    $committed[] = $order->public_id;
                }
                $manifests[] = $manifest->public_id;
            }
            $result = ['committed' => $committed, 'failed' => [], 'manifests' => $manifests];
            DB::table('orchestration_plans')->where('uuid', $plan->uuid)->update([
                'request_id' => $validated['request_id'], 'request_hash' => $hash,
                'response' => json_encode(['result' => $result, 'permissions' => $permissions], JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);

            return response()->json($result);
        }, 3);
    }

    protected function assertSnapshotAssignment(array $assignment, array $snapshot): void
    {
        foreach (['order_id' => 'orders', 'vehicle_id' => 'vehicles', 'driver_id' => 'drivers'] as $key => $table) {
            if (!empty($assignment[$key])) {
                abort_unless(isset($snapshot[$table][$assignment[$key]]), 422, 'Assignment references a resource outside the reviewed company plan.');
            }

        }
    }

    protected function authorizeOrchestrationAction(string $permission): void
    {
        // The public controller deliberately uses organization-credential route
        // authorization, not Console user IAM (see its constructor).
        if ($this instanceof \Fleetbase\FleetOps\Http\Controllers\Api\v1\OrchestrationController) {
            return;
        }

        Authorization::authorize($permission);
    }

    protected function beginOrchestrationTransaction(): void
    {
        DB::beginTransaction();
    }

    protected function commitOrchestrationTransaction(): void
    {
        DB::commit();
    }

    protected function rollBackOrchestrationTransaction(): void
    {
        DB::rollBack();
    }

    protected function orchestrationTransactionLevel(): int
    {
        return DB::transactionLevel();
    }

    protected function findVehicleByPublicId(string $publicId): ?Vehicle
    {
        return Vehicle::where('company_uuid', $this->companyUuid())->where('public_id', $publicId)->first();
    }

    protected function findDriverByPublicId(string $publicId): ?Driver
    {
        return Driver::where('company_uuid', $this->companyUuid())->where('public_id', $publicId)->first();
    }

    protected function findOrderByPublicId(string $publicId): ?Order
    {
        return Order::where('company_uuid', $this->companyUuid())->where('public_id', $publicId)->first();
    }

    protected function createManifest(array $attributes): Manifest
    {
        return Manifest::create($attributes);
    }

    protected function createManifestStop(array $attributes): ManifestStop
    {
        return ManifestStop::create($attributes);
    }

    protected function updateWaypointSequence(string $payloadUuid, string $waypointPublicId, int|string $sequence): void
    {
        DB::table('waypoints')
            ->where('company_uuid', $this->companyUuid())
            ->where('payload_uuid', $payloadUuid)
            ->where('public_id', $waypointPublicId)
            ->update(['order' => $sequence]);
    }

    /**
     * Return available orchestration engines.
     *
     * GET /int/v1/fleet-ops/orchestrator/engines
     */
    public function engines(): JsonResponse
    {
        return response()->json([
            'engines' => $this->registry->available(),
        ]);
    }

    /**
     * Return all active Order Configs with their custom field definitions.
     * Used by the Orchestrator Settings UI for configurable card fields.
     *
     * GET /int/v1/fleet-ops/orchestrator/order-config-fields
     */
    public function orderConfigFields(): JsonResponse
    {
        $companyUuid = $this->companyUuid();

        $configs = $this->getOrderConfigFieldConfigs($companyUuid)
            ->map(function ($config) {
                // customFields is a morphMany on subject_uuid/subject_type.
                // If the eager load returned nothing (e.g. subject_type mismatch),
                // fall back to a direct query by subject_uuid.
                $customFields = $config->customFields;
                if ($customFields->isEmpty()) {
                    $customFields = $this->getCustomFieldsForOrderConfig($config->uuid);
                }

                $fields = $customFields
                    ->map(fn ($field) => [
                        'key'      => $field->name ?? Str::slug($field->label ?? '', '_'),
                        'label'    => $field->label ?? $field->name ?? '',
                        'type'     => $field->type ?? 'text',
                        'required' => (bool) ($field->required ?? false),
                    ])
                    ->values();

                return [
                    'id'     => $config->public_id,
                    'uuid'   => $config->uuid,
                    'name'   => $config->name,
                    'key'    => $config->key,
                    'fields' => $fields,
                ];
            });

        // Exclude configs that have no custom fields at all
        $configs = $configs->filter(fn ($config) => count($config['fields']) > 0)->values();

        return response()->json(['configs' => $configs]);
    }

    protected function getOrderConfigFieldConfigs(string $companyUuid)
    {
        return OrderConfig::where('company_uuid', $companyUuid)
            ->with('customFields')
            ->get(['uuid', 'public_id', 'name', 'key']);
    }

    protected function getCustomFieldsForOrderConfig(string $orderConfigUuid)
    {
        return \Fleetbase\Models\CustomField::where('subject_uuid', $orderConfigUuid)
            ->orderBy('order')
            ->get();
    }

    /**
     * Import orders from parsed CSV/Excel row data.
     *
     * POST /int/v1/fleet-ops/orchestrator/import-orders
     */
    public function importOrders(Request $request): JsonResponse
    {
        $rows        = $request->input('rows', []);
        $companyUuid = $this->companyUuid();
        $request->validate(['rows' => 'required|array|max:500', 'rows.*' => 'required|array']);

        if (empty($rows)) {
            return response()->json(['error' => 'No rows provided.'], 422);
        }

        $created = [];
        $failed  = [];

        // ── Group multi-waypoint rows by order_ref ────────────────────────────
        // Rows with order_type = 'multi_waypoint' and the same order_ref are
        // collapsed into a single order where each row becomes one waypoint.
        $groups = [];
        foreach ($rows as $row) {
            $orderType = strtolower(trim($row['order_type'] ?? 'pickup_dropoff'));
            $orderRef  = trim($row['order_ref'] ?? '');

            if ($orderType === 'multi_waypoint' && $orderRef !== '') {
                $groups[$orderRef][] = $row;
            } else {
                // Each pickup/dropoff row is its own independent group.
                $groups['__single_' . Str::uuid()][] = $row;
            }
        }

        foreach ($groups as $groupKey => $groupRows) {
            DB::beginTransaction();
            try {
                // Use the first row for order-level metadata.
                $firstRow  = $groupRows[0];
                $orderType = strtolower(trim($firstRow['order_type'] ?? 'pickup_dropoff'));
                $isMulti   = $orderType === 'multi_waypoint';

                // ── Resolve OrderConfig ───────────────────────────────────────
                $orderConfigUuid     = null;
                $resolvedOrderConfig = null;
                if (!empty($firstRow['type'])) {
                    $resolvedOrderConfig = OrderConfig::resolveFromIdentifier($firstRow['type']);
                    abort_unless($resolvedOrderConfig && $resolvedOrderConfig->company_uuid === $companyUuid, 422, 'Unknown company order configuration.');
                    if ($resolvedOrderConfig) {
                        $orderConfigUuid = $resolvedOrderConfig->uuid;
                    }
                }
                $resolvedOrderConfig ??= OrderConfig::defaultOrCreate();
                $orderConfigUuid = $resolvedOrderConfig?->uuid;

                // ── Resolve Customer ─────────────────────────────────────────
                $customerUuid = null;
                $customerType = null;
                if (!empty($firstRow['customer_email']) || !empty($firstRow['customer_phone']) || !empty($firstRow['customer_name'])) {
                    $customerEntityType = strtolower(trim($firstRow['customer_type'] ?? 'contact'));
                    if ($customerEntityType === 'vendor') {
                        $vendor = $this->resolveOrCreateVendor($firstRow, $companyUuid, 'customer');
                        if ($vendor) {
                            $customerUuid = $vendor->uuid;
                            $customerType = 'Fleetbase\\FleetOps\\Models\\Vendor';
                        }
                    } else {
                        $contact = $this->resolveOrCreateContact($firstRow, $companyUuid, 'customer');
                        if ($contact) {
                            $customerUuid = $contact->uuid;
                            $customerType = 'Fleetbase\\FleetOps\\Models\\Contact';
                        }
                    }
                }

                // ── Resolve Facilitator ───────────────────────────────────────
                $facilitatorUuid = null;
                $facilitatorType = null;
                if (!empty($firstRow['facilitator_email']) || !empty($firstRow['facilitator_phone']) || !empty($firstRow['facilitator_name'])) {
                    $facilitatorEntityType = strtolower(trim($firstRow['facilitator_type'] ?? 'vendor'));
                    if ($facilitatorEntityType === 'contact') {
                        $contact = $this->resolveOrCreateContact($firstRow, $companyUuid, 'facilitator');
                        if ($contact) {
                            $facilitatorUuid = $contact->uuid;
                            $facilitatorType = 'Fleetbase\\FleetOps\\Models\\Contact';
                        }
                    } else {
                        $vendor = $this->resolveOrCreateVendor($firstRow, $companyUuid, 'facilitator');
                        if ($vendor) {
                            $facilitatorUuid = $vendor->uuid;
                            $facilitatorType = 'Fleetbase\\FleetOps\\Models\\Vendor';
                        }
                    }
                }

                // ── Resolve Vehicle ───────────────────────────────────────────
                $vehicleUuid = null;
                if (!empty($firstRow['vehicle_plate'])) {
                    $vehicle = Vehicle::where('company_uuid', $companyUuid)
                        ->where('plate_number', $firstRow['vehicle_plate'])
                        ->first();
                    abort_unless($vehicle, 422, 'Unknown company vehicle.');
                    if ($vehicle) {
                        $vehicleUuid = $vehicle->uuid;
                    }
                }

                // ── Resolve Driver ────────────────────────────────────────────
                $driverUuid       = null;
                $driverIdentifier = $firstRow['driver_email'] ?? $firstRow['driver_phone'] ?? $firstRow['driver_name'] ?? null;
                if ($driverIdentifier) {
                    $driver = Driver::findByIdentifier($driverIdentifier);
                    abort_unless($driver && $driver->company_uuid === $companyUuid, 422, 'Unknown company driver.');
                    if ($driver) {
                        $driverUuid = $driver->uuid;
                    }
                }

                // ── Build required_skills array ───────────────────────────────
                $requiredSkills = [];
                if (!empty($firstRow['required_skills'])) {
                    $requiredSkills = array_filter(array_map('trim', explode(',', $firstRow['required_skills'])));
                }

                // ── Create the Order ─────────────────────────────────────────
                $order = Order::create([
                    'company_uuid'          => $companyUuid,
                    'order_config_uuid'     => $orderConfigUuid,
                    'customer_uuid'         => $customerUuid,
                    'customer_type'         => $customerType,
                    'facilitator_uuid'      => $facilitatorUuid,
                    'facilitator_type'      => $facilitatorType,
                    'vehicle_assigned_uuid' => $vehicleUuid,
                    'driver_assigned_uuid'  => $driverUuid,
                    'internal_id'           => $firstRow['internal_id'] ?? null,
                    'status'                => $firstRow['status'] ?? 'created',
                    'type'                  => $resolvedOrderConfig?->key ?? 'transport',
                    'notes'                 => $firstRow['notes'] ?? null,
                    'scheduled_at'          => !empty($firstRow['scheduled_at'])
                        ? Carbon::parse($firstRow['scheduled_at'])
                        : null,
                    'time_window_start'     => $firstRow['time_window_start'] ?? null,
                    'time_window_end'       => $firstRow['time_window_end'] ?? null,
                    'required_skills'       => $requiredSkills,
                    'orchestrator_priority' => (int) ($firstRow['priority'] ?? 0),
                    'meta'                  => array_filter([
                        'service_time_min' => $firstRow['service_time_min'] ?? null,
                        'order_type'       => $orderType,
                        'order_ref'        => $firstRow['order_ref'] ?? null,
                    ]),
                ]);

                // ── Create Payload ────────────────────────────────────────────
                $payload = Payload::create([
                    'company_uuid'       => $companyUuid,
                    'cod_amount'         => $firstRow['cod_amount'] ?? null,
                    'cod_currency'       => $firstRow['cod_currency'] ?? null,
                    'capacity_weight_kg' => $firstRow['weight_kg'] ?? null,
                    'capacity_volume_m3' => $firstRow['volume_m3'] ?? null,
                    'capacity_parcels'   => $firstRow['parcels'] ?? null,
                ]);

                $order->setPayload($payload);
                $order->save();

                // ── Attach Places ────────────────────────────────────────────────
                $entities = [];
                if ($isMulti) {
                    // ── Multi-waypoint ───────────────────────────────────────
                    // Each row with an address becomes a waypoint stop, tagged
                    // with a stable _import_id ('wp_0', 'wp_1', …).
                    //
                    // Multiple entities per order are supported: any row in the
                    // group that has entity fields will produce an entity.  If
                    // the row also has an address it is linked to that waypoint
                    // via _import_id; otherwise entity_destination is used to
                    // resolve the target stop (index, 'pickup', or 'dropoff').
                    $waypoints    = [];
                    $wpImportIds  = []; // rowIndex => _import_id for address rows

                    foreach ($groupRows as $wpIndex => $wpRow) {
                        $importId  = 'wp_' . $wpIndex;
                        $placeData = $this->buildPlaceData($wpRow, 'dropoff');
                        if (!empty($placeData['street1'])) {
                            $place = Place::createFromMixed($placeData, [], true);
                            if ($place) {
                                $waypoints[]             = [
                                    'place_uuid' => $place->uuid,
                                    'type'       => 'dropoff',
                                    '_import_id' => $importId,
                                ];
                                $wpImportIds[$wpIndex] = $importId;
                            }
                        }
                    }

                    if (!empty($waypoints)) {
                        $payload->setWaypoints($waypoints);
                    }

                    // Collect entities from ALL rows in the group
                    foreach ($groupRows as $wpIndex => $wpRow) {
                        $entityData = $this->buildEntityData($wpRow, $companyUuid);
                        if ($entityData === null) {
                            continue;
                        }

                        // Prefer the _import_id of this row's own waypoint;
                        // fall back to entity_destination if the row has no address.
                        if (isset($wpImportIds[$wpIndex])) {
                            $entityData['_import_id'] = $wpImportIds[$wpIndex];
                        } else {
                            $dest = $wpRow['entity_destination'] ?? null;
                            if ($dest !== null && $dest !== '') {
                                // Numeric string → waypoint index; otherwise 'pickup'/'dropoff'
                                if (is_numeric($dest)) {
                                    $targetIndex = (int) $dest;
                                    if (isset($wpImportIds[$targetIndex])) {
                                        $entityData['_import_id'] = $wpImportIds[$targetIndex];
                                    }
                                } else {
                                    $entityData['destination'] = $dest;
                                }
                            }
                        }

                        $entities[] = $entityData;
                    }
                } else {
                    // ── Pickup / Dropoff ─────────────────────────────────────
                    // Address fields come from the first row only.
                    // Entity rows: ALL rows in the group are scanned so that
                    // multiple entities (one per row) can be attached to the
                    // same order.  Only the first row is used for addresses.
                    $pickupData  = $this->buildPlaceData($firstRow, 'pickup');
                    $dropoffData = $this->buildPlaceData($firstRow, 'dropoff');

                    if (!empty($pickupData['street1'])) {
                        $payload->setPickup($pickupData, ['save' => true]);
                    }

                    if (!empty($dropoffData['street1'])) {
                        $payload->setDropoff($dropoffData, ['save' => true]);
                    }

                    // Collect entities from ALL rows in the group
                    foreach ($groupRows as $entityRow) {
                        $entityData = $this->buildEntityData($entityRow, $companyUuid);
                        if ($entityData === null) {
                            continue;
                        }
                        // Resolve destination from each row's entity_destination
                        // column; default to 'dropoff' when not set.
                        $dest                      = $entityRow['entity_destination'] ?? 'dropoff';
                        $entityData['destination'] = ($dest !== '') ? $dest : 'dropoff';
                        $entities[]                = $entityData;
                    }
                }

                // ── Attach Entities to Payload ──────────────────────────────
                if (!empty($entities)) {
                    $payload->load(['waypoints', 'pickup', 'dropoff']);
                    $payload->setEntities($entities);
                }

                DB::commit();
                $created[] = $order->public_id;
            } catch (\Exception $e) {
                DB::rollBack();
                $rowIndex = ($groupRows[0]['_rowIndex'] ?? '?');
                $failed[] = ['row' => $rowIndex, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'created' => $created,
            'failed'  => $failed,
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build a Place-compatible attributes array from a CSV row.
     *
     * @param array  $row    the mapped CSV row
     * @param string $prefix 'pickup' or 'dropoff'
     */
    private function buildPlaceData(array $row, string $prefix): array
    {
        return array_filter([
            'name'        => $row["{$prefix}_name"] ?? null,
            'street1'     => $row["{$prefix}_street1"] ?? null,
            'street2'     => $row["{$prefix}_street2"] ?? null,
            'city'        => $row["{$prefix}_city"] ?? null,
            'province'    => $row["{$prefix}_state"] ?? null,
            'postal_code' => $row["{$prefix}_postal_code"] ?? null,
            'country'     => $row["{$prefix}_country"] ?? null,
            'phone'       => $row["{$prefix}_phone"] ?? null,
            'location'    => $this->buildLocationPoint($row["{$prefix}_lat"] ?? null, $row["{$prefix}_lng"] ?? null),
        ]);
    }

    /**
     * Build a WKT POINT string from optional lat/lng strings.
     * Returns null when either value is missing or both are zero.
     */
    private function buildLocationPoint(?string $lat, ?string $lng): ?string
    {
        if (empty($lat) || empty($lng)) {
            return null;
        }
        $latF = (float) $lat;
        $lngF = (float) $lng;
        if ($latF === 0.0 && $lngF === 0.0) {
            return null;
        }

        return "POINT({$lngF} {$latF})";
    }

    /**
     * Build an Entity-compatible attributes array from a CSV row.
     * Returns null when no entity fields are present in the row.
     *
     * @param array  $row         the mapped CSV row
     * @param string $companyUuid the current company UUID
     */
    private function buildEntityData(array $row, string $companyUuid): ?array
    {
        // Only build an entity if at least one entity field has a value
        $hasEntity = !empty($row['entity_name'])
            || !empty($row['entity_type'])
            || !empty($row['entity_sku'])
            || !empty($row['entity_barcode'])
            || !empty($row['entity_description']);

        if (!$hasEntity) {
            return null;
        }

        return array_filter([
            'company_uuid'     => $companyUuid,
            'name'             => $row['entity_name'] ?? null,
            'type'             => $row['entity_type'] ?? null,
            'description'      => $row['entity_description'] ?? null,
            'sku'              => $row['entity_sku'] ?? null,
            'barcode'          => $row['entity_barcode'] ?? null,
            'internal_id'      => $row['entity_internal_id'] ?? null,
            'declared_value'   => isset($row['entity_declared_value']) && $row['entity_declared_value'] !== ''
                                    ? (float) $row['entity_declared_value'] : null,
            'currency'         => $row['entity_currency'] ?? null,
            'price'            => isset($row['entity_price']) && $row['entity_price'] !== ''
                                    ? (float) $row['entity_price'] : null,
            'sale_price'       => isset($row['entity_sale_price']) && $row['entity_sale_price'] !== ''
                                    ? (float) $row['entity_sale_price'] : null,
            'weight'           => isset($row['entity_weight']) && $row['entity_weight'] !== ''
                                    ? (float) $row['entity_weight'] : null,
            'weight_unit'      => $row['entity_weight_unit'] ?? null,
            'length'           => isset($row['entity_length']) && $row['entity_length'] !== ''
                                    ? (float) $row['entity_length'] : null,
            'width'            => isset($row['entity_width']) && $row['entity_width'] !== ''
                                    ? (float) $row['entity_width'] : null,
            'height'           => isset($row['entity_height']) && $row['entity_height'] !== ''
                                    ? (float) $row['entity_height'] : null,
            'dimensions_unit'  => $row['entity_dimensions_unit'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Resolve an existing Contact by email/phone, or create a new one.
     *
     * @param array  $row         the mapped CSV row
     * @param string $companyUuid the company UUID
     * @param string $prefix      'customer' or 'facilitator'
     */
    private function resolveOrCreateContact(array $row, string $companyUuid, string $prefix): ?Contact
    {
        $email = $row["{$prefix}_email"] ?? null;
        $phone = $row["{$prefix}_phone"] ?? null;
        $name  = $row["{$prefix}_name"] ?? null;

        // Try to find by email first, then phone.
        $contact = null;
        if ($email) {
            $contact = Contact::where('company_uuid', $companyUuid)->where('email', $email)->first();
        }
        if (!$contact && $phone) {
            $contact = Contact::where('company_uuid', $companyUuid)->where('phone', $phone)->first();
        }

        if ($contact) {
            return $contact;
        }

        // Create a new contact if we have at least a name or email.
        if ($name || $email) {
            return Contact::create(array_filter([
                'company_uuid' => $companyUuid,
                'name'         => $name,
                'email'        => $email,
                'phone'        => $phone,
                'type'         => 'customer',
            ]));
        }

        return null;
    }

    /**
     * Resolve an existing Vendor by email/phone, or create a new one.
     *
     * @param array  $row         the mapped CSV row
     * @param string $companyUuid the company UUID
     * @param string $prefix      'customer' or 'facilitator'
     */
    private function resolveOrCreateVendor(array $row, string $companyUuid, string $prefix): ?Vendor
    {
        $email = $row["{$prefix}_email"] ?? null;
        $phone = $row["{$prefix}_phone"] ?? null;
        $name  = $row["{$prefix}_name"] ?? null;

        $vendor = null;
        if ($email) {
            $vendor = Vendor::where('company_uuid', $companyUuid)->where('email', $email)->first();
        }
        if (!$vendor && $phone) {
            $vendor = Vendor::where('company_uuid', $companyUuid)->where('phone', $phone)->first();
        }
        if (!$vendor && $name) {
            $vendor = Vendor::where('company_uuid', $companyUuid)->whereRaw('lower(name) = ?', [strtolower($name)])->first();
        }

        if ($vendor) {
            return $vendor;
        }

        if ($name || $email) {
            return Vendor::create(array_filter([
                'company_uuid' => $companyUuid,
                'name'         => $name,
                'email'        => $email,
                'phone'        => $phone,
            ]));
        }

        return null;
    }
}
