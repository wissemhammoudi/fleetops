<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Arr;

class CollectionHandovers
{
    public static function report(CollectionRound $round, Order $order, array $stop): array
    {
        $driverName = $order->getConnection()->table('drivers')->join('users', 'users.uuid', '=', 'drivers.user_uuid')
            ->where('drivers.company_uuid', $round->company_uuid)->where('drivers.uuid', $round->driver_uuid)
            ->whereNull('drivers.deleted_at')->whereNull('users.deleted_at')->value('users.name');
        $handover = [
            'status' => 'awaiting_customer',
            'reported_at' => now()->toISOString(),
            'reported_by_uuid' => session('user'),
            'driver' => ['name' => $driverName],
            'parcels' => $stop['order_parcels'] ?? [],
            'contact' => Arr::only($stop['contact'] ?? [], ['name', 'street1', 'street2', 'city']),
        ];
        $stop['handover'] = $handover;
        $order->recordCollectionHandover(self::pickup($round, $stop), 'pickup_handover_reported');

        return $stop;
    }

    public static function preview(Order $order): array
    {
        // This projection is written atomically with the round, and never synthesized for legacy pickups.
        return ['pickups' => array_values(data_get($order->meta, 'collection_workflow.handovers', []))];
    }

    public static function decide(CollectionRound $round, Order $order, array $input): bool
    {
        $stops = $round->stops;
        $index = collect($stops)->search(fn ($stop) => $stop['uuid'] === $input['stop_uuid'] && $stop['order_uuid'] === $order->uuid);
        abort_if($index === false, 404, 'Pickup stop not found for this order.');
        $stop = $stops[$index];
        $handover = $stop['handover'] ?? null;
        abort_unless($stop['status'] === 'collected' && $handover && isset($handover['reported_at']), 409, 'The driver has not reported this pickup for customer confirmation.');
        $reason = trim($input['reason'] ?? '');
        abort_if($input['action'] === 'dispute' && $reason === '', 422, 'A reason is required to dispute the pickup.');
        $hash = hash('sha256', json_encode([$input['action'], $reason, session('user'), $order->customer_uuid], JSON_THROW_ON_ERROR));
        if (($handover['request_id'] ?? null) === $input['request_id']) {
            abort_unless(($handover['request_hash'] ?? null) === $hash, 409, 'This retry identifier was already used for a different customer decision.');

            return false;
        }
        abort_unless($handover['status'] === 'awaiting_customer', 409, 'This pickup already has a final customer decision. Refresh to see it.');
        $status = $input['action'] === 'confirm' ? 'confirmed' : 'disputed';
        $handover['status'] = $status;
        $handover[$status . '_at'] = now()->toISOString();
        $handover['customer_uuid'] = $order->customer_uuid;
        $handover['customer_actor_uuid'] = session('user');
        $handover['request_id'] = $input['request_id'];
        $handover['request_hash'] = $hash;
        if ($status === 'disputed') {
            $handover['reason'] = $reason;
        }
        $stops[$index]['handover'] = $handover;
        $round->stops = $stops;
        $order->recordCollectionHandover(self::pickup($round, $stops[$index]), 'pickup_handover_' . $status);
        $order->save();

        return true;
    }

    private static function pickup(CollectionRound $round, array $stop): array
    {
        return [
            'round_uuid' => $round->uuid,
            'stop_uuid' => $stop['uuid'],
            'order_id' => $stop['order_id'] ?? $stop['order_uuid'],
        ] + Arr::only($stop['handover'], ['status', 'reported_at', 'confirmed_at', 'disputed_at', 'customer_uuid', 'driver', 'parcels', 'contact', 'reason']);
    }
}
