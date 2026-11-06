<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Models\Driver;

class CollectionRoundMonitor
{
    public static function read(string $id): array
    {
        $company = CollectionConsoleAccess::authorize('read');
        $round = CollectionRound::where('company_uuid', $company)->where('uuid', $id)->firstOrFail();
        $driver = $round->driver_uuid
            ? Driver::where('company_uuid', $company)->where('uuid', $round->driver_uuid)->first()
            : null;
        $data = $round->toArray();
        unset($data['custody']['requests']);

        return [
            'round' => $data,
            'driver' => $driver ? [
                'uuid' => $driver->uuid,
                'name' => $driver->name,
                'phone' => $driver->phone,
                'online' => (bool) $driver->online,
                'status' => $driver->current_status ?: $driver->status,
                'location' => $driver->location,
                'position_at' => data_get($driver->meta, 'navigator_position_at'),
            ] : null,
            'observed_at' => now()->toISOString(),
        ];
    }
}
