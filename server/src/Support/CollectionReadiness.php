<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Carbon;

class CollectionReadiness
{
    public static function assertEditable(Order $order): void
    {
        abort_unless(CollectionWorkflow::applies($order), 409, 'This order does not use scheduled collection.');
        abort_unless(data_get($order->meta, 'collection_workflow.version') === CollectionWorkflow::VERSION, 409, 'This collection workflow version is not supported.');
        abort_unless(in_array($order->status, ['created', 'pending'], true)
            && !$order->dispatched && !$order->started && !$order->driver_assigned_uuid
            && !data_get($order->meta, 'collection_workflow.collection_round_uuid')
            && in_array(data_get($order->meta, 'collection_workflow.state'), ['awaiting_preparation', 'ready_for_pickup'], true),
            409, 'Collection is already planned, released or closed. Contact operations to change this order.');
    }

    public static function preview(Order $order): array
    {
        self::assertEditable($order);
        $schedule = CollectionSchedule::forCompany((string) $order->company_uuid);
        $earliest = now();
        if ($order->scheduled_at) {
            $scheduledDate = Carbon::parse($order->scheduled_at)->setTimezone($schedule['timezone'])->startOfDay();
            if ($scheduledDate->greaterThan($earliest)) {
                $earliest = $scheduledDate;
            }
        }
        $planned = CollectionSchedule::pickupAt($earliest, $schedule)->toISOString();
        $fingerprint = self::fingerprint($order);
        $snapshot = self::snapshot($order);

        return [
            'state' => data_get($order->meta, 'collection_workflow.state'),
            'planned_collection_at' => $planned,
            'timezone' => $schedule['timezone'],
            'pickup_stops' => array_column(self::pickupStops($order), 'contact'),
            'parcel_count' => array_sum(array_map(fn ($entity) => $entity->quantity ?? 1, $snapshot['entities'])),
            'parcels' => array_map(fn ($entity) => ['name' => $entity->name, 'quantity' => $entity->quantity ?? 1], $snapshot['entities']),
            'confirmation_token' => hash('sha256', json_encode([$order->uuid, $fingerprint, $schedule, $planned, count(data_get($order->meta, 'collection_workflow.history', []))], JSON_THROW_ON_ERROR)),
            'message' => 'Confirm the pickup address, contact phone, parcel count and compliant packaging. This is a planned collection date, not a guaranteed appointment.',
        ];
    }

    public static function change(Order $order, bool $ready, ?string $token): array
    {
        self::assertEditable($order);
        $workflow = data_get($order->meta, 'collection_workflow');
        $target = $ready ? 'ready_for_pickup' : 'awaiting_preparation';
        if ($workflow['state'] === $target) {
            if ($ready) {
                abort_unless(hash_equals((string) ($workflow['confirmation_token'] ?? ''), (string) $token)
                    && ($workflow['content_fingerprint'] ?? '') === self::fingerprint($order),
                    409, 'The delivery changed. Withdraw readiness and review the current order before confirming again.');
            }
            return $workflow;
        }
        if ($ready) {
            $preview = self::preview($order);
            abort_unless($token && hash_equals($preview['confirmation_token'], $token), 409, 'The order, collection calendar or cutoff changed. Review the updated pickup date and confirm again.');
            $workflow['ready_at'] = now()->toISOString();
            $workflow['planned_collection_at'] = $preview['planned_collection_at'];
            $workflow['timezone'] = $preview['timezone'];
            $workflow['confirmation_token'] = $token;
            $workflow['content_fingerprint'] = self::fingerprint($order);
        } else {
            abort_unless($token && hash_equals((string) ($workflow['confirmation_token'] ?? ''), $token), 409, 'Readiness changed. Refresh the order before withdrawing.');
            foreach (['ready_at', 'planned_collection_at', 'confirmation_token', 'content_fingerprint', 'timezone'] as $field) {
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
        return hash('sha256', json_encode(self::snapshot($order), JSON_THROW_ON_ERROR));
    }

    public static function pickupStops(Order $order): array
    {
        $snapshot = self::snapshot($order);
        $places = collect($snapshot['places'])->keyBy('uuid');
        $stops = [];
        $parcels = array_map(fn ($entity) => ['name' => $entity->name, 'quantity' => $entity->quantity ?? 1], $snapshot['entities']);
        $append = function (string $key, string $place) use (&$stops, $places, $order, $parcels): void {
            $stops[] = [
                'uuid' => $order->uuid . ':' . $key,
                'order_uuid' => $order->uuid,
                'order_id' => $order->public_id ?? $order->uuid,
                'contact' => \Illuminate\Support\Arr::only((array) $places->get($place), ['name', 'street1', 'street2', 'city', 'phone']),
                'status' => 'pending',
                'order_parcels' => $parcels,
                'notes' => $order->notes,
            ];
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
