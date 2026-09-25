<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductVariantInventory
{
    public const MAX_VARIANTS = 50;

    /** @param array<string, mixed> $attributes */
    public function add(Product $product, array $attributes): Product
    {
        return DB::transaction(function () use ($product, $attributes): Product {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $count = $locked->variants()->count();
            if ($count >= self::MAX_VARIANTS) {
                throw ValidationException::withMessages(['variants' => 'Maksimal 50 variasi per listing.']);
            }

            $attributes['position'] = (int) $locked->variants()->max('position') + 1;
            $attributes['is_default'] = false;
            $locked->variants()->create($attributes);
            $this->syncStock($locked);

            return $this->fresh($locked);
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(Product $product, ProductVariant $variant, array $attributes): Product
    {
        return DB::transaction(function () use ($product, $variant, $attributes): Product {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $lockedVariant = $locked->variants()->whereKey($variant->id)->lockForUpdate()->firstOrFail();
            $lockedVariant->update($attributes);
            $this->syncStock($locked);

            return $this->fresh($locked);
        }, 3);
    }

    public function remove(Product $product, ProductVariant $variant): Product
    {
        return DB::transaction(function () use ($product, $variant): Product {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $variants = $locked->variants()->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            if ($variants->count() === 1) {
                throw ValidationException::withMessages(['variant' => 'Listing harus memiliki minimal satu variasi.']);
            }

            $lockedVariant = $variants->firstWhere('id', $variant->id);
            abort_unless($lockedVariant, 404);
            if ($lockedVariant->orderItems()->exists()) {
                throw ValidationException::withMessages(['variant' => 'Variasi yang sudah masuk pesanan tidak dapat dihapus.']);
            }
            $wasDefault = $lockedVariant->is_default;
            $lockedVariant->delete();
            if ($wasDefault) {
                $locked->variants()->orderBy('position')->orderBy('id')->first()?->update(['is_default' => true]);
            }
            $this->syncStock($locked);

            return $this->fresh($locked);
        }, 3);
    }

    public function updateDefaultStock(Product $product, int $stock): void
    {
        $default = $product->variants()->where('is_default', true)->lockForUpdate()->firstOrFail();
        $default->update(['stock' => $stock]);
        $this->syncStock($product);
    }

    private function syncStock(Product $product): void
    {
        $product->forceFill(['stock' => (int) $product->variants()->sum('stock')])->save();
    }

    private function fresh(Product $product): Product
    {
        return $product->refresh()->load(['images', 'variants']);
    }
}
