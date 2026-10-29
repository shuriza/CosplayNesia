<?php

namespace App\Services;

use App\Jobs\SendNotificationEmail;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\NotificationCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Single write path for outbound notification channels.
 *
 * `enqueue` is called inside the domain transaction that also wrote the in-app notification, so the
 * outbox row commits or rolls back with the event it describes. The insert is idempotent through
 * the unique (channel, event_key) pair: a retried checkout that replays the same fan-out inserts
 * nothing the second time. The job is only dispatched after the transaction commits, so a worker
 * can never pick up a delivery whose domain row was rolled back.
 */
class NotificationChannel
{
    public function enqueue(UserNotification $notification): void
    {
        $this->queue(
            recipientId: $notification->recipient_id,
            type: $notification->type,
            // The in-app key is already namespaced and unique per domain event; the (channel,
            // event_key) unique index scopes it to this channel, so no second prefix is needed.
            eventKey: $notification->event_key,
            payload: $notification->payload ?? [],
            notificationId: $notification->id,
        );
    }

    /**
     * Record an outbound message for a domain event that has no in-app row (security events).
     *
     * @param  array<string, mixed>  $payload
     */
    public function queue(
        int $recipientId,
        string $type,
        string $eventKey,
        array $payload = [],
        ?int $notificationId = null,
    ): void {
        $category = NotificationCatalog::categoryFor($type);
        if ($category === null) {
            return;
        }

        $recipient = User::query()->find($recipientId);
        if ($recipient === null) {
            return;
        }

        $delivery = $this->record($recipient, $type, $category, $eventKey, $payload, $notificationId);
        if ($delivery === null) {
            return;
        }

        DB::afterCommit(fn () => SendNotificationEmail::dispatch($delivery->id));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(
        User $recipient,
        string $type,
        string $category,
        string $eventKey,
        array $payload,
        ?int $notificationId,
    ): ?NotificationDelivery {
        // A user who muted this category gets a suppressed row instead of no row: the decision is
        // recorded, so "did we send this?" is answerable without re-deriving preferences later.
        $allowed = app(NotificationPreferences::class)->allows(
            $recipient,
            NotificationCatalog::CHANNEL_EMAIL,
            $category,
        );

        $inserted = NotificationDelivery::query()->insertOrIgnore([[
            'recipient_id' => $recipient->id,
            'notification_id' => $notificationId,
            'channel' => NotificationCatalog::CHANNEL_EMAIL,
            'event_key' => $eventKey,
            'type' => $type,
            'category' => $category,
            'status' => $allowed ? NotificationDelivery::STATUS_PENDING : NotificationDelivery::STATUS_SUPPRESSED,
            'payload' => json_encode(
                $this->project($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
            'queued_at' => $allowed ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        if ($inserted === 0) {
            return null;
        }

        return NotificationDelivery::query()
            ->where('channel', NotificationCatalog::CHANNEL_EMAIL)
            ->where('event_key', $eventKey)
            ->first();
    }

    /**
     * Allowlist projection: only these keys can ever reach the outbox, so an email cannot grow a
     * field just because a caller added one to the notification payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function project(array $payload): array
    {
        return array_filter(
            array_intersect_key($payload, array_flip([
                'product_name', 'variant_label', 'seller_name', 'item_count', 'quantity', 'subtotal',
                'rating', 'rental_start_date', 'rental_end_date', 'preview', 'from_domain',
                'search_name', 'price',
            ])),
            fn ($value) => is_scalar($value),
        );
    }
}
