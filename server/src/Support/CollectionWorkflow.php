<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\CollectionDeliveryLeg;
use Illuminate\Validation\ValidationException;

class CollectionWorkflow
{
    public const VERSION = 1;

    public const STATES = [
        'awaiting_preparation', 'ready_for_pickup', 'pickup_scheduled', 'pickup_in_progress',
        'collected', 'pickup_exception', 'depot_hold', 'received_at_depot', 'ready_for_delivery_dispatch', 'out_for_delivery', 'delivered', 'cancelled',
    ];

    public static function prepareForSave(Order $order): void
    {
        $meta = $order->meta ?? [];
        $original = $order->getOriginal('meta') ?? [];
        if (!$order->isRecordingCollectionReadiness() && (array_key_exists('collection_workflow', $meta) !== array_key_exists('collection_workflow', $original)
            || data_get($meta, 'collection_workflow') !== data_get($original, 'collection_workflow'))) {
            throw ValidationException::withMessages(['meta.collection_workflow' => 'Collection state is managed by the collection workflow, not order edits.']);
        }

        if (!$order->exists && config('fleetops.collection_workflow.enabled', false)
            && data_get($meta, 'delivery.purpose') === 'transportation'
            && data_get($meta, 'delivery.service_level') === 'standard') {
            data_set($meta, 'collection_workflow', [
                'version' => self::VERSION,
                'state' => 'awaiting_preparation',
                'ready_at' => null,
                'planned_collection_at' => null,
                'depot_received_at' => null,
                'planned_delivery_date' => null,
                'collection_round_uuid' => null,
                'delivery_run_uuid' => null,
                'history' => [['state' => 'awaiting_preparation', 'at' => now()->toISOString(), 'actor_uuid' => session('user')]],
            ]);
            $order->meta = $meta;
        }

        if (self::applies($order)) {
            if ($order->exists && ($order->isDirty(['scheduled_at', 'notes', 'internal_id', 'pod_required', 'pod_method', 'purchase_rate_uuid'])
                || ($order->isDirty('payload_uuid') && !self::canDispatch($order)))) {
                throw ValidationException::withMessages(['collection_workflow' => 'Collection order changes require operations review and a new quote where applicable. Do not edit the existing delivery contract.']);
            }
            if ($order->exists && data_get($meta, 'delivery') !== data_get($original, 'delivery')) {
                throw ValidationException::withMessages(['meta.delivery' => 'An enrolled collection order cannot change its delivery contract through order edits.']);
            }
            if ($order->isDirty('status')) {
                if ($order->exists && in_array(strtolower((string) $order->status), ['canceled', 'cancelled', 'order_canceled'], true)) {
                    $beforeCancellation = clone $order;
                    $beforeCancellation->status = $order->getOriginal('status');
                    CollectionReadiness::assertEditable($beforeCancellation);
                }
                self::assertCanProgress($order, (string) $order->status);
                if ($order->exists && in_array(strtolower((string) $order->status), ['completed', 'delivered', 'done', 'order_completed'], true)) {
                    $updated = $order->meta;
                    $updated['collection_workflow']['state'] = 'delivered';
                    $updated['collection_workflow']['history'][] = ['state' => 'delivered', 'at' => now()->toISOString(), 'actor_uuid' => session('user')];
                    $order->meta = $updated;
                }
                if ($order->exists && in_array(strtolower((string) $order->status), ['canceled', 'cancelled', 'order_canceled'], true)
                    && data_get($order->meta, 'collection_workflow.state') !== 'cancelled') {
                    $updated = $order->meta;
                    $updated['collection_workflow']['state'] = 'cancelled';
                    $updated['collection_workflow']['history'][] = ['state' => 'cancelled', 'at' => now()->toISOString(), 'actor_uuid' => session('user')];
                    $order->meta = $updated;
                }
            }
            if ($order->dispatched || $order->dispatched_at || $order->started || $order->started_at || $order->driver_assigned_uuid) {
                self::assertCanDispatch($order);
            }
        }
    }

    public static function applies(Order $order): bool
    {
        return array_key_exists('collection_workflow', $order->meta ?? [])
            || array_key_exists('collection_workflow', $order->getOriginal('meta') ?? []);
    }

    public static function canDispatch(Order $order): bool
    {
        if (!self::applies($order)) {
            return true;
        }
        $id = data_get($order->meta, 'collection_workflow.delivery_run_uuid');

        return $id && !$order->adhoc && CollectionDeliveryLeg::where('uuid', $id)->where('company_uuid', $order->company_uuid)
            ->where('order_uuid', $order->uuid)->where('status', 'released')
            ->where('driver_uuid', $order->driver_assigned_uuid)->where('payload_uuid', $order->payload_uuid)->exists();
    }

    public static function assertCanDispatch(Order $order): void
    {
        if (!self::canDispatch($order)) {
            throw ValidationException::withMessages(['collection_workflow' => 'This order requires the collection/depot workflow and cannot be dispatched as a direct delivery.']);
        }
    }

    public static function assertCanProgress(Order $order, string $code): void
    {
        if (self::applies($order) && !self::canDispatch($order) && !in_array(strtolower($code), ['created', 'pending', 'canceled', 'cancelled', 'order_canceled'], true)) {
            throw ValidationException::withMessages(['collection_workflow' => 'Use the collection workflow to advance this order; direct delivery activities are blocked.']);
        }
    }
}
