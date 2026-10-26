<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecipientHandoff
{
    use ResolvesOrderServiceStops;

    public static function stage(Order $order, bool $allowCompletedStop = false): array
    {
        return (new self())->resolveStage($order, $allowCompletedStop);
    }

    private function resolveStage(Order $order, bool $allowCompletedStop): array
    {
        $payload = $order->payload_uuid ? Payload::find($order->payload_uuid) : null;
        $stops = $payload ? $this->payloadServiceStops($payload) : collect();
        $usesStops = $payload && $this->payloadUsesServiceStopActivity($payload);
        $stop = $payload ? $this->payloadCurrentServiceStop($payload) : null;
        $finished = in_array($order->status, ['completed', 'done', 'delivered', 'order_completed'], true);
        $started = $order->started || $order->started_at || in_array($order->status, ['started', 'picked_up', 'in_transit', 'out_for_delivery', 'arrived_at_dropoff'], true);
        $active = ($allowCompletedStop && $finished)
            || ($started && !in_array($order->status, ['created', 'pending', 'dispatched', 'completed', 'done', 'delivered', 'order_completed', 'canceled', 'cancelled', 'order_canceled'], true));
        $reason = 'Start the delivery and complete pickup before recording recipient acceptance.';
        $eligible = false;
        if ($active && $stop) {
            if ($usesStops) {
                $key = data_get($payload->meta, 'service_stop.key');
                $matchingPlaces = $stops->filter(fn ($candidate) => data_get($candidate, 'place.uuid') === $payload->current_waypoint_uuid);
                $unambiguous = $matchingPlaces->count() === 1 || $key === ($stop['key'] ?? null);
                $earlier = $stops->filter(fn ($candidate) => $candidate['sequence'] < $stop['sequence']);
                $pickupComplete = $earlier->contains(fn ($candidate) => $candidate['type'] === 'pickup')
                    && $earlier->every(fn ($candidate) => $this->serviceStopIsComplete($order, $payload, $candidate, true));
                $recipientStop = $stop['type'] === 'dropoff' || ($stop['type'] === 'waypoint' && $payload->pickup_uuid);
                $eligible = $unambiguous && $recipientStop && $pickupComplete && ($allowCompletedStop || !$this->serviceStopIsComplete($order, $payload, $stop));
            } else {
                // Classic routes track pickup on the order, not a separate stop.
                $pickupComplete = in_array($order->status, ['picked_up', 'in_transit', 'out_for_delivery'], true);
                $pickupComplete = $pickupComplete || ($allowCompletedStop && $finished);
                if (!$pickupComplete && $order->tracking_number_uuid) {
                    $pickupComplete = \Fleetbase\FleetOps\Models\TrackingStatus::where('tracking_number_uuid', $order->tracking_number_uuid)
                        ->whereIn('code', ['PICKED_UP', 'IN_TRANSIT', 'OUT_FOR_DELIVERY'])->exists();
                }
                $currentKey = data_get($payload->meta, 'service_stop.key');
                $eligible = $pickupComplete && $payload->dropoff_uuid !== null && (!$currentKey || $currentKey === 'dropoff');
                if ($eligible) {
                    $stop = $stops->firstWhere('type', 'dropoff');
                }
            }
        }
        if ($active && !$eligible) {
            $reason = 'Recipient handoff is available only at the current drop-off after preceding stops, including pickup, are completed.';
        }
        $route = $stops->map(fn ($candidate) => [
            'key' => $candidate['key'], 'type' => $candidate['type'], 'place_uuid' => data_get($candidate, 'place.uuid'), 'sequence' => $candidate['sequence'],
            'address' => collect(['name', 'street1', 'street2', 'city', 'province', 'postal_code', 'country', 'phone'])
                ->mapWithKeys(fn ($field) => [$field => data_get($candidate, 'place.' . $field)])->all(),
            'location' => data_get($candidate, 'place.location') instanceof \Fleetbase\LaravelMysqlSpatial\Types\Point
                ? [data_get($candidate, 'place.location')->getLat(), data_get($candidate, 'place.location')->getLng()] : null,
        ])->all();
        $identity = [
            'route' => $route, 'key' => $stop['key'] ?? null,
            'current' => $payload?->current_waypoint_uuid,
            'visit' => data_get($payload?->meta, 'service_stop.visit', 0),
        ];

        return [
            'eligible' => $eligible, 'reason' => $eligible ? null : $reason,
            'stop_key' => $stop['key'] ?? null, 'stop_role' => ($stop['type'] ?? null) === 'waypoint' ? 'dropoff' : ($stop['type'] ?? null),
            'stop_token' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)),
        ];
    }

    public static function assertRecipientStage(Order $order, bool $allowCompletedStop = false): array
    {
        $stage = self::stage($order, $allowCompletedStop);
        if (!$stage['eligible']) {
            throw ValidationException::withMessages(['recipient_handoff' => $stage['reason']]);
        }

        return $stage;
    }

    public static function fingerprint(Order $order): string
    {
        $payload = $order->payload_uuid ? $order->getConnection()->table('payloads')->where('uuid', $order->payload_uuid)->first() : null;
        return hash('sha256', PackagingInspection::fingerprint($order) . json_encode([
            'opening_allowed' => (bool) data_get($order->meta, 'delivery.opening_allowed', false),
            'delivery' => data_get($order->meta, 'delivery'),
            'pickup' => data_get($payload, 'pickup_uuid'),
            'dropoff' => data_get($payload, 'dropoff_uuid'),
            'stop' => data_get($payload, 'current_waypoint_uuid'),
            'stage' => self::stage($order, true)['stop_token'],
        ], JSON_THROW_ON_ERROR));
    }

    public static function result(Order $order, array $input): array
    {
        $stage = self::assertRecipientStage($order);
        $validated = Validator::make($input, [
            'status' => ['required', Rule::in(['accepted', 'refused'])],
            'opened' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        if ($validated['opened'] && !data_get($order->meta, 'delivery.opening_allowed', false)) {
            throw ValidationException::withMessages(['opened' => 'The sender has not allowed opening. Do not open the parcel.']);
        }
        $reason = trim($validated['reason'] ?? '');
        if ($validated['status'] === 'refused' && $reason === '') {
            throw ValidationException::withMessages(['reason' => 'Record why the recipient refused the parcel. Arrange a return with operations; charges require an agreed policy.']);
        }

        return [
            'status' => $validated['status'], 'opened' => (bool) $validated['opened'],
            'stop_key' => $stage['stop_key'], 'stop_token' => $stage['stop_token'],
            'reason' => $reason, 'driver_uuid' => $order->driver_assigned_uuid,
            'fingerprint' => self::fingerprint($order), 'checked_at' => now()->toISOString(),
        ];
    }

    public static function assertCanComplete(Order $order, bool $entireOrder = true): void
    {
        $current = $order->exists ? Order::findOrFail($order->uuid) : $order;
        $result = data_get($current->meta, 'recipient_handoff.latest');
        if (!data_get($current->meta, 'recipient_handoff_policy_revision') && !$result) {
            return;
        }
        self::assertRecipientStage($current, true);
        if ($entireOrder && $current->payload_uuid) {
            $helper = new self();
            $payload = Payload::find($current->payload_uuid);
            if ($payload && $helper->payloadUsesServiceStopActivity($payload)) {
                $unfinished = $helper->payloadServiceStops($payload)->contains(fn ($candidate) =>
                    !$helper->serviceStopIsComplete($current, $payload, $candidate, true));
                if ($unfinished) {
                    throw ValidationException::withMessages(['recipient_handoff' => 'Complete all route stops before completing the delivery.']);
                }
            }
        }
        if ($current->driver_assigned_uuid !== $order->driver_assigned_uuid
            || $current->payload_uuid !== $order->payload_uuid
            || !self::hasCurrentAcceptance($current, true)
            || data_get($result, 'fingerprint') !== self::fingerprint($order)) {
            throw ValidationException::withMessages(['recipient_handoff' => 'Delivery completion is blocked. Record recipient acceptance first. If refused, do not collect COD or mark delivered; contact operations to arrange a return.']);
        }
    }

    public static function hasCurrentAcceptance(Order $order, bool $allowCompletedStop = false): bool
    {
        $result = data_get($order->meta, 'recipient_handoff.latest');

        return self::stage($order, $allowCompletedStop)['eligible']
            && PackagingInspection::canProgress($order, 'completed')
            && data_get($result, 'status') === 'accepted'
            && data_get($result, 'driver_uuid') === $order->driver_assigned_uuid
            && data_get($result, 'fingerprint') === self::fingerprint($order);
    }

    public static function assertManifestStop(Order $order, ?string $placeUuid, ?string $waypointUuid): void
    {
        if (!data_get($order->meta, 'recipient_handoff_policy_revision') && !data_get($order->meta, 'recipient_handoff.latest')) {
            return;
        }
        $helper = new self();
        $payload = $order->payload_uuid ? Payload::find($order->payload_uuid) : null;
        $stops = $payload ? $helper->payloadServiceStops($payload) : collect();
        $matches = $stops->filter(fn ($candidate) => data_get($candidate, 'place.uuid') === $placeUuid
            && (!$waypointUuid || data_get($candidate, 'waypoint.uuid') === $waypointUuid));
        $target = $matches->count() === 1 ? $matches->first() : null;
        $current = $payload ? $helper->payloadCurrentServiceStop($payload) : null;
        $usesStops = $payload && $helper->payloadUsesServiceStopActivity($payload);
        if (!$target || ($usesStops && ($target['key'] ?? null) !== ($current['key'] ?? null))) {
            throw ValidationException::withMessages(['recipient_handoff' => 'Only the current, unambiguous route stop can be completed. Reload the delivery destination.']);
        }
        if ($target['type'] === 'pickup') {
            return;
        }
        self::assertCanComplete($order, false);
    }
}
