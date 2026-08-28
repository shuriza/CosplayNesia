<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'fulfillment_id', 'product_id', 'product_name', 'product_type', 'unit_price', 'quantity',
        'rental_start_date', 'rental_end_date', 'stock_released_at',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'quantity' => 'integer',
            'rental_start_date' => 'date:Y-m-d',
            'rental_end_date' => 'date:Y-m-d',
            'stock_released_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function rentalReservation(): HasOne
    {
        return $this->hasOne(RentalReservation::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(ProductReview::class);
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillment::class, 'fulfillment_id');
    }

    public function isFulfilledForReview(): bool
    {
        if ($this->fulfillment?->status !== OrderFulfillment::STATUS_COMPLETED) {
            return false;
        }

        return $this->product_type !== Product::TYPE_RENTAL
            || $this->rentalReservation?->status === RentalReservation::STATUS_COMPLETED;
    }
}
