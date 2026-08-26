<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderFulfillment extends Model
{
    use HasFactory;

    public const STATUS_RECEIVED = 'received';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_READY = 'ready';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'order_id', 'seller_id', 'seller_name', 'status', 'status_changed_at',
        'accepted_at', 'ready_at', 'completed_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status_changed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'ready_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_RECEIVED,
            self::STATUS_ACCEPTED,
            self::STATUS_READY,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'fulfillment_id');
    }

    public function scopeForSeller(Builder $query, User|int $seller): Builder
    {
        $sellerId = $seller instanceof User ? $seller->id : $seller;

        return $query->where('seller_id', $sellerId);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }
}
