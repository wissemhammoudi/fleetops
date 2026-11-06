<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class CollectionPickupTracking
{
    public static function read(Order $order): array
    {
        $roundId = data_get($order->meta, 'collection_workflow.collection_round_uuid');
        $round = $roundId ? CollectionRound::where('company_uuid', $order->company_uuid)->where('uuid', $roundId)->first() : null;
        $ownStops = $round ? collect($round->stops)->where('order_uuid', $order->uuid)->values() : collect();
        if (!$round || $ownStops->isEmpty() || !in_array($round->status, ['released', 'in_progress', 'awaiting_depot', 'completed'])
            || in_array($order->status, ['canceled', 'cancelled', 'order_canceled'])) {
            return ['pickups' => [], 'observed_at' => now()->toISOString()];
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
                    if (!$delivery || !in_array($delivery->status, ['completed', 'canceled', 'cancelled', 'order_canceled'])) {
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
        $active = $round->status === 'in_progress' && ($next['order_uuid'] ?? null) === $order->uuid;
        $driver = $active && $round->driver_uuid
            ? Driver::where('company_uuid', $order->company_uuid)->where('uuid', $round->driver_uuid)->first()
            : null;
        $positionAt = data_get($driver?->meta, 'navigator_position_at');
        $point = $driver?->location;
        $coordinates = $point ? [$point->getLng(), $point->getLat()] : null;
        $time = $positionAt ? Carbon::parse($positionAt) : null;
        $fresh = $driver && $driver->online && $time && $time->between(now()->subSeconds(120), now()->addSeconds(30))
            && $coordinates && abs($coordinates[0]) <= 180 && abs($coordinates[1]) <= 90 && ($coordinates[0] != 0 || $coordinates[1] != 0);

        return [
            'pickups' => $ownStops->map(function ($stop) use ($round, $active, $next, $driver, $positionAt, $fresh, $coordinates, $order) {
                $onWay = $active && ($next['uuid'] ?? null) === $stop['uuid'];
                $received = (bool) data_get($order->meta, 'collection_workflow.depot_received_at');
                return [
                    'stop_uuid' => $stop['uuid'],
                    'status' => $received ? 'at_depot' : ($stop['status'] === 'pending' ? ($onWay ? 'driver_on_way' : 'scheduled') : $stop['status']),
                    'scheduled_at' => $round->scheduled_at,
                    'timezone' => $round->timezone,
                    'contact' => Arr::only($stop['contact'] ?? [], ['name', 'street1', 'street2', 'city']),
                    'location' => $stop['location'] ?? null,
                    'driver' => $onWay && $driver ? [
                        'name' => $driver->name, 'phone' => $driver->phone, 'online' => (bool) $driver->online,
                        'position_at' => $positionAt,
                        'location' => $fresh ? ['latitude' => $coordinates[1], 'longitude' => $coordinates[0]] : null,
                    ] : null,
                ];
            })->all(),
            'observed_at' => now()->toISOString(),
        ];
    }
}
