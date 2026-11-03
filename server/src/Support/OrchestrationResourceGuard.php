<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Orchestration\Support\OrchestrationPayloadBuilder;
use Illuminate\Support\Collection;

class OrchestrationResourceGuard extends OrchestrationPayloadBuilder
{
    public static function validate(Vehicle $vehicle, ?Driver $driver, Collection $orders): void
    {
        $required = $orders->flatMap(fn ($order) => $order->required_skills ?? [])->unique()->all();
        $skills = array_unique(array_merge($vehicle->skills ?? [], $driver?->skills ?? []));
        abort_if(count(array_diff($required, $skills)) > 0, 422, 'Assigned resources do not provide the required skills.');
        $capacity = static::buildVehicleCapacity($vehicle);
        $demand = [0, 0, 0, 0];
        foreach ($orders as $order) {
            foreach (static::computePayloadDemand($order) as $dimension => $amount) {
                $demand[$dimension] += $amount;
            }
        }
        foreach ($capacity as $dimension => $limit) {
            abort_if($limit > 0 && $demand[$dimension] > $limit, 422, 'Vehicle payload capacity exceeded.');
        }
    }
}
