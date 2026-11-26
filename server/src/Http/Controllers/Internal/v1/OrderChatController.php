<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\OrderChatChannel;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

class OrderChatController extends Controller
{
    private function resolveOrder(string $id): Order
    {
        abort_unless(session('company') && session('user'), 403, 'Company session is required.');

        return Order::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();
    }

    public function show(string $id, Request $request)
    {
        $order = $this->resolveOrder($id);

        if (!$request->boolean('open') && !OrderChatChannel::isActiveForOrder($order)) {
            return response()->json([
                'active'       => false,
                'channel'      => null,
                'participants' => [],
                'messages'     => [],
            ]);
        }

        $channel = OrderChatChannel::ensureForOrder($order, null, null, (string) session('user'));

        return response()->json(OrderChatChannel::serializeChannel($channel, $order, (string) session('user')));
    }

    public function sendMessage(string $id, Request $request)
    {
        $order  = $this->resolveOrder($id);
        $result = OrderChatChannel::sendMessage($order, (string) session('user'), $request);

        return response()->json(['message' => $result['message']] + $result['payload']);
    }
}
