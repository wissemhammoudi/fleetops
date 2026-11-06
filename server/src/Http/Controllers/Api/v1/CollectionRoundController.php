<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\CollectionDeliveryLeg;
use Fleetbase\FleetOps\Support\CollectionRouteProgress;
use Fleetbase\FleetOps\Support\CollectionDelivery;
use Fleetbase\FleetOps\Support\CollectionRounds;
use Fleetbase\FleetOps\Support\DeliveryOptions;
use Fleetbase\FleetOps\Support\DriverNavigation;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CollectionRoundController extends Controller
{
    private function driver(): Driver
    {
        abort_unless(session('company') && session('user'), 403, 'Driver authentication is required.');

        return Driver::where('company_uuid', session('company'))->where('user_uuid', session('user'))->firstOrFail();
    }

    public function index()
    {
        $driver = $this->driver();

        return response()->json(['rounds' => CollectionRound::where('company_uuid', $driver->company_uuid)->where('driver_uuid', $driver->uuid)
            ->whereIn('status', ['released', 'in_progress', 'awaiting_depot', 'completed'])
            ->orderByRaw("CASE WHEN status = 'completed' THEN 1 ELSE 0 END")
            ->orderByRaw("CASE WHEN status = 'completed' THEN scheduled_at END DESC")
            ->orderBy('scheduled_at')->limit(100)->get()->map(function ($round) use ($driver) {
                $data = $round->toArray();
                if (!empty($data['routing']['stops'])) $data['routing'] = CollectionRouteProgress::forDriver($data['routing'], $driver->company_uuid, $driver->uuid);
                return $data;
            }),
            'catalogue' => DeliveryOptions::catalogue()]);
    }

    public function update(Request $request, string $id)
    {
        $driver = $this->driver();
        $input = $request->validate([
            'action' => ['required', 'in:start,start_visit,result'],
            'revision' => ['required', 'integer', 'min:1'],
            'stop_uuid' => ['required_if:action,result,start_visit', 'string', 'max:150'],
            'request_id' => ['required_if:action,result', 'string', 'min:8', 'max:100'],
            'outcome' => ['required_if:action,result', 'in:collected,not_ready,absent,packaging_refused'],
            'reason' => ['required_if:outcome,not_ready,absent,packaging_refused', 'nullable', 'string', 'max:1000'],
            'checks' => ['sometimes', 'array'],
            'checks.*' => ['boolean'],
        ]);

        return response()->json(['round' => CollectionRounds::drive((string) $driver->company_uuid, $driver->uuid, $id, $input)]);
    }

    public function deliveries()
    {
        $driver = $this->driver();

        return response()->json(['deliveries' => CollectionDeliveryLeg::where('company_uuid', $driver->company_uuid)
            ->where('driver_uuid', $driver->uuid)->whereIn('status', ['staging', 'accepted', 'held', 'released'])
            ->orderByRaw("CASE WHEN status = 'released' THEN 1 ELSE 0 END")
            ->orderBy('created_at', 'desc')->limit(100)->get()->sort(function ($left, $right) {
                return [
                    data_get($left->manifest, 'routing.departure_at', ''), data_get($left->manifest, 'routing.plan_uuid', ''),
                    (int) data_get($left->manifest, 'routing.order_sequence', 0), $left->uuid,
                ] <=> [
                    data_get($right->manifest, 'routing.departure_at', ''), data_get($right->manifest, 'routing.plan_uuid', ''),
                    (int) data_get($right->manifest, 'routing.order_sequence', 0), $right->uuid,
                ];
            })->values()->map(function ($leg) use ($driver) {
                $data = $leg->toArray();
                unset($data['manifest']['requests']);
                if (!empty($data['manifest']['routing']['stops'])) $data['manifest']['routing'] = CollectionRouteProgress::forDriver($data['manifest']['routing'], $driver->company_uuid, $driver->uuid);

                return $data;
            }), 'catalogue' => DeliveryOptions::catalogue()]);
    }

    public function navigation()
    {
        return response()->json(['navigation' => \Fleetbase\FleetOps\Support\ActiveVisitTracking::read($this->driver())])->header('Cache-Control', 'no-store');
    }

    public function startDelivery(Request $request)
    {
        $driver = $this->driver();
        $input = $request->validate(['order_uuid' => ['required', 'string', 'max:150']]);
        return \Fleetbase\FleetOps\Support\DriverNavigation::transaction($driver->company_uuid, function () use ($driver, $input) {
            $order = \Fleetbase\FleetOps\Models\Order::where('company_uuid', $driver->company_uuid)->where('driver_assigned_uuid', $driver->uuid)
                ->where('uuid', $input['order_uuid'])->firstOrFail();
            abort_if(in_array($order->status, DriverNavigation::TERMINAL_STATUSES), 409, 'This delivery is closed.');
            if (!$order->started) return app(OrderController::class)->startOrder($order->public_id, new Request());
            \Fleetbase\FleetOps\Support\DriverNavigation::delivery($driver, $order);
            return response()->json(['started' => true]);
        });
    }

    public function acceptDelivery(Request $request, string $id)
    {
        $driver = $this->driver();

        return response()->json(['delivery' => CollectionDelivery::change((string) $driver->company_uuid, $id, $request->all(), $driver->uuid)]);
    }
}
