<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;

class CollectionCapacity
{
    public static function parcels(array $stops): int
    {
        $orders = [];
        foreach ($stops as $stop) {
            $orders[$stop['order_uuid']] = array_sum(array_column($stop['order_parcels'] ?? [], 'quantity'));
        }

        return array_sum($orders);
    }

    public static function driverLimit(Driver $driver, array $schedule): ?int
    {
        $vehicle = $driver->vehicle_uuid ? Vehicle::where('company_uuid', $driver->company_uuid)->where('uuid', $driver->vehicle_uuid)->first() : null;
        $limit = $vehicle?->payload_capacity_parcels;
        if ($limit === null || $limit <= 0) {
            $limit = $schedule['fallback_parcel_capacity'] ?? null;
        }

        return $limit !== null && $limit > 0 ? (int) $limit : null;
    }

    public static function candidates(string $company, array $schedule): array
    {
        return Driver::where('company_uuid', $company)->get()->map(fn ($driver) => [
            'uuid' => $driver->uuid, 'name' => $driver->name, 'parcel_capacity' => self::driverLimit($driver, $schedule),
        ])->filter(fn ($driver) => $driver['parcel_capacity'] !== null)->sortByDesc('parcel_capacity')->values()->all();
    }

    public static function assertAssignment(CollectionRound $round, Driver $driver, array $schedule): void
    {
        $conflict = CollectionRound::where('company_uuid', $round->company_uuid)->where('driver_uuid', $driver->uuid)
            ->where('uuid', '!=', $round->uuid)->where('scheduled_at', $round->scheduled_at)
            ->whereIn('status', ['draft', 'released', 'in_progress', 'awaiting_depot'])->exists();
        abort_if($conflict, 409, 'This driver already has a round in this pickup slot. Assign another driver.');
        if (!$schedule['capacity_planning']) {
            return;
        }
        $capacity = self::driverLimit($driver, $schedule);
        abort_unless($capacity, 422, 'Set the vehicle parcel capacity or a staff fallback capacity before assigning this driver.');
        abort_unless(self::parcels($round->stops) <= $capacity && count($round->stops) <= $schedule['max_pickup_stops'],
            422, 'This round exceeds the driver parcel capacity or pickup-stop limit. Re-plan before assigning.');
    }

    public static function nearby(array $stops): array
    {
        if (count($stops) < 2) {
            return $stops;
        }
        $ordered = [array_shift($stops)];
        while ($stops) {
            $origin = end($ordered)['location'] ?? null;
            usort($stops, function ($a, $b) use ($origin) {
                if (!$origin) {
                    return strcmp($a['uuid'], $b['uuid']);
                }
                $distance = fn ($stop) => isset($stop['location'])
                    ? Utils::vincentyGreatCircleDistance(Utils::getPointFromMixed($origin), Utils::getPointFromMixed($stop['location']))
                    : INF;

                return ($distance($a) <=> $distance($b)) ?: strcmp($a['uuid'], $b['uuid']);
            });
            $ordered[] = array_shift($stops);
        }

        return $ordered;
    }
}
