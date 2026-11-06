<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Order;

class CollectionRouteProgress
{
    public static function forDriver(array $routing, string $company, string $driver): array
    {
        $ids = collect($routing['stops'] ?? [])->where('kind', 'delivery')->pluck('order_uuid');
        $orders = $ids->isEmpty() ? collect() : Order::where('company_uuid', $company)
            ->where('driver_assigned_uuid', $driver)->whereIn('uuid', $ids)->get()->keyBy('uuid');
        $routing['stops'] = array_map(function ($stop) use ($orders) {
            if (($stop['kind'] ?? 'pickup') === 'delivery') $stop['status'] = $orders->get($stop['order_uuid'])?->status ?? 'unavailable';
            return $stop;
        }, $routing['stops'] ?? []);

        return $routing;
    }
}
