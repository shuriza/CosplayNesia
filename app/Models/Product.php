<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    public const TYPE_RENTAL = 'Sewa';

    public const TYPE_SALE = 'Beli';

    protected $fillable = [
        'seller_id', 'name', 'series', 'category', 'price', 'type', 'size',
        'seller', 'city', 'rating', 'popular', 'newest', 'badge', 'image', 'stock', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'stock' => 'integer',
            'popular' => 'integer',
            'newest' => 'integer',
            'rating' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites')->withTimestamps();
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function rentalReservations(): HasMany
    {
        return $this->hasMany(RentalReservation::class);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, function (Builder $query, string $term): void {
            $query->where(function (Builder $query) use ($term): void {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('series', 'like', "%{$term}%")
                    ->orWhere('seller', 'like', "%{$term}%")
                    ->orWhere('city', 'like', "%{$term}%");
            });
        });
    }
}
