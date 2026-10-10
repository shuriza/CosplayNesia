<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Single write path for the backoffice audit trail. Kept separate from SecurityEventRecorder so
 * admin actions are queryable on their own and always carry actor role, reason, and request id.
 */
class AdminAuditRecorder
{
    public function record(
        ?User $actor,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $reason = null,
        array $context = [],
        ?Request $request = null,
    ): AdminAuditLog {
        return AdminAuditLog::query()->create([
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role ?? 'system',
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
            'context' => $context === [] ? null : $context,
            'request_id' => $request?->attributes->get('request_id'),
            'ip_address' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 500) ?: null,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }
}
