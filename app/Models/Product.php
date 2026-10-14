<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    use HasFactory;

    public const TYPE_RENTAL = 'Sewa';

    public const TYPE_SALE = 'Beli';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * Removed by an operator. Distinct from `paused` (the seller's own choice) and from
     * `archived` (permanent, seller-driven): a takedown is reversible and the seller may not
     * undo it, so the seller transition table has no outgoing edge from this state.
     */
    public const STATUS_TAKEN_DOWN = 'taken_down';

    protected $fillable = [
        'seller_id', 'store_id', 'name', 'series', 'category', 'category_attributes', 'costume_includes',
        'price', 'sale_price', 'rental_price_per_day', 'rental_deposit', 'rental_min_days',
        'rental_max_days', 'type', 'size', 'measurements', 'care_instructions', 'seller', 'city', 'popular',
        'badge', 'image', 'stock', 'status',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'sale_price' => 'integer',
            'rental_price_per_day' => 'integer',
            'rental_deposit' => 'integer',
            'rental_min_days' => 'integer',
            'rental_max_days' => 'integer',
            'stock' => 'integer',
            'popular' => 'integer',
            'rating' => 'float',
            'review_count' => 'integer',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            'submitted_for_review_at' => 'datetime',
            'moderated_at' => 'datetime',
            'taken_down_at' => 'datetime',
            'content_version' => 'integer',
            'approved_version' => 'integer',
            'category_attributes' => 'array',
            'costume_includes' => 'array',
            'measurements' => 'array',
            'is_favorite' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Product $product): void {
            if ($product->type === self::TYPE_RENTAL && $product->rental_price_per_day === null) {
                $product->forceFill([
                    'rental_price_per_day' => $product->price,
                    'rental_deposit' => 0,
                    'rental_min_days' => 1,
                    'rental_max_days' => RentalReservation::MAX_DAYS,
                ])->save();
            } elseif ($product->type === self::TYPE_SALE && $product->sale_price === null) {
                $product->forceFill(['sale_price' => $product->price])->save();
            }
            $product->variants()->create([
                'sku' => 'LEGACY-'.$product->id,
                'label' => $product->size ?: 'Standar',
                'size' => $product->size,
                'condition' => ProductVariant::CONDITION_GOOD,
                'stock' => $product->stock,
                'position' => 0,
                'is_default' => true,
            ]);
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites')->withTimestamps();
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position')->orderBy('id');
    }

    public function rentalReservations(): HasMany
    {
        return $this->hasMany(RentalReservation::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function scopeWithReviewSummary(Builder $query): Builder
    {
        return $query
            ->withCount(['reviews as review_count'])
            ->withAvg('reviews as rating', 'rating');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Rating distribution for every star bucket, resolved in one grouped query so the public
     * review feed never issues five separate counts.
     *
     * @return array<int, int>
     */
    public function ratingDistribution(): array
    {
        $buckets = array_fill_keys(range(1, 5), 0);
        $counts = ProductReview::query()
            ->where('product_id', $this->id)
            ->selectRaw('rating, count(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        foreach ($counts as $rating => $total) {
            $bucket = (int) $rating;
            if (isset($buckets[$bucket])) {
                $buckets[$bucket] = (int) $total;
            }
        }

        return $buckets;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, function (Builder $query, string $term): void {
            $driver = DB::connection($query->getModel()->getConnectionName())->getDriverName();
            if (mb_strlen($term) >= 3 && $driver === 'sqlite') {
                $phrase = '"'.str_replace('"', '""', $term).'"';
                $query->whereRaw(
                    'products.id in (select product_id from product_search where product_search match ?)',
                    [$phrase],
                );

                return;
            }

            if (mb_strlen($term) >= 3 && $driver === 'pgsql') {
                $query->whereRaw(
                    "lower(coalesce(name, '') || ' ' || coalesce(series, '') || ' ' || coalesce(seller, '') || ' ' || coalesce(city, '')) LIKE ?",
                    ['%'.mb_strtolower($term).'%'],
                );

                return;
            }

            $query->where(function (Builder $query) use ($term): void {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('series', 'like', "%{$term}%")
                    ->orWhere('seller', 'like', "%{$term}%")
                    ->orWhere('city', 'like', "%{$term}%");
            });
        });
    }
}
