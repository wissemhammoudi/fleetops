<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Order;

class CollectionReadiness
{
    public static function assertEditable(Order $order): void
    {
        abort_unless(CollectionWorkflow::applies($order), 409, 'This order does not use scheduled collection.');
        abort_unless(data_get($order->meta, 'collection_workflow.version') === CollectionWorkflow::VERSION, 409, 'This collection workflow version is not supported.');
        abort_unless(in_array($order->status, ['created', 'pending', 'awaiting_preparation', 'ready_for_pickup'], true)
            && !$order->dispatched && !$order->started && !$order->driver_assigned_uuid
            && !data_get($order->meta, 'collection_workflow.collection_round_uuid')
            && in_array(data_get($order->meta, 'collection_workflow.state'), ['awaiting_preparation', 'ready_for_pickup'], true),
            409, 'Collection is already planned, released or closed. Contact operations to change this order.');
    }

    public static function preview(Order $order, ?string $pickupDate = null): array
    {
        self::assertEditable($order);
        abort_if($pickupDate !== null, 422, 'Pickup dates are organized by the dispatcher.');
        $fingerprint = self::fingerprint($order);
        $snapshot = self::snapshot($order);

        return [
            'state' => data_get($order->meta, 'collection_workflow.state'),
            'scheduling_mode' => 'dispatcher',
            'planned_collection_at' => null,
            'pickup_stops' => array_column(self::pickupStops($order), 'contact'),
            'parcel_count' => array_sum(array_map(fn ($entity) => $entity->quantity ?? 1, $snapshot['entities'])),
            'parcels' => array_map(fn ($entity) => ['name' => $entity->name, 'quantity' => $entity->quantity ?? 1], $snapshot['entities']),
            'confirmation_token' => hash('sha256', json_encode([$order->uuid, $fingerprint, 'dispatcher', count(data_get($order->meta, 'collection_workflow.history', []))], JSON_THROW_ON_ERROR)),
            'message' => 'Confirm the pickup address, contact phone, parcel count and compliant packaging. The dispatcher will organize collection after readiness is confirmed.',
        ];
    }

    public static function change(Order $order, bool $ready, ?string $token, ?string $pickupStart = null): array
    {
        self::assertEditable($order);
        abort_if($pickupStart !== null, 422, 'Pickup windows are organized by the dispatcher.');
        $workflow = data_get($order->meta, 'collection_workflow');
        $target = $ready ? 'ready_for_pickup' : 'awaiting_preparation';
        if ($workflow['state'] === $target) {
            if ($ready) {
                abort_unless(hash_equals((string) ($workflow['confirmation_token'] ?? ''), (string) $token)
                    && ($workflow['content_fingerprint'] ?? '') === self::fingerprint($order)
                    && ($pickupStart === null || ($workflow['pickup_window']['start'] ?? null) === $pickupStart),
                    409, 'The delivery changed. Withdraw readiness and review the current order before confirming again.');
            }
            return $workflow;
        }
        if ($ready) {
            $preview = self::preview($order);
            abort_unless($token && hash_equals($preview['confirmation_token'], $token), 409, 'The order changed. Review the current parcels and pickup contacts before confirming again.');
            $workflow['ready_at'] = now()->toISOString();
            $workflow['scheduling_mode'] = 'dispatcher';
            $workflow['planned_collection_at'] = null;
            $workflow['pickup_window'] = null;
            $workflow['timezone'] = null;
            $workflow['confirmation_token'] = $token;
            $workflow['content_fingerprint'] = self::fingerprint($order);
        } else {
            abort_unless($token && hash_equals((string) ($workflow['confirmation_token'] ?? ''), $token), 409, 'Readiness changed. Refresh the order before withdrawing.');
            foreach (['ready_at', 'planned_collection_at', 'confirmation_token', 'content_fingerprint', 'timezone', 'pickup_window'] as $field) {
                $workflow[$field] = null;
            }
        }
        $workflow['state'] = $target;
        $workflow['history'][] = ['state' => $target, 'at' => now()->toISOString(), 'actor_uuid' => session('user')];

        return $workflow;
    }

    public static function assertReadyForPlanning(Order $order): void
    {
        self::assertEditable($order);
        abort_unless(data_get($order->meta, 'collection_workflow.state') === 'ready_for_pickup'
            && data_get($order->meta, 'collection_workflow.content_fingerprint') === self::fingerprint($order),
            409, 'Collection readiness is missing or stale. The customer must review and confirm the current delivery.');
    }

    public static function fingerprint(Order $order): string
    {
        $snapshot = self::snapshot($order);
        foreach ($snapshot['places'] as $place) {
            // Query-builder rows contain MySQL geometry bytes, not UTF-8 text.
            if (isset($place->location)) {
                $place->location = bin2hex($place->location);
            }
        }

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public static function pickupStops(Order $order): array
    {
        $snapshot = self::snapshot($order);
        $places = collect($snapshot['places'])->keyBy('uuid');
        $stops = [];
        $parcels = array_map(fn ($entity) => ['name' => $entity->name, 'quantity' => $entity->quantity ?? 1], $snapshot['entities']);
        $append = function (string $key, string $place) use (&$stops, $places, $order, $parcels): void {
            $row = [
                'uuid' => $order->uuid . ':' . $key,
                'order_uuid' => $order->uuid,
                'order_id' => $order->public_id ?? $order->uuid,
                'contact' => \Illuminate\Support\Arr::only((array) $places->get($place), ['name', 'street1', 'street2', 'city', 'phone']),
                'status' => 'pending',
                'order_parcels' => $parcels,
                'notes' => $order->notes,
            ];
            $wkt = $order->getConnection()->table('places')->where('uuid', $place)->selectRaw('ST_AsText(location) as point')->value('point');
            if ($wkt) {
                $point = \Fleetbase\LaravelMysqlSpatial\Types\Point::fromWKT($wkt);
                $row['location'] = ['latitude' => $point->getLat(), 'longitude' => $point->getLng()];
            }
            $stops[] = $row;
        };
        if ($snapshot['payload']->pickup_uuid) {
            $append('pickup', $snapshot['payload']->pickup_uuid);
        }
        foreach ($snapshot['waypoints'] as $index => $waypoint) {
            if (($waypoint->type ?? ($index === 0 && !$snapshot['payload']->pickup_uuid ? 'pickup' : 'dropoff')) === 'pickup') {
                $append($waypoint->uuid, $waypoint->place_uuid);
            }
        }
        abort_unless($stops, 409, 'This order has no pickup stop. Contact operations.');

        return $stops;
    }

    private static function snapshot(Order $order): array
    {
        $connection = $order->getConnection();
        $payload = $connection->table('payloads')->where('uuid', $order->payload_uuid)->whereNull('deleted_at')->first();
        abort_unless($payload, 409, 'This order has no collection payload. Contact operations.');
        $entities = $connection->table('entities')->where('payload_uuid', $order->payload_uuid)->whereNull('deleted_at')->orderBy('uuid')->get()->all();
        $waypoints = $connection->table('waypoints')->where('payload_uuid', $order->payload_uuid)->whereNull('deleted_at')->orderBy('order')->orderBy('uuid')->get()->all();
        $placeIds = array_filter([$payload->pickup_uuid, $payload->dropoff_uuid, $payload->return_uuid, ...array_column($waypoints, 'place_uuid')]);
        $places = $connection->table('places')->whereIn('uuid', $placeIds)->whereNull('deleted_at')->orderBy('uuid')->get()->all();
        abort_unless(count($places) === count(array_unique($placeIds)), 409, 'A delivery address is missing. Contact operations.');
        abort_unless(count($entities) > 0, 409, 'Add parcels before confirming collection readiness.');

        return [
            'order' => $order->only(['payload_uuid', 'scheduled_at', 'notes', 'internal_id', 'pod_required', 'pod_method', 'purchase_rate_uuid']),
            'delivery' => data_get($order->meta, 'delivery'),
            'payload' => $payload, 'entities' => $entities, 'waypoints' => $waypoints, 'places' => $places,
        ];
    }
}
