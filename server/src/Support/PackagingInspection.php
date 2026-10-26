<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Entity;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PackagingInspection
{
    public static function fingerprint(Order $order): string
    {
        return hash('sha256', json_encode([
            'payload' => $order->payload_uuid,
            'parcels' => $order->payload_uuid ? Entity::where('payload_uuid', $order->payload_uuid)->orderBy('uuid')->get()->map(fn ($entity) => [
                'uuid' => $entity->uuid,
                'name' => $entity->name,
                'weight' => $entity->weight,
                'weight_unit' => $entity->weight_unit,
                'dimensions' => [$entity->length, $entity->width, $entity->height],
                'dimensions_unit' => $entity->dimensions_unit,
                'classification' => data_get($entity->meta, 'parcel'),
            ])->all() : [],
        ], JSON_THROW_ON_ERROR));
    }

    public static function result(array $input, string $driverUuid, string $fingerprint): array
    {
        $rules = ['checks' => ['required', 'array'], 'reason' => ['nullable', 'string', 'max:1000']];
        foreach (DeliveryOptions::driverChecks() as $check) {
            $rules['checks.' . $check['key']] = ['required', 'boolean'];
        }
        $validated = Validator::make($input, $rules)->validate();
        $checks = [];
        foreach (DeliveryOptions::driverChecks() as $check) {
            $checks[$check['key']] = (bool) $validated['checks'][$check['key']];
        }
        $accepted = !in_array(false, $checks, true);
        $reason = trim($validated['reason'] ?? '');
        if (!$accepted && $reason === '') {
            throw ValidationException::withMessages(['reason' => 'Explain why pickup is refused and what the sender must correct.']);
        }

        return [
            'status' => $accepted ? 'accepted' : 'refused',
            'checks' => $checks,
            'reason' => $reason,
            'driver_uuid' => $driverUuid,
            'guide_revision' => DeliveryOptions::REVISION,
            'fingerprint' => $fingerprint,
            'checked_at' => now()->toISOString(),
        ];
    }

    public static function assertCanProgress(Order $order, string $code): void
    {
        if (!self::canProgress($order, $code)) {
            throw ValidationException::withMessages([
                'packaging' => 'Pickup is blocked. The assigned driver must check every parcel and record compliant packaging. Repackage refused parcels and submit a new check before continuing.',
            ]);
        }
    }

    public static function canProgress(Order $order, string $code): bool
    {
        if (in_array($code, ['created', 'pending', 'dispatched', 'started', 'arrived', 'arrived_at_pickup', 'arrived_at_dropoff', 'canceled', 'cancelled', 'order_canceled'], true)) {
            return true;
        }
        $current = $order->exists ? Order::findOrFail($order->uuid) : $order;
        $inspection = data_get($current->meta, 'packaging_inspection.latest');
        $previousStatus = $current->status;
        // Do not interrupt legacy goods already in transit; pending pickups still require a check.
        if (!data_get($current->meta, 'packaging_policy_revision') && !$inspection
            && in_array($previousStatus, ['picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'completed', 'done'], true)) {
            return true;
        }
        return data_get($inspection, 'status') === 'accepted'
            && data_get($inspection, 'driver_uuid') === $order->driver_assigned_uuid
            && $current->driver_assigned_uuid === $order->driver_assigned_uuid
            && $current->payload_uuid === $order->payload_uuid
            && data_get($inspection, 'guide_revision') === DeliveryOptions::REVISION
            && data_get($inspection, 'fingerprint') === self::fingerprint($order);
    }
}
