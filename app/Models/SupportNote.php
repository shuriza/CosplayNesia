<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Private operator note attached to a user, store, product, or order.
 *
 * Append-only for the same reason the audit log is: a note is evidence of what support knew and
 * when. Notes are internal by construction — no buyer- or seller-facing endpoint reads this table,
 * which is what keeps support context from leaking into a conversation with a customer.
 */
class SupportNote extends Model
{
    public const UPDATED_AT = null;

    public const TARGET_USER = 'user';

    public const TARGET_STORE = 'store';

    public const TARGET_PRODUCT = 'product';

    public const TARGET_ORDER = 'order';

    protected $fillable = [
        'author_id', 'author_role', 'target_type', 'target_id', 'body', 'occurred_at', 'created_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Support notes are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Support notes are immutable.'));
    }

    /**
     * @return array<int, string>
     */
    public static function targetTypes(): array
    {
        return [self::TARGET_USER, self::TARGET_STORE, self::TARGET_PRODUCT, self::TARGET_ORDER];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
