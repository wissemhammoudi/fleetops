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
        $activity = self::activity($order, $round);
        $ownStops = $round ? collect($round->stops)->where('order_uuid', $order->uuid)->values() : collect();
        if (!$round || $ownStops->isEmpty() || !in_array($round->status, ['released', 'in_progress', 'awaiting_depot', 'received_at_depot', 'completed'])
            || in_array($order->status, ['canceled', 'cancelled', 'order_canceled'])) {
            return ['pickups' => [], 'activity' => $activity, 'delivery' => ActiveVisitTracking::forOrder($order, 'delivery'), 'observed_at' => now()->toISOString()];
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
                    'depot' => $received ? Arr::only($round->depot ?? [], ['name', 'street1', 'city']) : null,
                    'ready_for_delivery' => $received && in_array(data_get($order->meta, 'collection_workflow.state'), ['received_at_depot', 'ready_for_delivery_dispatch'], true),
                    'scheduled_at' => $round->scheduled_at,
                    'timezone' => $round->timezone,
                    'contact' => Arr::only($stop['contact'] ?? [], ['name', 'street1', 'street2', 'city']),
                    'location' => $stop['location'] ?? null,
                    'navigation' => $onWay ? $navigation : null,
                    'driver' => $onWay ? ($navigation['driver'] ?? null) : null,
                ];
            })->all(),
            'delivery' => ActiveVisitTracking::forOrder($order, 'delivery'),
            'activity' => $activity,
            'observed_at' => now()->toISOString(),
        ];
    }

    private static function activity(Order $order, ?CollectionRound $round): array
    {
        $states = ['awaiting_preparation', 'ready_for_pickup', 'pickup_scheduled', 'pickup_in_progress', 'collected',
            'pickup_exception', 'depot_hold', 'received_at_depot', 'ready_for_delivery_dispatch', 'out_for_delivery', 'delivered', 'cancelled'];
        $events = ['pickup_handover_confirmed', 'pickup_handover_disputed', 'pickup_rescheduled', 'delivery_rescheduled'];
        $activity = [];
        foreach (data_get($order->meta, 'collection_workflow.history', []) as $index => $entry) {
            $code = $entry['event'] ?? $entry['state'] ?? null;
            if (!in_array($code, [...$states, ...$events], true) || empty($entry['at']) || strtotime($entry['at']) === false) continue;
            $activity[] = ['id' => 'order:' . $index, 'code' => $code, 'created_at' => $entry['at']];
        }
        if ($order->started_at) {
            $activity[] = ['id' => 'delivery:started', 'code' => 'delivery_driver_on_way', 'created_at' => $order->started_at->toISOString()];
        }
        // Include previous attempts after readiness withdrawal or reassignment clears the current round.
        $roundIds = collect(data_get($order->meta, 'collection_workflow.history', []))->pluck('round_uuid')
            ->push($round?->uuid)->filter()->unique()->values();
        $rounds = $roundIds->isEmpty() ? collect() : CollectionRound::where('company_uuid', $order->company_uuid)->whereIn('uuid', $roundIds)->get();
        foreach ($rounds as $attempt) {
            $ownStops = collect($attempt->stops)->where('order_uuid', $order->uuid)->pluck('uuid')->all();
            foreach ($attempt->history ?? [] as $index => $entry) {
                if (!in_array($entry['stop_uuid'] ?? null, $ownStops, true) || empty($entry['at']) || strtotime($entry['at']) === false) continue;
                $code = ($entry['event'] ?? null) === 'visit_started' ? 'pickup_driver_on_way'
                    : (($entry['event'] ?? null) === 'stop_recorded' ? $entry['outcome'] ?? null : null);
                if (!in_array($code, ['pickup_driver_on_way', 'collected', 'absent', 'not_ready', 'packaging_refused'], true)) continue;
                if ($code === 'collected' && collect($activity)->contains(fn ($item) => $item['code'] === $code
                    && abs(strtotime($item['created_at']) - strtotime($entry['at'])) <= 1)) continue;
                $activity[] = ['id' => 'pickup:' . sha1($attempt->uuid . ':' . $index), 'code' => $code, 'created_at' => $entry['at']];
            }
        }
        return $activity;
    }
}
