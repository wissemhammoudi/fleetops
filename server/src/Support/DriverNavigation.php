<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Models\CollectionDeliveryLeg;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    public static function claim(Driver $driver, array $visit, bool $switch = false): void
    {
        $locked = Driver::where('company_uuid', $driver->company_uuid)->where('uuid', $driver->uuid)->lockForUpdate()->firstOrFail();
        $active = self::active($locked);
        $same = $active && $active['kind'] === $visit['kind'] && $active['order_uuid'] === $visit['order_uuid']
            && ($active['stop_uuid'] ?? null) === ($visit['stop_uuid'] ?? null)
            && ($active['round_uuid'] ?? null) === ($visit['round_uuid'] ?? null);
        if ($active && !$same) {
            if ($switch) {
                self::pause($locked);
                $locked->refresh();
            } else {
                abort(409, 'Finish the active visit or depot return before starting another visit.');
            }
        }
        $meta = $locked->meta ?? [];
        $meta['navigator_active_visit'] = $same ? $active : $visit + ['started_at' => now()->toISOString()];
        unset($meta['navigator_last_paused_visit']);
        $locked->updateQuietly(['meta' => $meta]);
        $driver->meta = $meta;
    }

    public static function pause(Driver $driver): ?array
    {
        $locked = Driver::where('company_uuid', $driver->company_uuid)->where('uuid', $driver->uuid)->lockForUpdate()->firstOrFail();
        $active = self::active($locked);
        $meta = $locked->meta ?? [];
        if (!$active) {
            if (array_key_exists('navigator_active_visit', $meta)) {
                unset($meta['navigator_active_visit']);
                $locked->updateQuietly(['meta' => $meta]);
                $driver->meta = $meta;
            }
            return null;
        }
        unset($meta['navigator_active_visit']);
        $meta['navigator_last_paused_visit'] = $active + ['paused_at' => now()->toISOString()];
        $currentJobUuid = $locked->current_job_uuid;

        if (($active['kind'] ?? null) === 'pickup' && !empty($active['round_uuid'])) {
            $round = CollectionRound::where('company_uuid', $locked->company_uuid)->where('uuid', $active['round_uuid'])->lockForUpdate()->first();
            if ($round) {
                $routing = $round->routing ?? [];
                if (($routing['active_stop_uuid'] ?? null) === ($active['stop_uuid'] ?? null)) {
                    unset($routing['active_stop_uuid']);
                    $round->routing = $routing;
                }
                CollectionRounds::recordPauseAudit($round, ['stop_uuid' => $active['stop_uuid'] ?? null, 'driver_uuid' => $locked->uuid]);
            }
        } elseif (($active['kind'] ?? null) === 'depot' && !empty($active['round_uuid'])) {
            $round = CollectionRound::where('company_uuid', $locked->company_uuid)->where('uuid', $active['round_uuid'])->lockForUpdate()->first();
            if ($round) {
                $routing = $round->routing ?? [];
                unset($routing['depot_return_started_at']);
                $round->routing = $routing;
                CollectionRounds::recordPauseAudit($round, ['kind' => 'depot', 'driver_uuid' => $locked->uuid]);
            }
        } elseif (($active['kind'] ?? null) === 'delivery' && !empty($active['order_uuid'])) {
            if ($currentJobUuid === $active['order_uuid']) {
                $currentJobUuid = null;
            }
            $order = Order::where('company_uuid', $locked->company_uuid)->where('uuid', $active['order_uuid'])->first();
            if ($order) {
                $orderMeta = $order->meta ?? [];
                $orderMeta['driver_visit_paused_at'] = now()->toISOString();
                $order->updateQuietly(['meta' => $orderMeta]);
            }
        }

        $locked->updateQuietly(['meta' => $meta, 'current_job_uuid' => $currentJobUuid]);
        $driver->meta = $meta;
        $driver->current_job_uuid = $currentJobUuid;
        return $active;
    }

    public static function pickup(CollectionRound $round, array $stop, bool $switch = false): void
    {
        $driver = Driver::where('company_uuid', $round->company_uuid)->where('uuid', $round->driver_uuid)->firstOrFail();
        self::claim($driver, ['kind' => 'pickup', 'order_uuid' => $stop['order_uuid'], 'round_uuid' => $round->uuid, 'stop_uuid' => $stop['uuid']], $switch);
        try {
            $order = Order::where('company_uuid', $round->company_uuid)->where('uuid', $stop['order_uuid'])->first();
            if ($order) {
                OrderChatChannel::ensureForOrder($order, $driver, 'pickup', $driver->user_uuid);
            }
        } catch (\Throwable $e) {
            Log::warning('Unable to initialize pickup chat channel.', ['order_uuid' => $stop['order_uuid'], 'error' => $e->getMessage()]);
        }
    }

    public static function delivery(Driver $driver, Order $order, bool $switch = false): void
    {
        abort_unless($order->driver_assigned_uuid === $driver->uuid && $order->company_uuid === $driver->company_uuid, 404, 'Assigned delivery not found.');
        abort_if(in_array($order->status, self::TERMINAL_STATUSES), 409, 'This delivery is closed.');
        if (CollectionWorkflow::applies($order)) {
            $leg = CollectionDeliveryLeg::where('company_uuid', $driver->company_uuid)->where('driver_uuid', $driver->uuid)->where('order_uuid', $order->uuid)->first();
            abort_unless($leg && $leg->status === 'released', 409, 'Operations must release this depot handover before delivery can start.');
        }
        self::claim($driver, ['kind' => 'delivery', 'order_uuid' => $order->uuid], $switch);
        $driver->updateQuietly(['current_job_uuid' => $order->uuid]);
        $orderMeta = $order->meta ?? [];
        if (array_key_exists('driver_visit_paused_at', $orderMeta)) {
            unset($orderMeta['driver_visit_paused_at']);
            $order->updateQuietly(['meta' => $orderMeta]);
        }
        try {
            OrderChatChannel::ensureForOrder($order, $driver, 'delivery', $driver->user_uuid);
        } catch (\Throwable $e) {
            Log::warning('Unable to initialize delivery chat channel.', ['order_uuid' => $order->uuid, 'error' => $e->getMessage()]);
        }
    }
}
