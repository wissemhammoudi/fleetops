<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\AssignedDriverOrder;
use Fleetbase\FleetOps\Support\PackagingInspection;
use Fleetbase\FleetOps\Support\RecipientHandoff;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecipientHandoffController extends Controller
{
    public function show(string $id)
    {
        $order = AssignedDriverOrder::find($id);

        return response()->json([
            'stage' => RecipientHandoff::stage($order),
            'handoff_required' => (bool) (data_get($order->meta, 'recipient_handoff_policy_revision') || data_get($order->meta, 'recipient_handoff.latest')),
            'opening_allowed' => (bool) data_get($order->meta, 'delivery.opening_allowed', false),
            'handoff' => data_get($order->meta, 'recipient_handoff.latest'),
            'acceptance_current' => RecipientHandoff::hasCurrentAcceptance($order),
        ]);
    }

    public function store(string $id, Request $request)
    {
        $assigned = AssignedDriverOrder::find($id);
        $handoff = DB::connection($assigned->getConnectionName())->transaction(function () use ($assigned, $request) {
            $order = Order::where('company_uuid', $assigned->company_uuid)
                ->where('driver_assigned_uuid', $assigned->driver_assigned_uuid)
                ->where('uuid', $assigned->uuid)->lockForUpdate()->firstOrFail();
            abort_if(in_array($order->status, ['completed', 'done', 'delivered', 'canceled', 'cancelled'], true), 422, 'Finished orders cannot record a handoff.');
            $stage = RecipientHandoff::stage($order);
            $request->validate(['stop_token' => 'required|string']);
            abort_unless(hash_equals($stage['stop_token'], $request->input('stop_token')), 409, 'The delivery stop changed. Reload recipient handoff before recording a decision.');
            RecipientHandoff::assertRecipientStage($order);
            PackagingInspection::assertCanProgress($order, 'picked_up');
            $result = RecipientHandoff::result($order, $request->all());
            $order->recordRecipientHandoff($result);
            $order->save();

            return $result;
        });

        return response()->json(['handoff' => $handoff]);
    }
}
