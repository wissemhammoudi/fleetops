<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Arr;

class CollectionPickupTracking
{
    public static function read(Order $order): array
    {
        $roundId = data_get($order->meta, 'collection_workflow.collection_round_uuid');
        $round = $roundId ? CollectionRound::where('company_uuid', $order->company_uuid)->where('uuid', $roundId)->first() : null;
        $ownStops = $round ? collect($round->stops)->where('order_uuid', $order->uuid)->values() : collect();
        if (!$round || $ownStops->isEmpty() || !in_array($round->status, ['released', 'in_progress', 'awaiting_depot', 'completed'])
            || in_array($order->status, ['canceled', 'cancelled', 'order_canceled'])) {
            return ['pickups' => [], 'delivery' => ActiveVisitTracking::forOrder($order, 'delivery'), 'observed_at' => now()->toISOString()];
        }

        $next = collect($round->stops)->firstWhere('status', 'pending');
        $routing = data_get($round->routing, 'stops', []);
        if ($routing) {
            $next = null;
            $deliveryIds = collect($routing)->where('kind', 'delivery')->pluck('order_uuid');
            $deliveries = $deliveryIds->isEmpty() ? collect() : Order::where('company_uuid', $order->company_uuid)
                ->whereIn('uuid', $deliveryIds)->get()->keyBy('uuid');
            foreach ($routing as $visit) {
                if (($visit['kind'] ?? 'pickup') === 'delivery') {
                    $delivery = $deliveries->get($visit['order_uuid']);
                    if (!$delivery || !in_array($delivery->status, DriverNavigation::TERMINAL_STATUSES)) {
                        break;
                    }
                } else {
                    $pending = collect($round->stops)->first(fn ($stop) => $stop['order_uuid'] === $visit['order_uuid'] && $stop['status'] === 'pending');
                    if ($pending) {
                        $next = $pending;
                        break;
                    }
                }
            }
        }
        $driver = $round->driver_uuid
            ? Driver::where('company_uuid', $order->company_uuid)->where('uuid', $round->driver_uuid)->first()
            : null;
        $visit = $driver ? DriverNavigation::active($driver) : null;
        $active = $round->status === 'in_progress' && ($next['order_uuid'] ?? null) === $order->uuid
            && ($visit['kind'] ?? null) === 'pickup' && ($visit['round_uuid'] ?? null) === $round->uuid
            && ($visit['order_uuid'] ?? null) === $order->uuid;
        if (!$active) $driver = null;
        $navigation = $driver ? ActiveVisitTracking::read($driver) : null;
        return [
            'pickups' => $ownStops->map(function ($stop) use ($round, $active, $next, $visit, $order, $navigation) {
                $onWay = $active && ($next['uuid'] ?? null) === $stop['uuid'] && ($visit['stop_uuid'] ?? null) === $stop['uuid'];
                $received = (bool) data_get($order->meta, 'collection_workflow.depot_received_at');
                return [
                    'stop_uuid' => $stop['uuid'],
                    'status' => $received ? 'at_depot' : ($stop['status'] === 'pending' ? ($onWay ? 'driver_on_way' : 'scheduled') : $stop['status']),
                    'scheduled_at' => $round->scheduled_at,
                    'timezone' => $round->timezone,
                    'contact' => Arr::only($stop['contact'] ?? [], ['name', 'street1', 'street2', 'city']),
                    'location' => $stop['location'] ?? null,
                    'navigation' => $onWay ? $navigation : null,
                    'driver' => $onWay ? ($navigation['driver'] ?? null) : null,
                ];
            })->all(),
            'delivery' => ActiveVisitTracking::forOrder($order, 'delivery'),
            'observed_at' => now()->toISOString(),
        ];
    }
}
