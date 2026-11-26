<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\CollectionRound;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\Models\ChatAttachment;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatLog;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\File;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OrderChatChannel
{
    private const MAX_IMAGE_BYTES = 10485760;

    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];

    public static function findForOrder(Order $order): ?ChatChannel
    {
        return ChatChannel::where('company_uuid', $order->company_uuid)
            ->where('meta->order_uuid', $order->uuid)
            ->first();
    }

    public static function isActiveForOrder(Order $order): bool
    {
        if (self::findForOrder($order) instanceof ChatChannel) {
            return true;
        }

        if ($order->driver_assigned_uuid || $order->started || $order->dispatched) {
            return true;
        }

        if (data_get($order->meta, 'collection_workflow.collection_round_uuid')) {
            return true;
        }

        $workflowState = data_get($order->meta, 'collection_workflow.state');

        return in_array($workflowState, ['pickup_scheduled', 'collected', 'at_depot', 'pickup_exception', 'in_progress'], true);
    }

    public static function inferStage(Order $order): string
    {
        if ($order->driver_assigned_uuid || $order->started) {
            return 'delivery';
        }

        if (data_get($order->meta, 'collection_workflow.collection_round_uuid')) {
            return 'pickup';
        }

        return 'order';
    }

    public static function ensureForOrder(Order $order, ?Driver $driver = null, ?string $stage = null, ?string $actorUserUuid = null): ChatChannel
    {
        $resolvedDriver   = $driver ?? self::resolveDriver($order);
        $customerUserIds  = self::resolveCustomerUserIds($order);
        $adminUserIds     = self::resolveAdminUserIds($order);
        $participantUuids = collect([
            $resolvedDriver?->user_uuid,
            ...$customerUserIds,
            ...$adminUserIds,
            $actorUserUuid,
        ])->filter(fn ($uuid) => is_string($uuid) && $uuid !== '')->unique()->values();

        $channel = self::findForOrder($order);
        $orderRef = $order->trackingNumber?->tracking_number ?: $order->public_id;
        $resolvedStage = $stage ?: ($channel ? data_get($channel->meta, 'stage') : null) ?: self::inferStage($order);

        if (!$channel) {
            $creatorUuid = $actorUserUuid
                ?: $resolvedDriver?->user_uuid
                ?: ($adminUserIds[0] ?? $customerUserIds[0] ?? null);

            $channel = ChatChannel::create([
                'company_uuid'    => $order->company_uuid,
                'created_by_uuid' => $creatorUuid,
                'name'            => 'Order ' . $orderRef,
                'meta'            => [
                    'kind'            => 'order_support',
                    'order_uuid'      => $order->uuid,
                    'order_public_id' => $order->public_id,
                    'tracking_number' => $order->trackingNumber?->tracking_number,
                    'stage'           => $resolvedStage,
                ],
            ]);

            if ($order->company_uuid && $channel->uuid) {
                ChatLog::create([
                    'company_uuid'      => $order->company_uuid,
                    'chat_channel_uuid' => $channel->uuid,
                    'event_type'        => 'started_chat',
                    'content'           => "Order {$orderRef} chat opened for driver, customer, and support.",
                    'subjects'          => [],
                    'status'            => 'complete',
                ]);
            }
        } elseif ($stage && data_get($channel->meta, 'stage') !== $stage) {
            $meta          = is_array($channel->meta) ? $channel->meta : [];
            $meta['stage'] = $stage;
            $channel->meta = $meta;
            $channel->save();
        }

        foreach ($participantUuids as $userUuid) {
            ChatParticipant::firstOrCreate([
                'company_uuid'      => $order->company_uuid,
                'chat_channel_uuid' => $channel->uuid,
                'user_uuid'         => $userUuid,
            ]);
        }

        return $channel->fresh(['participants.user', 'messages.sender.user', 'messages.attachments.file', 'messages.receipts']);
    }

    public static function sendMessage(Order $order, string $senderUserUuid, Request $request, ?Driver $driver = null): array
    {
        $content  = trim((string) $request->input('content', ''));
        $hasImage = $request->hasFile('image')
            || $request->hasFile('file')
            || filled($request->input('image_base64'))
            || filled($request->input('file_uuid'));

        abort_if($content === '' && !$hasImage, 422, 'Enter a message or attach an image.');

        $channel = self::ensureForOrder($order, $driver, null, $senderUserUuid);
        $sender  = ChatParticipant::firstOrCreate([
            'company_uuid'      => $order->company_uuid,
            'chat_channel_uuid' => $channel->uuid,
            'user_uuid'         => $senderUserUuid,
        ]);

        $message = ChatMessage::create([
            'company_uuid'      => $order->company_uuid,
            'chat_channel_uuid' => $channel->uuid,
            'sender_uuid'       => $sender->uuid,
            'content'           => $content,
        ]);

        if ($hasImage) {
            $attachment = self::storeImageAttachment($request, $channel, $sender, $message);
            if (!$attachment && $content === '') {
                $message->delete();
                abort(422, 'Attach a valid image file (PNG, JPG, WEBP, GIF, or HEIC up to 10 MB).');
            }
        }

        try {
            $message->notifyParticipants();
        } catch (\Throwable) {
            // Do not fail message delivery when notification channels are offline.
        }

        $channel->refresh();
        $message->load(['sender.user', 'attachments.file', 'receipts']);
        $roleMap = self::roleMapForOrder($order);

        return [
            'message' => self::serializeMessage($message, $roleMap, $senderUserUuid),
            'channel' => $channel,
            'payload' => self::serializeChannel($channel, $order, $senderUserUuid),
        ];
    }

    public static function storeImageAttachment(
        Request $request,
        ChatChannel $channel,
        ChatParticipant $sender,
        ?ChatMessage $message = null
    ): ?ChatAttachment {
        $fileRecord = null;
        $uploaded   = $request->file('image') ?? $request->file('file');
        $disk       = config('filesystems.default', 'local');
        $bucket     = config('filesystems.disks.' . $disk . '.bucket', config('filesystems.disks.s3.bucket'));

        if ($uploaded instanceof UploadedFile) {
            $request->validate([
                ($request->hasFile('image') ? 'image' : 'file') => ['required', 'file', 'mimes:jpeg,jpg,png,webp,gif,heic,heif', 'max:10240'],
            ]);
            $fileName   = File::randomFileNameFromRequest($request);
            $storedPath = $uploaded->storeAs('uploads/chat', $fileName, ['disk' => $disk]);
            if ($storedPath === false) {
                abort(500, 'Unable to store chat image.');
            }
            $fileRecord = File::createFromUpload(
                $uploaded,
                $storedPath,
                'chat_attachment',
                $uploaded->getSize(),
                $disk,
                $bucket
            );
            if ($sender->user_uuid && !$fileRecord->uploader_uuid) {
                $fileRecord->uploader_uuid = $sender->user_uuid;
                $fileRecord->save();
            }
        } elseif (filled($request->input('image_base64'))) {
            $rawBase64   = (string) $request->input('image_base64');
            $contentType = strtolower(trim((string) $request->input('image_content_type', '')));
            if (preg_match('/^data:([^;]+);base64,(.*)$/s', $rawBase64, $matches)) {
                $contentType = $contentType ?: strtolower(trim($matches[1]));
                $rawBase64   = $matches[2];
            }
            if ($contentType === '') {
                $contentType = 'image/jpeg';
            }
            $ext = self::ALLOWED_IMAGE_MIMES[$contentType] ?? null;
            abort_if(!$ext, 422, 'Unsupported image type.');

            $binary = base64_decode($rawBase64, true);
            abort_if($binary === false || strlen($binary) === 0 || strlen($binary) > self::MAX_IMAGE_BYTES, 422, 'Image payload is invalid or exceeds 10 MB.');

            $originalName = trim((string) $request->input('image_filename', ''));
            if ($originalName === '') {
                $originalName = 'chat-photo-' . time() . '.' . $ext;
            }
            $storedName = Str::uuid()->toString() . '.' . $ext;
            $storedPath = 'uploads/chat/' . $storedName;

            $written = Storage::disk($disk)->put($storedPath, $binary);
            abort_if(!$written, 500, 'Unable to store chat image.');

            $fileRecord = File::create([
                'company_uuid'      => $channel->company_uuid,
                'uploader_uuid'     => $sender->user_uuid,
                'disk'              => $disk,
                'bucket'            => $bucket,
                'path'              => $storedPath,
                'original_filename' => $originalName,
                'type'              => 'chat_attachment',
                'content_type'      => $contentType,
                'file_size'         => strlen($binary),
            ]);
        } elseif (filled($request->input('file_uuid'))) {
            $fileId     = (string) $request->input('file_uuid');
            $fileRecord = File::where('company_uuid', $channel->company_uuid)
                ->where(function ($query) use ($fileId) {
                    $query->where('uuid', $fileId)->orWhere('public_id', $fileId);
                })
                ->firstOrFail();
        }

        if (!$fileRecord instanceof File) {
            return null;
        }

        return ChatAttachment::create([
            'company_uuid'      => $channel->company_uuid,
            'chat_channel_uuid' => $channel->uuid,
            'chat_message_uuid' => $message?->uuid,
            'sender_uuid'       => $sender->uuid,
            'file_uuid'         => $fileRecord->uuid,
        ]);
    }

    public static function serializeChannel(ChatChannel $channel, Order $order, ?string $viewerUserUuid = null): array
    {
        $channel->loadMissing(['participants.user', 'messages.sender.user', 'messages.attachments.file', 'messages.receipts']);
        $roleMap = self::roleMapForOrder($order);

        $participants = $channel->participants->map(function (ChatParticipant $participant) use ($roleMap, $viewerUserUuid) {
            $userUuid = $participant->user_uuid;
            $user     = $participant->user;

            return [
                'id'         => $participant->public_id,
                'uuid'       => $participant->uuid,
                'user_uuid'  => $userUuid,
                'name'       => $user?->name ?? 'User',
                'avatar_url' => $user?->avatar_url ?? null,
                'role'       => $roleMap[$userUuid] ?? 'admin',
                'is_online'  => (bool) $participant->is_online,
                'is_me'      => $viewerUserUuid !== null && $userUuid === $viewerUserUuid,
            ];
        })->values()->all();

        $messages = $channel->messages()
            ->with(['sender.user', 'attachments.file', 'receipts'])
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn (ChatMessage $message) => self::serializeMessage($message, $roleMap, $viewerUserUuid))
            ->values()
            ->all();

        return [
            'active' => true,
            'channel' => [
                'id'              => $channel->public_id,
                'uuid'            => $channel->uuid,
                'name'            => $channel->title,
                'stage'           => data_get($channel->meta, 'stage', self::inferStage($order)),
                'order_uuid'      => $order->uuid,
                'order_public_id' => $order->public_id,
                'tracking_number' => $order->trackingNumber?->tracking_number,
            ],
            'participants' => $participants,
            'messages'     => $messages,
        ];
    }

    public static function serializeMessage(ChatMessage $message, array $roleMap, ?string $viewerUserUuid = null): array
    {
        $sender     = $message->sender;
        $senderUser = $sender?->user;
        $userUuid   = $sender?->user_uuid;

        return [
            'id'         => $message->public_id,
            'uuid'       => $message->uuid,
            'content'    => (string) ($message->content ?? ''),
            'created_at' => $message->created_at?->toISOString(),
            'sender'     => [
                'id'         => $sender?->public_id,
                'uuid'       => $sender?->uuid,
                'user_uuid'  => $userUuid,
                'name'       => $senderUser?->name ?? 'User',
                'avatar_url' => $senderUser?->avatar_url ?? null,
                'role'       => $roleMap[$userUuid] ?? 'admin',
                'is_me'      => $viewerUserUuid !== null && $userUuid === $viewerUserUuid,
            ],
            'attachments' => $message->attachments
                ->filter(fn (ChatAttachment $attachment) => $attachment->file instanceof File)
                ->map(fn (ChatAttachment $attachment) => [
                    'id'           => $attachment->public_id,
                    'uuid'         => $attachment->uuid,
                    'url'          => $attachment->file->url,
                    'filename'     => $attachment->file->original_filename,
                    'content_type' => $attachment->file->content_type,
                ])
                ->values()
                ->all(),
        ];
    }

    public static function resolveDriver(Order $order): ?Driver
    {
        if ($order->driver_assigned_uuid) {
            $driver = Driver::where('company_uuid', $order->company_uuid)
                ->where('uuid', $order->driver_assigned_uuid)
                ->first();
            if ($driver) {
                return $driver;
            }
        }

        $roundUuid = data_get($order->meta, 'collection_workflow.collection_round_uuid');
        if ($roundUuid) {
            $round = CollectionRound::where('company_uuid', $order->company_uuid)->where('uuid', $roundUuid)->first();
            if ($round?->driver_uuid) {
                return Driver::where('company_uuid', $order->company_uuid)->where('uuid', $round->driver_uuid)->first();
            }
        }

        return null;
    }

    public static function resolveCustomerUserIds(Order $order): array
    {
        $order->loadMissing('customer');
        $customer = $order->customer;

        if ($customer instanceof Contact && $customer->user_uuid) {
            return [$customer->user_uuid];
        }

        if ($customer instanceof Vendor) {
            return $customer->vendorPersonnel()
                ->where('status', 'active')
                ->with('contact')
                ->get()
                ->pluck('contact.user_uuid')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return [];
    }

    public static function resolveAdminUserIds(Order $order): array
    {
        $ownerUuid = Company::where('uuid', $order->company_uuid)->value('owner_uuid');
        $companyUserIds = CompanyUser::where('company_uuid', $order->company_uuid)
            ->where('status', 'active')
            ->pluck('user_uuid')
            ->filter();

        $staffUsers = User::whereIn('uuid', $companyUserIds)
            ->where(function ($query) {
                $query->whereNull('type')->orWhereNotIn('type', User::MANAGED_TYPES);
            })
            ->limit(25)
            ->pluck('uuid')
            ->filter()
            ->all();

        return collect([$ownerUuid, ...$staffUsers])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public static function roleMapForOrder(Order $order): array
    {
        $map    = [];
        $driver = self::resolveDriver($order);
        if ($driver?->user_uuid) {
            $map[$driver->user_uuid] = 'driver';
        }

        foreach (self::resolveCustomerUserIds($order) as $customerUserUuid) {
            $map[$customerUserUuid] = 'customer';
        }

        foreach (self::resolveAdminUserIds($order) as $adminUserUuid) {
            if (!isset($map[$adminUserUuid])) {
                $map[$adminUserUuid] = 'admin';
            }
        }

        return $map;
    }
}
