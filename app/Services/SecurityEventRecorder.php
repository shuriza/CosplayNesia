<?php

namespace App\Services;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Notifications\NotificationCatalog;
use Illuminate\Http\Request;

class SecurityEventRecorder
{
    /**
     * Events that also email the account owner.
     *
     * These are the changes an attacker would make to keep an account: rotating the password,
     * moving the address, or kicking every device. The user must learn about them even if they
     * never open the app again, which is why the catalog marks the category mandatory.
     */
    private const EMAILED_EVENTS = [
        NotificationCatalog::TYPE_SECURITY_PASSWORD_CHANGED,
        NotificationCatalog::TYPE_SECURITY_EMAIL_CHANGED,
        NotificationCatalog::TYPE_SECURITY_SESSIONS_REVOKED,
    ];

    public function __construct(private readonly NotificationChannel $channel) {}

    public function record(?User $user, string $type, ?Request $request = null, array $metadata = []): void
    {
        $event = SecurityEvent::query()->create([
            'user_id' => $user?->id,
            'type' => $type,
            'ip_address' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 500) ?: null,
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ]);

        if ($user === null || ! in_array($type, self::EMAILED_EVENTS, true)) {
            return;
        }

        // Keyed by the security event id, not by type+user: the second password change of the
        // week is a new event and must send a new email, while a retried request for the same
        // recorded event still cannot send twice.
        $this->channel->queue(
            recipientId: $user->id,
            type: $type,
            eventKey: "notify:security:{$event->id}",
            payload: $metadata,
        );
    }
}
