<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;

/**
 * Single write path for the notification feed.
 *
 * Every recorder call is idempotent through the unique event_key, so transaction retries and
 * replayed same-state mutations cannot duplicate a row. Self-directed notifications are dropped
 * because a user acting on their own order gains nothing from being told about it.
 */
class NotificationRecorder
{
    public function __construct(private readonly NotificationChannel $channel) {}

    public function record(array $attributes): void
    {
        $recipientId = $attributes['recipient_id'] ?? null;
        if ($recipientId === null) {
            return;
        }
        if ($recipientId === ($attributes['actor_id'] ?? null)) {
            return;
        }

        $attributes['created_at'] ??= now();
        if (array_key_exists('payload', $attributes) && is_array($attributes['payload'])) {
            $attributes['payload'] = json_encode($attributes['payload'], JSON_THROW_ON_ERROR);
        }

        $inserted = UserNotification::query()->insertOrIgnore([$attributes]);
        if ($inserted === 0) {
            return;
        }

        // Fan-out to outbound channels only when this call actually created the row, so a replayed
        // mutation cannot queue a second email for an event the user was already told about.
        $notification = UserNotification::query()->where('event_key', $attributes['event_key'])->first();
        if ($notification !== null) {
            $this->channel->enqueue($notification);
        }
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     */
    public function recordMany(iterable $rows): void
    {
        foreach ($rows as $row) {
            $this->record($row);
        }
    }

    public function markRead(User $user, int $notificationId): UserNotification
    {
        return DB::transaction(function () use ($user, $notificationId): UserNotification {
            $notification = UserNotification::query()
                ->whereKey($notificationId)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($notification->recipient_id === $user->id, 403);

            if ($notification->isUnread()) {
                $notification->update(['read_at' => now()]);
            }

            return $notification->fresh();
        }, 3);
    }

    public function markAllRead(User $user): int
    {
        return UserNotification::query()
            ->where('recipient_id', $user->id)
            ->unread()
            ->update(['read_at' => now()]);
    }

    public function unreadCount(User $user): int
    {
        return UserNotification::query()
            ->where('recipient_id', $user->id)
            ->unread()
            ->count();
    }
}
