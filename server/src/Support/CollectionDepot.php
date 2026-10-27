<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CollectionDepot
{
    public static function prepare(CollectionRound $round, iterable $orders): void
    {
        $units = [];
        $receipts = [];
        foreach ($orders as $order) {
            $entities = $order->getConnection()->table('entities')->where('payload_uuid', $order->payload_uuid)
                ->whereNull('deleted_at')->orderBy('uuid')->get();
            abort_unless($entities->count(), 409, 'A pickup order has no parcels to label.');
            foreach ($entities as $entity) {
                $quantity = $entity->quantity ?? 1;
                abort_unless(filter_var($quantity, FILTER_VALIDATE_INT) !== false && $quantity >= 1, 409, 'Physical parcel quantities must be positive integers before release.');
                for ($number = 1; $number <= $quantity; $number++) {
                    $units[] = ['barcode' => (string) Str::uuid(), 'order_uuid' => $order->uuid, 'order_id' => $order->public_id,
                        'entity_uuid' => $entity->uuid, 'name' => $entity->name, 'number' => $number, 'quantity' => (int) $quantity,
                        'condition' => 'expected', 'received_at' => null];
                }
            }
            $receipts[$order->uuid] = ['order_id' => $order->public_id, 'state' => 'pending'];
        }
        $round->custody = ['units' => $units, 'receipts' => $receipts, 'requests' => []];
    }

    public static function change(string $company, string $id, array $input): CollectionRound
    {
        $input = Validator::make($input, [
            'action' => ['required', 'in:scan,reconcile,resolve'],
            'revision' => ['required', 'integer', 'min:1'],
            'request_id' => ['required', 'uuid'],
            'depot_uuid' => ['required', 'string'],
            'barcode' => ['required_if:action,scan', 'uuid'],
            'condition' => ['required_if:action,scan', 'in:good,damaged'],
            'order_uuid' => ['required_unless:action,scan', 'string'],
            'reason' => ['required_if:condition,damaged', 'required_if:action,resolve', 'nullable', 'string', 'max:1000'],
        ])->validate();
        $connection = DB::connection(config('fleetbase.connection.db', 'mysql'));

        return $connection->transaction(function () use ($connection, $company, $id, $input) {
            abort_unless($connection->table('companies')->where('uuid', $company)->lockForUpdate()->first(['uuid']), 404, 'Company not found.');
            $round = CollectionRound::where('company_uuid', $company)->where('uuid', $id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($round->status, ['awaiting_depot', 'received_at_depot'], true), 409, 'Finish the pickup round before depot receipt.');
            abort_unless(data_get($round->depot, 'uuid') === $input['depot_uuid'], 409, 'This round must be received at its assigned depot.');
            abort_unless($round->custody && count($round->custody['units']), 409, 'Physical parcel labels are missing. Operations must review this round; do not infer quantities.');
            $custody = $round->custody;
            $hash = hash('sha256', json_encode(\Illuminate\Support\Arr::except($input, ['revision', 'request_id']), JSON_THROW_ON_ERROR));
            $previous = $custody['requests'][$input['request_id']] ?? null;
            if ($previous) {
                abort_unless(hash_equals($previous, $hash), 409, 'This retry identifier was already used for a different depot action.');

                return $round;
            }
            abort_unless($round->revision === (int) $input['revision'], 409, 'The depot manifest changed. Refresh before continuing.');
            $unitIndex = $input['action'] === 'scan' ? collect($custody['units'])->search(fn ($unit) => $unit['barcode'] === $input['barcode']) : false;
            if ($input['action'] === 'scan') {
                abort_if($unitIndex === false, 404, 'This physical parcel label is not on this round.');
            }
            $orderId = $input['action'] === 'scan' ? $custody['units'][$unitIndex]['order_uuid'] : $input['order_uuid'];
            abort_unless(isset($custody['receipts'][$orderId]), 404, 'This order is not on this depot manifest.');
            $order = Order::where('company_uuid', $company)->where('uuid', $orderId)->lockForUpdate()->firstOrFail();
            abort_unless(data_get($order->meta, 'collection_workflow.collection_round_uuid') === $round->uuid
                && in_array(data_get($order->meta, 'collection_workflow.state'), ['collected', 'pickup_exception', 'depot_hold', 'received_at_depot'], true)
                && !$order->dispatched && !$order->started && !$order->driver_assigned_uuid, 409, 'This order is not available for depot reconciliation.');
            abort_unless(data_get($order->meta, 'collection_workflow.content_fingerprint') === CollectionReadiness::fingerprint($order), 409,
                'The order contract changed after collection. Do not reconcile against stale parcel or address data.');
            $receipt = $custody['receipts'][$orderId];
            $before = null;
            if ($input['action'] === 'scan') {
                $before = $custody['units'][$unitIndex];
                abort_if($before['condition'] === 'damaged' && $input['condition'] === 'good' && !trim($input['reason'] ?? ''), 422, 'Explain the repair or replacement before recording a damaged parcel as good.');
                $custody['units'][$unitIndex] = array_merge($before, ['condition' => $input['condition'], 'received_at' => now()->toISOString(), 'actor_uuid' => session('user')]);
                if ($input['condition'] === 'damaged') {
                    $receipt['state'] = 'held';
                    $receipt['reason'] = trim($input['reason']);
                    $order->recordDepotReceipt($round->uuid, false);
                    $order->save();
                }
            } else {
                $units = collect($custody['units'])->where('order_uuid', $orderId);
                $allGood = $units->isNotEmpty() && $units->every(fn ($unit) => $unit['condition'] === 'good' && $unit['received_at']);
                $allCollected = collect($round->stops)->where('order_uuid', $orderId)->every(fn ($stop) => $stop['status'] === 'collected');
                if ($input['action'] === 'resolve') {
                    abort_unless($receipt['state'] === 'held' && $allGood && $allCollected, 409, 'Resolution requires every expected parcel received in good condition and every pickup collected. Partial custody cannot be waived.');
                }
                if (!$allGood || !$allCollected || ($receipt['state'] === 'held' && $input['action'] !== 'resolve')) {
                    $receipt['state'] = 'held';
                    $receipt['reason'] = !$allCollected ? 'Pickup collection is incomplete. Operations review is required.'
                        : (!$allGood ? 'Expected parcels are missing or damaged.' : 'An existing hold requires explicit operations resolution.');
                    $order->recordDepotReceipt($round->uuid, false);
                    $order->save();
                } elseif ($receipt['state'] !== 'received') {
                    $calendar = CollectionSchedule::forCompany($company);
                    $date = CollectionSchedule::deliveryDate(now(), $calendar);
                    $order->recordDepotReceipt($round->uuid, true, $date, $calendar['timezone']);
                    $order->save();
                    $receipt = ['order_id' => $receipt['order_id'], 'state' => 'received', 'at' => now()->toISOString(),
                        'delivery_date' => $date, 'timezone' => $calendar['timezone'], 'actor_uuid' => session('user')];
                }
            }
            $custody['receipts'][$orderId] = $receipt;
            $custody['requests'][$input['request_id']] = $hash;
            $round->custody = $custody;
            $collectedOrders = collect($round->stops)->where('status', 'collected')->pluck('order_uuid')->unique();
            $round->status = $collectedOrders->every(fn ($uuid) => ($custody['receipts'][$uuid]['state'] ?? '') === 'received')
                ? 'received_at_depot' : 'awaiting_depot';
            $round->history = [...$round->history, ['event' => 'depot_' . $input['action'], 'order_uuid' => $orderId,
                'barcode' => $input['barcode'] ?? null, 'condition' => $input['condition'] ?? null,
                'before' => $before, 'receipt_state' => $receipt['state'], 'reason' => trim($input['reason'] ?? ''),
                'at' => now()->toISOString(), 'actor_uuid' => session('user'), 'request_id' => $input['request_id']]];
            $round->revision++;
            $round->save();

            return $round;
        }, 3);
    }

    public static function eligible(Order $order): bool
    {
        if (data_get($order->meta, 'collection_workflow.state') !== 'received_at_depot' || $order->dispatched || $order->started || $order->driver_assigned_uuid) {
            return false;
        }
        $received = data_get($order->meta, 'collection_workflow.depot_received_at');
        if (!$received) {
            return false;
        }
        if (!in_array($order->status, ['created', 'pending'], true)
            || data_get($order->meta, 'collection_workflow.content_fingerprint') !== CollectionReadiness::fingerprint($order)) {
            return false;
        }
        $calendar = CollectionSchedule::forCompany((string) $order->company_uuid);
        $today = now()->setTimezone($calendar['timezone']);
        $earliest = CollectionSchedule::deliveryDate(\Illuminate\Support\Carbon::parse($received), $calendar);

        return $today->toDateString() >= max($earliest, (string) data_get($order->meta, 'collection_workflow.planned_delivery_date'))
            && in_array($today->dayOfWeekIso, $calendar['operating_days'], true)
            && !in_array($today->toDateString(), $calendar['closed_dates'], true);
    }
}
