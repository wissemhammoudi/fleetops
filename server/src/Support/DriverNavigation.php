<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Models\CollectionDeliveryLeg;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\Company;
use Illuminate\Support\Facades\DB;

class DriverNavigation
{
    public const TERMINAL_STATUSES = ['completed', 'delivered', 'done', 'order_completed', 'canceled', 'cancelled', 'order_canceled'];

    public static function transaction(string $company, callable $work)
    {
        return DB::connection(config('fleetbase.connection.db', 'mysql'))->transaction(function () use ($company, $work) {
            Company::where('uuid', $company)->lockForUpdate()->firstOrFail();
            return $work();
        });
    }

    public static function active(Driver $driver): ?array
    {
        $visit = data_get($driver->meta, 'navigator_active_visit');
        if (!is_array($visit) || empty($visit['order_uuid'])) return null;
        $order = Order::where('company_uuid', $driver->company_uuid)->where('uuid', $visit['order_uuid'])->first();
        if (!$order || in_array($order->status, self::TERMINAL_STATUSES)) return null;
        if (($visit['kind'] ?? null) === 'depot') {
            $round = CollectionRound::where('company_uuid', $driver->company_uuid)->where('uuid', $visit['round_uuid'])
                ->where('driver_uuid', $driver->uuid)->where('status', 'awaiting_depot')->first();
            return $round && !collect($round->stops)->contains('status', 'pending')
                && collect($round->stops)->contains(fn ($stop) => $stop['order_uuid'] === $order->uuid && $stop['status'] === 'collected')
                && data_get($order->meta, 'collection_workflow.collection_round_uuid') === $round->uuid ? $visit : null;
        }
        if (($visit['kind'] ?? null) === 'pickup') {
            $round = CollectionRound::where('company_uuid', $driver->company_uuid)->where('uuid', $visit['round_uuid'])
                ->where('driver_uuid', $driver->uuid)->where('status', 'in_progress')->first();
            $stop = $round ? collect($round->stops)->firstWhere('uuid', $visit['stop_uuid']) : null;
            return $stop && $stop['order_uuid'] === $order->uuid && $stop['status'] === 'pending'
                && data_get($order->meta, 'collection_workflow.collection_round_uuid') === $round->uuid ? $visit : null;
        }
        return ($visit['kind'] ?? null) === 'delivery' && $order->driver_assigned_uuid === $driver->uuid && $order->started ? $visit : null;
    }

    public static function claim(Driver $driver, array $visit): void
    {
        $locked = Driver::where('company_uuid', $driver->company_uuid)->where('uuid', $driver->uuid)->lockForUpdate()->firstOrFail();
        $active = self::active($locked);
        $same = $active && $active['kind'] === $visit['kind'] && $active['order_uuid'] === $visit['order_uuid']
            && ($active['stop_uuid'] ?? null) === ($visit['stop_uuid'] ?? null)
            && ($active['round_uuid'] ?? null) === ($visit['round_uuid'] ?? null);
        abort_if($active && !$same, 409, 'Finish the active visit or depot return before starting another visit.');
        $meta = $locked->meta ?? [];
        $meta['navigator_active_visit'] = $same ? $active : $visit + ['started_at' => now()->toISOString()];
        $locked->updateQuietly(['meta' => $meta]);
        $driver->meta = $meta;
    }

    public static function pickup(CollectionRound $round, array $stop): void
    {
        $driver = Driver::where('company_uuid', $round->company_uuid)->where('uuid', $round->driver_uuid)->firstOrFail();
        self::claim($driver, ['kind' => 'pickup', 'order_uuid' => $stop['order_uuid'], 'round_uuid' => $round->uuid, 'stop_uuid' => $stop['uuid']]);
    }

    public static function delivery(Driver $driver, Order $order): void
    {
        abort_unless($order->driver_assigned_uuid === $driver->uuid && $order->company_uuid === $driver->company_uuid, 404, 'Assigned delivery not found.');
        abort_if(in_array($order->status, self::TERMINAL_STATUSES), 409, 'This delivery is closed.');
        if (CollectionWorkflow::applies($order)) {
            $leg = CollectionDeliveryLeg::where('company_uuid', $driver->company_uuid)->where('driver_uuid', $driver->uuid)->where('order_uuid', $order->uuid)->first();
            abort_unless($leg && $leg->status === 'released', 409, 'Operations must release this depot handover before delivery can start.');
            foreach (data_get($leg->manifest, 'routing.stops', []) as $visit) {
                if (($visit['kind'] ?? 'pickup') === 'delivery' && $visit['order_uuid'] === $order->uuid) break;
                if (($visit['kind'] ?? 'pickup') === 'pickup') {
                    $pickup = Order::where('company_uuid', $driver->company_uuid)->where('uuid', $visit['order_uuid'])->first();
                    $round = CollectionRound::where('company_uuid', $driver->company_uuid)->where('driver_uuid', $driver->uuid)
                        ->where('uuid', data_get($pickup?->meta, 'collection_workflow.collection_round_uuid'))->first();
                    $stop = $round ? collect($round->stops)->firstWhere('order_uuid', $visit['order_uuid']) : null;
                    abort_if(!$stop || $stop['status'] === 'pending',
                        409, 'Complete the earlier pickup before starting this delivery.');
                } else {
                    $earlier = Order::where('company_uuid', $driver->company_uuid)->where('uuid', $visit['order_uuid'])->first();
                    abort_unless($earlier && in_array($earlier->status, self::TERMINAL_STATUSES),
                        409, 'Complete the earlier delivery before starting this one.');
                }
            }
        }
        self::claim($driver, ['kind' => 'delivery', 'order_uuid' => $order->uuid]);
    }
}
