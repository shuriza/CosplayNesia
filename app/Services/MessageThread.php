<?php

namespace App\Services;

use App\Models\FulfillmentMessage;
use App\Models\OrderFulfillment;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;

/**
 * Owns participation rules for a fulfillment conversation.
 *
 * Only the two parties bound to a fulfillment may read or write it: the buyer who owns the parent
 * order and the seller who owns the fulfillment. Everyone else receives 403, including sellers on
 * other fulfillments of the same order.
 */
class MessageThread
{
    public function __construct(private readonly NotificationRecorder $notifications) {}

    /**
     * @return array{0: OrderFulfillment, 1: string} the fulfillment and the viewer's role
     */
    public function participate(User $user, OrderFulfillment $fulfillment): array
    {
        $fulfillment->loadMissing('order');
        $role = match (true) {
            $fulfillment->seller_id === $user->id => FulfillmentMessage::ROLE_SELLER,
            $fulfillment->order?->user_id === $user->id => FulfillmentMessage::ROLE_BUYER,
            default => null,
        };
        abort_if($role === null, 403);

        return [$fulfillment, $role];
    }

    public function send(User $user, OrderFulfillment $fulfillment, string $body): FulfillmentMessage
    {
        [, $role] = $this->participate($user, $fulfillment);

        return DB::transaction(function () use ($user, $fulfillment, $body, $role): FulfillmentMessage {
            $locked = OrderFulfillment::query()
                ->whereKey($fulfillment->id)
                ->lockForUpdate()
                ->firstOrFail();

            // A cancelled fulfillment is a closed matter; reopening it by message would imply an
            // agreement the order can no longer honour.
            abort_if($locked->status === OrderFulfillment::STATUS_CANCELLED, 409, 'Pesanan yang dibatalkan tidak dapat menerima pesan baru.');

            $timestamp = now();
            $message = $locked->messages()->create([
                'sender_id' => $user->id,
                'sender_role' => $role,
                'body' => $body,
                'created_at' => $timestamp,
            ]);

            // Writing a message implies having read the thread up to this point.
            $locked->forceFill([$locked->readMarkerColumn($role) => $message->id])->save();

            $locked->loadMissing('order');
            $recipientId = $role === FulfillmentMessage::ROLE_SELLER
                ? $locked->order?->user_id
                : $locked->seller_id;

            $this->notifications->record([
                'recipient_id' => $recipientId,
                'actor_id' => $user->id,
                'order_id' => $locked->order_id,
                'fulfillment_id' => $locked->id,
                'type' => UserNotification::TYPE_MESSAGE_RECEIVED,
                'payload' => [
                    'sender_role' => $role,
                    'preview' => mb_substr($body, 0, 80),
                ],
                'event_key' => "notify:message:{$message->id}:received",
                'created_at' => $timestamp,
            ]);

            return $message;
        }, 3);
    }

    public function markRead(User $user, OrderFulfillment $fulfillment): OrderFulfillment
    {
        [, $role] = $this->participate($user, $fulfillment);

        return DB::transaction(function () use ($fulfillment, $role): OrderFulfillment {
            $locked = OrderFulfillment::query()
                ->whereKey($fulfillment->id)
                ->lockForUpdate()
                ->firstOrFail();
            // Advance to the newest message so every counterpart line up to now counts as read.
            $latestId = $locked->messages()->max('id');
            if ($latestId !== null) {
                $locked->forceFill([$locked->readMarkerColumn($role) => $latestId])->save();
            }

            return $locked->fresh();
        }, 3);
    }
}
