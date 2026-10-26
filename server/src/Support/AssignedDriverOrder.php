<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;

class AssignedDriverOrder
{
    public static function find(string $id): Order
    {
        abort_unless(session('company') && session('user'), 403, 'Driver authentication is required.');
        $driver = Driver::where('company_uuid', session('company'))->where('user_uuid', session('user'))->first();
        abort_unless($driver, 403, 'Only the assigned driver can record this check.');

        return Order::where('company_uuid', session('company'))
            ->where('driver_assigned_uuid', $driver->uuid)
            ->where(fn ($query) => $query->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();
    }
}
