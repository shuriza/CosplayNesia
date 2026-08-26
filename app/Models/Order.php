<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Order extends Model
{
    use HasFactory;

    public const STATUS_DEMO_CONFIRMED = 'demo_confirmed';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PARTIALLY_FULFILLED = 'partially_fulfilled';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PARTIALLY_CANCELLED = 'partially_cancelled';

    protected $fillable = [
        'user_id', 'total_amount', 'status', 'idempotency_key', 'idempotency_hash',
        'recipient_name', 'recipient_phone', 'recipient_email', 'address_line1', 'address_line2',
        'city', 'province', 'postal_code', 'handoff_note',
    ];

    protected function casts(): array
    {
        return ['total_amount' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function rentalReservations(): HasManyThrough
    {
        return $this->hasManyThrough(RentalReservation::class, OrderItem::class);
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(OrderFulfillment::class);
    }

    public function refreshAggregateStatus(): string
    {
        $statuses = $this->relationLoaded('fulfillments')
            ? $this->fulfillments->pluck('status')
            : $this->fulfillments()->pluck('status');

        if ($statuses->isEmpty()) {
            return self::STATUS_DEMO_CONFIRMED;
        }

        $hasUnassignedItems = $this->relationLoaded('items')
            ? $this->items->contains(fn (OrderItem $item): bool => $item->fulfillment_id === null)
            : $this->items()->whereNull('fulfillment_id')->exists();
        $active = $statuses->reject(fn (string $status): bool => in_array($status, [
            OrderFulfillment::STATUS_COMPLETED,
            OrderFulfillment::STATUS_CANCELLED,
        ], true))->count();
        $completed = $statuses->filter(fn (string $status): bool => $status === OrderFulfillment::STATUS_COMPLETED)->count();
        $cancelled = $statuses->filter(fn (string $status): bool => $status === OrderFulfillment::STATUS_CANCELLED)->count();

        return match (true) {
            $active > 0 => self::STATUS_PROCESSING,
            $completed > 0 && ($hasUnassignedItems || $cancelled > 0) => self::STATUS_PARTIALLY_FULFILLED,
            $completed > 0 => self::STATUS_FULFILLED,
            $hasUnassignedItems => self::STATUS_PARTIALLY_CANCELLED,
            default => self::STATUS_CANCELLED,
        };
    }

    public function syncAggregateStatus(): void
    {
        $status = $this->refreshAggregateStatus();
        if ($this->status !== $status) {
            $this->forceFill(['status' => $status])->save();
        }
    }
}
