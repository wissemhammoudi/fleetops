<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\CollectionDeliveryLeg;
use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CollectionDelivery
{
    public static function change(string $company, string $orderId, array $input, ?string $driverUuid = null): CollectionDeliveryLeg
    {
        $input = Validator::make($input, [
            'action' => ['required', 'in:assign,load,accept,release,recall'],
            'revision' => ['required', 'integer', 'min:0'],
            'request_id' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'driver_uuid' => ['required_if:action,assign', 'string'],
            'barcode' => ['required_if:action,load', 'uuid'],
            'checks' => ['sometimes', 'array'],
            'checks.*' => ['boolean'],
            'manifest_verified' => ['required_if:action,accept', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        abort_unless($driverUuid ? $input['action'] === 'accept' : $input['action'] !== 'accept', 403, 'Driver acceptance and operations actions use separate authenticated endpoints.');
        $connection = DB::connection(config('fleetbase.connection.db', 'mysql'));

        return $connection->transaction(function () use ($company, $orderId, $input, $driverUuid, $connection) {
            abort_unless($connection->table('companies')->where('uuid', $company)->lockForUpdate()->first(['uuid']), 404, 'Company not found.');
            $order = Order::where('company_uuid', $company)->where('uuid', $orderId)->lockForUpdate()->firstOrFail();
            $leg = CollectionDeliveryLeg::where('company_uuid', $company)->where('order_uuid', $order->uuid)->lockForUpdate()->first();
            if ($driverUuid) {
                abort_unless($leg && $leg->driver_uuid === $driverUuid, 404, 'Assigned delivery handover not found.');
            }
            $hash = hash('sha256', json_encode(\Illuminate\Support\Arr::except($input, ['revision', 'request_id']), JSON_THROW_ON_ERROR));
            $previous = $leg ? data_get($leg->manifest, 'requests.' . $input['request_id']) : null;
            if ($previous) {
                abort_unless(hash_equals($previous, $hash), 409, 'Retry identifier already used for a different delivery action.');

                return $leg;
            }
            abort_unless(($leg?->revision ?? 0) === (int) $input['revision'], 409, 'Delivery handover changed. Refresh before continuing.');
            $previousManifest = $leg ? \Illuminate\Support\Arr::except($leg->manifest, ['requests']) : null;
            $previousDriver = $leg?->driver_uuid;
            abort_unless(CollectionWorkflow::applies($order) && !$order->dispatched && !$order->started && !$order->driver_assigned_uuid, 409, 'Delivery is already released or this order does not use depot collection.');
            $round = CollectionRound::where('company_uuid', $company)->where('uuid', data_get($order->meta, 'collection_workflow.collection_round_uuid'))->lockForUpdate()->firstOrFail();
            if ($input['action'] === 'recall') {
                abort_unless($leg && $leg->status !== 'released', 409, 'Released goods cannot be recalled without a custody transfer.');
                abort_unless(trim($input['reason'] ?? '') !== '', 422, 'Explain the recall and confirm all staged goods remain at depot.');
                $leg->status = 'recalled';
            } else {
                self::assertReceipt($order, $round);
                if ($input['action'] === 'assign') {
                    abort_unless(!$leg || in_array($leg->status, ['staging', 'recalled', 'held'], true), 409, 'Recall an accepted handover before changing its driver. Goods must remain at depot.');
                    abort_unless(Driver::where('company_uuid', $company)->where('uuid', $input['driver_uuid'])->exists(), 422, 'Select an active driver from this company.');
                    $manifest = self::manifest($order, $round);
                    $manifest['requests'] = $leg ? data_get($leg->manifest, 'requests', []) : [];
                    $leg ??= new CollectionDeliveryLeg(['uuid' => (string) Str::uuid(), 'company_uuid' => $company,
                        'order_uuid' => $order->uuid, 'round_uuid' => $round->uuid, 'history' => [], 'revision' => 0]);
                    $leg->driver_uuid = $input['driver_uuid'];
                    $leg->manifest = $manifest;
                    $leg->status = 'staging';
                } else {
                    abort_unless($leg && in_array($leg->status, ['staging', 'accepted'], true), 409, 'Assign or refresh the depot handover first.');
                    $current = self::manifest($order, $round);
                    abort_unless($current['receipt_token'] === data_get($leg->manifest, 'receipt_token'), 409, 'Depot custody changed. Recall and prepare a fresh handover.');
                    $manifest = $leg->manifest;
                    if ($input['action'] === 'load') {
                        abort_unless($leg->status === 'staging', 409, 'Driver already accepted this manifest. Recall it before changing staged goods.');
                        $index = collect($manifest['units'])->search(fn ($unit) => $unit['barcode'] === $input['barcode']);
                        abort_if($index === false, 404, 'Parcel is not on this delivery handover.');
                        $manifest['units'][$index]['staged_at'] = now()->toISOString();
                        $manifest['units'][$index]['actor_uuid'] = session('user');
                    } else {
                        $allStaged = collect($manifest['units'])->isNotEmpty() && collect($manifest['units'])->every(fn ($unit) => !empty($unit['staged_at']));
                        if ($input['action'] === 'accept') {
                            abort_unless($leg->status === 'staging', 409, 'This handover is already accepted.');
                            $inspection = PackagingInspection::result($input, $driverUuid, PackagingInspection::fingerprint($order));
                            if (!$input['manifest_verified']) {
                                abort_unless(trim($input['reason'] ?? '') !== '', 422, 'Explain the missing or mismatched physical parcels.');
                                $inspection['status'] = 'refused';
                                $inspection['reason'] = trim($input['reason']);
                            }
                            $inspection['manifest_verified'] = (bool) $input['manifest_verified'];
                            abort_unless($inspection['status'] === 'refused' || $allStaged, 409, 'Staff must scan every physical parcel into the staging area before driver acceptance.');
                            $manifest['acceptance'] = $inspection;
                            $leg->status = $inspection['status'] === 'accepted' ? 'accepted' : 'held';
                            if ($leg->status === 'held') {
                                $custody = $round->custody;
                                $custody['receipts'][$order->uuid]['state'] = 'held';
                                $custody['receipts'][$order->uuid]['reason'] = $inspection['reason'];
                                $round->custody = $custody;
                                $round->status = 'awaiting_depot';
                                $round->history = [...$round->history, ['event' => 'delivery_handover_refused', 'at' => now()->toISOString(), 'actor_uuid' => session('user'), 'reason' => $inspection['reason']]];
                                $round->revision++;
                                $round->save();
                                $order->recordDepotReceipt($round->uuid, false);
                                $order->save();
                            }
                        } elseif ($input['action'] === 'release') {
                            abort_unless($allStaged, 409, 'Every parcel must be staged before departure.');
                            abort_unless($leg->status === 'accepted' && data_get($manifest, 'acceptance.status') === 'accepted'
                                && data_get($manifest, 'acceptance.driver_uuid') === $leg->driver_uuid, 409, 'The assigned driver must accept this exact parcel manifest.');
                            abort_unless(CollectionDepot::eligible($order), 409, 'Delivery cannot depart before the next operating day after complete depot receipt, or while held.');
                            abort_unless(Driver::where('company_uuid', $company)->where('uuid', $leg->driver_uuid)->exists(), 409, 'Assigned driver is no longer available.');
                            abort_unless($order->config()?->getDispatchActivity(), 409, 'Configure the delivery dispatch activity before releasing depot goods.');
                            $leg->payload_uuid = self::deliveryPayload($order, $round, $manifest);
                            $leg->status = 'released';
                        }
                    }
                    $leg->manifest = $manifest;
                }
            }
            $manifest = $leg->manifest;
            $manifest['requests'][$input['request_id']] = $hash;
            $leg->manifest = $manifest;
            $audit = ['action' => $input['action'], 'driver_uuid' => $leg->driver_uuid,
                'barcode' => $input['barcode'] ?? null, 'at' => now()->toISOString(), 'actor_uuid' => session('user'), 'reason' => trim($input['reason'] ?? '')];
            if (in_array($input['action'], ['assign', 'recall'], true)) {
                $audit['previous_manifest'] = $previousManifest;
                $audit['previous_driver_uuid'] = $previousDriver;
            } elseif ($input['action'] === 'accept') {
                $audit['acceptance'] = $manifest['acceptance'];
            }
            $leg->history = [...$leg->history, $audit];
            $leg->revision++;
            $leg->save();
            if ($input['action'] === 'release') {
                $order->releaseCollectionDelivery($leg);
                $order->save();
                $order->dispatchWithActivity();
            }

            return $leg;
        }, 3);
    }

    public static function assertReceipt(Order $order, CollectionRound $round): void
    {
        $units = collect(data_get($round->custody, 'units', []))->where('order_uuid', $order->uuid);
        abort_unless(data_get($order->meta, 'collection_workflow.state') === 'received_at_depot'
            && data_get($round->custody, 'receipts.' . $order->uuid . '.state') === 'received'
            && $units->isNotEmpty() && $units->every(fn ($unit) => $unit['condition'] === 'good' && $unit['received_at'])
            && collect($round->stops)->where('order_uuid', $order->uuid)->every(fn ($stop) => $stop['status'] === 'collected')
            && data_get($order->meta, 'collection_workflow.content_fingerprint') === CollectionReadiness::fingerprint($order),
            409, 'Every parcel must be received in good condition, all pickups collected and the depot hold resolved before delivery handover.');
    }

    private static function manifest(Order $order, CollectionRound $round): array
    {
        $connection = $order->getConnection();
        $payload = $connection->table('payloads')->where('uuid', $order->payload_uuid)->first();
        $waypoints = $connection->table('waypoints')->where('payload_uuid', $order->payload_uuid)->whereNull('deleted_at')->orderBy('order')->get();
        $ids = [];
        foreach ($waypoints as $index => $waypoint) {
            if (($waypoint->type ?? ($index === 0 && !$payload->pickup_uuid ? 'pickup' : 'dropoff')) !== 'pickup') {
                $ids[] = $waypoint->place_uuid;
            }
        }
        if ($payload->dropoff_uuid) {
            $ids[] = $payload->dropoff_uuid;
        }
        abort_unless($ids, 409, 'This order has no recipient drop-off route.');
        $stops = array_map(fn ($id) => \Illuminate\Support\Arr::only((array) $connection->table('places')->where('uuid', $id)->whereNull('deleted_at')->first(),
            ['uuid', 'name', 'street1', 'street2', 'city', 'province', 'postal_code', 'country', 'phone']), $ids);
        $units = collect($round->custody['units'])->where('order_uuid', $order->uuid)->values()->all();

        return ['order_id' => $order->public_id, 'depot' => $round->depot, 'dropoffs' => $stops,
            'original_payload_uuid' => $order->payload_uuid, 'delivery_date' => data_get($order->meta, 'collection_workflow.planned_delivery_date'),
            'units' => $units, 'receipt_token' => hash('sha256', json_encode([$units, data_get($round->custody, 'receipts.' . $order->uuid), CollectionReadiness::fingerprint($order), $round->depot], JSON_THROW_ON_ERROR))];
    }

    public static function matchesParcelLabel(Order $order, Entity $entity, string $code): bool
    {
        if (!CollectionWorkflow::applies($order) || $entity->payload_uuid !== $order->payload_uuid || !CollectionWorkflow::canDispatch($order)) {
            return false;
        }
        $leg = CollectionDeliveryLeg::where('uuid', data_get($order->meta, 'collection_workflow.delivery_run_uuid'))
            ->where('company_uuid', $order->company_uuid)->where('order_uuid', $order->uuid)->firstOrFail();
        $source = data_get($leg->manifest, 'source_entities.' . $entity->uuid);
        if (!$source) {
            return false;
        }

        return $code === $source || collect($leg->manifest['units'])->contains(fn ($unit) => $unit['entity_uuid'] === $source && $unit['barcode'] === $code);
    }

    private static function deliveryPayload(Order $order, CollectionRound $round, array &$manifest): string
    {
        $connection = $order->getConnection();
        $original = (array) $connection->table('payloads')->where('uuid', $order->payload_uuid)->first();
        $depot = Place::where('company_uuid', $order->company_uuid)->where('uuid', $round->depot['uuid'])->first();
        abort_unless($depot, 409, 'The receiving depot address is no longer available.');
        $depot->fill(\Illuminate\Support\Arr::except($round->depot, ['uuid', 'location_wkt']));
        abort_unless(array_key_exists('location_wkt', $round->depot), 409, 'Depot location snapshot is missing. Operations must review this historical round before delivery release.');
        $depot->location = $round->depot['location_wkt'] ? Point::fromWKT($round->depot['location_wkt']) : null;
        $depotId = $depot->snapshotForDelivery()->uuid;
        $payload = $original;
        $payload['pickup_uuid'] = $depotId;
        $payload['dropoff_uuid'] = $original['dropoff_uuid'];
        $payload['return_uuid'] = null;
        $payload['current_waypoint_uuid'] = $depotId;
        foreach (['pickup_tracking_number_uuid', 'dropoff_tracking_number_uuid', 'return_tracking_number_uuid'] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = null;
            }
        }
        $meta = json_decode($original['meta'] ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
        unset($meta['service_stop']);
        $payload['meta'] = json_encode(array_merge($meta, ['collection_delivery' => true, 'original_payload_uuid' => $order->payload_uuid]), JSON_THROW_ON_ERROR);
        $uuid = self::copyRow($connection, 'payloads', $payload, ['public_id' => 'payload_' . Str::random(16)]);
        $waypoints = $connection->table('waypoints')->where('payload_uuid', $order->payload_uuid)->whereNull('deleted_at')->orderBy('order')->get();
        $sequence = 0;
        foreach ($waypoints as $index => $waypoint) {
            if (($waypoint->type ?? ($index === 0 && !$original['pickup_uuid'] ? 'pickup' : 'dropoff')) === 'pickup') {
                continue;
            }
            $row = (array) $waypoint;
            if (array_key_exists('tracking_number_uuid', $row)) {
                $row['tracking_number_uuid'] = null;
            }
            if (array_key_exists('type', $row)) {
                $row['type'] = 'dropoff';
            }
            $waypointUuid = self::copyRow($connection, 'waypoints', $row, ['payload_uuid' => $uuid, 'order' => ++$sequence, 'public_id' => 'waypoint_' . Str::random(16)]);
            $marker = Waypoint::findOrFail($waypointUuid);
            $tracking = TrackingNumber::insertGetUuid([], $marker);
            $connection->table('waypoints')->where('uuid', $waypointUuid)->update(['tracking_number_uuid' => $tracking]);
        }
        foreach ($connection->table('entities')->where('payload_uuid', $order->payload_uuid)->whereNull('deleted_at')->get() as $entity) {
            $row = (array) $entity;
            if (array_key_exists('tracking_number_uuid', $row)) {
                $row['tracking_number_uuid'] = null;
            }
            $entityUuid = self::copyRow($connection, 'entities', $row, ['payload_uuid' => $uuid, 'public_id' => 'entity_' . Str::random(16)]);
            $manifest['source_entities'][$entityUuid] = $entity->uuid;
            $tracking = TrackingNumber::insertGetUuid([], Entity::findOrFail($entityUuid));
            $connection->table('entities')->where('uuid', $entityUuid)->update(['tracking_number_uuid' => $tracking]);
        }

        return $uuid;
    }

    private static function copyRow($connection, string $table, array $row, array $changes): string
    {
        unset($row['id'], $row['_key'], $row['_import_id']);
        if (!array_key_exists('public_id', $row)) {
            unset($changes['public_id']);
        }
        $uuid = (string) Str::uuid();
        $row = array_merge($row, $changes, ['uuid' => $uuid]);
        if (array_key_exists('created_at', $row)) {
            $row['created_at'] = now();
        }
        if (array_key_exists('updated_at', $row)) {
            $row['updated_at'] = now();
        }
        $connection->table($table)->insert($row);

        return $uuid;
    }
}
