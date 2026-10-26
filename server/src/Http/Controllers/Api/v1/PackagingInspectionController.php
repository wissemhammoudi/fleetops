<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\DeliveryOptions;
use Fleetbase\FleetOps\Support\PackagingInspection;
use Fleetbase\FleetOps\Support\AssignedDriverOrder;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PackagingInspectionController extends Controller
{
    public function show(string $id)
    {
        $order = AssignedDriverOrder::find($id);

        return response()->json(['catalogue' => DeliveryOptions::catalogue(), 'inspection' => data_get($order->meta, 'packaging_inspection.latest')]);
    }

    public function store(string $id, Request $request)
    {
        $assigned = AssignedDriverOrder::find($id);
        $inspection = DB::connection($assigned->getConnectionName())->transaction(function () use ($assigned, $request) {
            $order = Order::where('company_uuid', $assigned->company_uuid)
                ->where('driver_assigned_uuid', $assigned->driver_assigned_uuid)
                ->where('uuid', $assigned->uuid)->lockForUpdate()->firstOrFail();
            abort_if(in_array($order->status, ['completed', 'done', 'canceled', 'cancelled'], true), 422, 'Finished orders cannot be inspected.');
            $result = PackagingInspection::result($request->all(), $order->driver_assigned_uuid, PackagingInspection::fingerprint($order));
            $order->recordPackagingInspection($result);
            $order->save();

            return $result;
        });

        return response()->json(['inspection' => $inspection]);
    }
}
