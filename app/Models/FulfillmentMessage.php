<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FulfillmentMessage extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const MAX_BODY_LENGTH = 1000;

    public const ROLE_BUYER = 'buyer';

    public const ROLE_SELLER = 'seller';

    protected $fillable = ['fulfillment_id', 'sender_id', 'sender_role', 'body', 'created_at'];

    /**
     * A conversation is evidence in a dispute, so messages are append-only. Editing or deleting
     * would let either side rewrite what was agreed, exactly like order_activities.
     */
    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Fulfillment messages are immutable.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Fulfillment messages are immutable.');
        });
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillment::class, 'fulfillment_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function scopeForThread(Builder $query, OrderFulfillment|int $fulfillment): Builder
    {
        $fulfillmentId = $fulfillment instanceof OrderFulfillment ? $fulfillment->id : $fulfillment;

        return $query->where('fulfillment_id', $fulfillmentId)
            ->latest('created_at')
            ->latest('id');
    }

    public static function counterpartRole(string $role): string
    {
        return $role === self::ROLE_BUYER ? self::ROLE_SELLER : self::ROLE_BUYER;
    }
}
