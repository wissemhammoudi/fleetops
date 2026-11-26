<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\OrderChatChannel;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Resources\ChatChannel as ChatChannelResource;
use Fleetbase\Http\Resources\ChatMessage as ChatMessageResource;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatParticipant;
use Illuminate\Http\Request;

class OrderChatController extends Controller
{
    private function driver(): Driver
    {
        abort_unless(session('company') && session('user'), 403, 'Driver authentication is required.');

        return Driver::where('company_uuid', session('company'))
            ->where('user_uuid', session('user'))
            ->firstOrFail();
    }

    private function resolveDriverOrder(Driver $driver, string $id): Order
    {
        $order = Order::where('company_uuid', $driver->company_uuid)
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();

        $assignedDirectly = $order->driver_assigned_uuid === $driver->uuid;
        $roundUuid        = data_get($order->meta, 'collection_workflow.collection_round_uuid');
        $assignedToRound  = $roundUuid && CollectionRound::where('company_uuid', $driver->company_uuid)
            ->where('uuid', $roundUuid)
            ->where('driver_uuid', $driver->uuid)
            ->exists();

        abort_unless($assignedDirectly || $assignedToRound, 403, 'You are not assigned to this order.');

        return $order;
    }

    public function show(string $id)
    {
        $driver  = $this->driver();
        $order   = $this->resolveDriverOrder($driver, $id);
        $channel = OrderChatChannel::ensureForOrder($order, $driver, null, $driver->user_uuid);

        return response()->json(
            ['chat_channel' => (new ChatChannelResource($channel))->resolve()]
            + OrderChatChannel::serializeChannel($channel, $order, $driver->user_uuid)
        );
    }

    public function sendMessage(string $id, Request $request)
    {
        $driver = $this->driver();
        $order  = $this->resolveDriverOrder($driver, $id);
        $result = OrderChatChannel::sendMessage($order, (string) $driver->user_uuid, $request, $driver);

        return response()->json([
            'message'      => $result['message'],
            'chat_channel' => (new ChatChannelResource($result['channel']))->resolve(),
        ] + $result['payload']);
    }

    public function sendChannelMessage(string $channelId, Request $request)
    {
        abort_unless(session('company') && session('user'), 403, 'Authentication is required.');

        $channel = ChatChannel::where('company_uuid', session('company'))
            ->where(function ($query) use ($channelId) {
                $query->where('uuid', $channelId)->orWhere('public_id', $channelId);
            })
            ->firstOrFail();

        $participant = ChatParticipant::where('company_uuid', session('company'))
            ->where('chat_channel_uuid', $channel->uuid)
            ->where('user_uuid', session('user'))
            ->first();

        abort_unless($participant instanceof ChatParticipant, 403, 'You are not a participant in this channel.');

        $content  = trim((string) $request->input('content', ''));
        $hasImage = $request->hasFile('image')
            || $request->hasFile('file')
            || filled($request->input('image_base64'))
            || filled($request->input('file_uuid'));

        abort_if($content === '' && !$hasImage, 422, 'Enter a message or attach an image.');

        $message = ChatMessage::create([
            'company_uuid'      => session('company'),
            'chat_channel_uuid' => $channel->uuid,
            'sender_uuid'       => $participant->uuid,
            'content'           => $content,
        ]);

        if ($hasImage) {
            OrderChatChannel::storeImageAttachment($request, $channel, $participant, $message);
        }

        try {
            $message->notifyParticipants();
        } catch (\Throwable) {
            // Keep message created when push/notification delivery fails.
        }

        $message->load(['sender.user', 'attachments.file', 'receipts']);

        return new ChatMessageResource($message);
    }
}
