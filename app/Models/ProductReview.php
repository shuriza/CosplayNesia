<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReview extends Model
{
    use HasFactory;

    public const MAX_BODY_LENGTH = 500;

    protected $fillable = ['order_item_id', 'product_id', 'user_id', 'rating', 'body'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForPublicFeed(Builder $query, Product|int $product): Builder
    {
        $productId = $product instanceof Product ? $product->id : $product;

        return $query->where('product_id', $productId)
            ->with('user:id,name')
            ->latest()
            ->latest('id');
    }

    /**
     * Public reviewer identity is reduced to a given name plus a family-name initial so the
     * catalog never exposes a full buyer identity to anonymous visitors.
     */
    public function reviewerLabel(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->user?->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return 'Cosplayer';
        }

        $given = array_shift($parts);
        if ($parts === []) {
            return $given;
        }

        return $given.' '.mb_strtoupper(mb_substr((string) end($parts), 0, 1)).'.';
    }
}
