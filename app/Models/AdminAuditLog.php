<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable record of a backoffice action. Like SecurityEvent and OrderActivity, rows are
 * append-only: corrections are new rows, never edits, so the audit trail cannot be rewritten.
 */
class AdminAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id', 'actor_role', 'action', 'target_type', 'target_id',
        'reason', 'context', 'request_id', 'ip_address', 'user_agent',
        'occurred_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Admin audit logs are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Admin audit logs are immutable.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
