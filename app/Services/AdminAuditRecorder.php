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

    /**
     * Record a state change with a redacted before/after pair.
     *
     * Only the named keys are kept, and only when both sides are scalar: a snapshot must answer
     * "what changed" for an operator six months later without becoming a second copy of the row
     * (and therefore a second place for PII to live). Keys whose value is unchanged are dropped so
     * the context stays a diff rather than a dump.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<int, string>  $keys
     */
    public function recordChange(
        ?User $actor,
        string $action,
        string $targetType,
        int $targetId,
        array $before,
        array $after,
        array $keys,
        ?string $reason = null,
        ?Request $request = null,
    ): AdminAuditLog {
        $changes = [];
        foreach ($keys as $key) {
            $from = $before[$key] ?? null;
            $to = $after[$key] ?? null;
            if (! is_scalar($from) && $from !== null) {
                continue;
            }
            if (! is_scalar($to) && $to !== null) {
                continue;
            }
            if ($from === $to) {
                continue;
            }
            $changes[$key] = ['from' => $from, 'to' => $to];
        }

        return $this->record(
            actor: $actor,
            action: $action,
            targetType: $targetType,
            targetId: $targetId,
            reason: $reason,
            context: $changes === [] ? [] : ['changes' => $changes],
            request: $request,
        );
    }
}
