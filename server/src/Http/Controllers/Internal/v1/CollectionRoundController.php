<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\CollectionDeliveryLeg;
use Fleetbase\FleetOps\Support\CollectionDelivery;
use Fleetbase\FleetOps\Support\CollectionRounds;
use Fleetbase\FleetOps\Support\CollectionDepot;
use Fleetbase\FleetOps\Support\CollectionCapacity;
use Fleetbase\FleetOps\Support\CollectionReadiness;
use Fleetbase\FleetOps\Support\CollectionSchedule;
use Fleetbase\FleetOps\Support\CollectionConsoleAccess;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Milon\Barcode\Facades\DNS2DFacade as DNS2D;

class CollectionRoundController extends Controller
{
    public function index()
    {
        $company = CollectionConsoleAccess::authorize('read');
        $paging = request()->validate(['followup_page' => ['sometimes', 'integer', 'min:1'], 'round_page' => ['sometimes', 'integer', 'min:1']]);
        $page = (int) ($paging['followup_page'] ?? 1);
        $schedule = CollectionSchedule::forCompany($company);
        $drivers = Driver::where('company_uuid', $company)->get()->map(fn ($driver) => ['uuid' => $driver->uuid, 'name' => $driver->name,
            'parcel_capacity' => $schedule['capacity_planning'] ? CollectionCapacity::driverLimit($driver, $schedule) : null]);
        $followup = \Fleetbase\FleetOps\Models\Order::where('company_uuid', $company)
            ->whereIn('status', ['created', 'pending', 'awaiting_preparation', 'ready_for_pickup'])->whereIn('meta->collection_workflow->state', ['awaiting_preparation', 'ready_for_pickup'])
            ->orderBy('created_at')->paginate(25, ['*'], 'followup_page', $page);
        $rows = $followup->getCollection()->map(function ($order) {
            try {
                $stops = CollectionReadiness::pickupStops($order);
                return ['order_uuid' => $order->uuid, 'order_id' => $order->public_id, 'state' => data_get($order->meta, 'collection_workflow.state'),
                    'parcel_count' => CollectionCapacity::parcels($stops), 'stops' => $stops,
                    'timezone' => data_get($order->meta, 'collection_workflow.timezone'),
                    'pickup_window' => data_get($order->meta, 'collection_workflow.pickup_window')];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                if ($error->getStatusCode() !== 409) throw $error;
                \Illuminate\Support\Facades\Log::warning('Pickup follow-up needs review.', ['order_uuid' => $order->uuid, 'message' => $error->getMessage()]);
                return ['order_uuid' => $order->uuid, 'order_id' => $order->public_id, 'state' => data_get($order->meta, 'collection_workflow.state'),
                    'error' => $error->getMessage(), 'stops' => []];
            }
        });

        $rounds = CollectionRound::where('company_uuid', $company)->where('status', '!=', 'closed')->whereJsonLength('stops', '>', 0)
            ->orderBy('scheduled_at')->orderBy('uuid')->paginate(100, ['*'], 'round_page', $paging['round_page'] ?? 1);
        return response()->json(['permissions' => CollectionConsoleAccess::permissions(),
            'schedule' => $schedule, 'pickup_dates' => CollectionSchedule::pickupDates(now(), $schedule),
            'follow_up' => $rows, 'follow_up_page' => $followup->currentPage(), 'follow_up_last_page' => $followup->lastPage(), 'follow_up_total' => $followup->total(),
            'rounds_page' => $rounds->currentPage(), 'rounds_last_page' => $rounds->lastPage(), 'rounds_total' => $rounds->total(),
            'rounds' => $rounds->getCollection()->map(function ($round) use ($drivers, $schedule) {
                $data = $round->toArray();
                $data['parcel_count'] = CollectionCapacity::parcels($round->stops);
                $data['pickup_count'] = count($round->stops);
                $data['order_count'] = count(array_unique(array_column($round->stops, 'order_uuid')));
                $assigned = $drivers->firstWhere('uuid', $round->driver_uuid);
                $data['parcel_capacity'] = $assigned['parcel_capacity'] ?? null;
                $data['max_pickup_stops'] = $schedule['max_pickup_stops'];
                $firstOrder = \Fleetbase\FleetOps\Models\Order::where('company_uuid', $round->company_uuid)->where('uuid', data_get($round->stops, '0.order_uuid'))->first();
                $data['pickup_window'] = data_get($firstOrder?->meta, 'collection_workflow.pickup_window');
                $data['suggested_drivers'] = $schedule['capacity_planning'] ? $drivers->filter(fn ($driver) =>
                    $driver['parcel_capacity'] !== null && $driver['parcel_capacity'] >= $data['parcel_count'] && $data['pickup_count'] <= $schedule['max_pickup_stops']
                    && !CollectionRound::where('company_uuid', $round->company_uuid)->where('uuid', '!=', $round->uuid)
                        ->where('driver_uuid', $driver['uuid'])->where('scheduled_at', $round->scheduled_at)
                        ->whereIn('status', ['draft', 'released', 'in_progress', 'awaiting_depot'])->exists())->values()->all() : [];
                unset($data['custody']['requests']);
                $data['depot_orders'] = [];
                foreach (data_get($round->custody, 'receipts', []) as $uuid => $receipt) {
                    if (!collect($round->stops)->where('order_uuid', $uuid)->contains('status', 'collected')) {
                        continue;
                    }
                    $order = \Fleetbase\FleetOps\Models\Order::where('company_uuid', $round->company_uuid)->where('uuid', $uuid)->first();
                    $leg = CollectionDeliveryLeg::where('company_uuid', $round->company_uuid)->where('order_uuid', $uuid)->first();
                    $delivery = $leg?->toArray();
                    unset($delivery['manifest']['requests']);
                    $data['depot_orders'][] = ['order_uuid' => $uuid, 'eligible' => $order && CollectionDepot::eligible($order), 'delivery' => $delivery] + $receipt;
                }

                return $data;
            }),
            'drivers' => $drivers,
            'depots' => DB::connection(config('fleetbase.connection.db', 'mysql'))->table('places')->where('company_uuid', $company)
                ->whereNull('deleted_at')->orderBy('name')->get(['uuid', 'name', 'street1'])]);
    }

    public function plan()
    {
        $input = request()->validate([
            'pickup_date' => ['nullable', 'date_format:Y-m-d'],
            'order_uuids' => ['sometimes', 'array', 'min:1', 'max:100'],
            'order_uuids.*' => ['required', 'string', 'distinct', 'max:100'],
        ]);
        return response()->json(CollectionRounds::plan(CollectionConsoleAccess::authorize('plan'), $input));
    }

    public function depot(Request $request, string $id)
    {
        $input = $request->validate(['action' => ['required', 'in:scan,reconcile,resolve']]);
        return response()->json(['round' => CollectionDepot::change(CollectionConsoleAccess::authorize($input['action']), $id, $request->all())]);
    }

    public function delivery(Request $request, string $id)
    {
        $input = $request->validate(['action' => ['required', 'in:assign,load,release,recall,reschedule']]);
        $action = $input['action'] === 'reschedule' ? 'assign-delivery' : $input['action'] . '-delivery';
        return response()->json(['delivery' => CollectionDelivery::change(CollectionConsoleAccess::authorize($action), $id, $request->all())]);
    }

    public function labels(string $id)
    {
        $round = CollectionRound::where('company_uuid', CollectionConsoleAccess::authorize('labels'))->where('uuid', $id)->firstOrFail();
        abort_unless($round->custody && $round->status !== 'draft', 409, 'Release the round to create physical parcel labels.');

        return response()->json(['labels' => array_map(fn ($unit) => \Illuminate\Support\Arr::only($unit, ['barcode', 'order_id', 'name', 'number', 'quantity'])
            + ['image' => DNS2D::getBarcodePNG($unit['barcode'], 'QRCODE')], $round->custody['units'])]);
    }

    public function update(Request $request, string $id)
    {
        CollectionConsoleAccess::authorize('read');
        $input = $request->validate([
            'action' => ['required', 'in:assign,release,cancel,reschedule'],
            'revision' => ['required', 'integer', 'min:1'],
            'driver_uuid' => ['required_if:action,assign', 'uuid'],
            'depot_uuid' => ['required_if:action,assign', 'uuid'],
            'pickup_date' => ['required_if:action,reschedule', 'date_format:Y-m-d'],
        ]);

        $company = CollectionConsoleAccess::authorize($input['action'] === 'reschedule' ? 'plan' : $input['action']);
        if ($input['action'] === 'reschedule' && !empty($input['driver_uuid'])) {
            CollectionConsoleAccess::authorize('assign');
        }

        return response()->json(['round' => CollectionRounds::operate($company, $id, $input)]);
    }
}
