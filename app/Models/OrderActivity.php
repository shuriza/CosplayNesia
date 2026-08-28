<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderActivity extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const ROLE_BUYER = 'buyer';

    public const ROLE_SELLER = 'seller';

    public const ROLE_SYSTEM = 'system';

    protected $fillable = [
        'order_id', 'fulfillment_id', 'order_item_id', 'rental_reservation_id',
        'actor_id', 'actor_role', 'event_type', 'from_status', 'to_status',
        'metadata', 'event_key', 'occurred_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Order activities are immutable.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Order activities are immutable.');
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillment::class, 'fulfillment_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function rentalReservation(): BelongsTo
    {
        return $this->belongsTo(RentalReservation::class, 'rental_reservation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
