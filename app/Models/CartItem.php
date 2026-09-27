<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'rental_start_date',
        'rental_end_date',
        'client_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'rental_start_date' => 'date:Y-m-d',
            'rental_end_date' => 'date:Y-m-d',
            'client_updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
