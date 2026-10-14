<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One report about one thing.
 *
 * A report is not a verdict: it records that someone flagged something and what an operator later
 * decided. Resolutions are stored on the row rather than applied by editing the target, so the
 * decision trail survives even when the reported content is gone.
 */
class ModerationReport extends Model
{
    public const TARGET_PRODUCT = 'product';

    public const TARGET_USER = 'user';

    public const TARGET_MESSAGE = 'message';

    public const TARGET_REVIEW = 'review';

    public const STATUS_OPEN = 'open';

    public const STATUS_REVIEWING = 'reviewing';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_DISMISSED = 'dismissed';

    public const ACTION_NONE = 'none';

    public const ACTION_WARN = 'warn';

    public const ACTION_TAKEDOWN = 'takedown';

    public const CATEGORY_SPAM = 'spam';

    public const CATEGORY_COUNTERFEIT = 'counterfeit';

    public const CATEGORY_INAPPROPRIATE = 'inappropriate';

    public const CATEGORY_MISLEADING = 'misleading';

    public const CATEGORY_ABUSE = 'abuse';

    public const CATEGORY_OTHER = 'other';

    protected $fillable = [
        'reporter_id', 'reporter_role', 'target_type', 'target_id', 'category',
        'reason', 'evidence', 'status', 'resolution', 'resolution_action',
        'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function targetTypes(): array
    {
        return [self::TARGET_PRODUCT, self::TARGET_USER, self::TARGET_MESSAGE, self::TARGET_REVIEW];
    }

    /**
     * @return array<int, string>
     */
    public static function categories(): array
    {
        return [
            self::CATEGORY_SPAM, self::CATEGORY_COUNTERFEIT, self::CATEGORY_INAPPROPRIATE,
            self::CATEGORY_MISLEADING, self::CATEGORY_ABUSE, self::CATEGORY_OTHER,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [self::STATUS_OPEN, self::STATUS_REVIEWING, self::STATUS_RESOLVED, self::STATUS_DISMISSED];
    }

    /**
     * @return array<int, string>
     */
    public static function resolutionActions(): array
    {
        return [self::ACTION_NONE, self::ACTION_WARN, self::ACTION_TAKEDOWN];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_REVIEWING], true);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_REVIEWING]);
    }
}
