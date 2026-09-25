<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

class ProductReadiness
{
    /** @return array{ready: bool, items: list<array{key: string, label: string, complete: bool}>} */
    public function evaluate(Product $product): array
    {
        $product->loadMissing(['images', 'variants']);
        $attributes = $product->category_attributes ?? [];
        $measurements = array_filter($product->measurements ?? [], fn ($value): bool => filled($value));
        $requiresCharacter = $product->category !== 'Aksesoris';
        $pricingComplete = $product->type === Product::TYPE_SALE
            ? $product->sale_price !== null && $product->sale_price > 0
            : $product->rental_price_per_day !== null
                && $product->rental_price_per_day > 0
                && $product->rental_deposit !== null
                && $product->rental_min_days !== null
                && $product->rental_max_days !== null
                && $product->rental_min_days <= $product->rental_max_days;

        $items = [
            ['key' => 'identity', 'label' => 'Nama, kategori, series, dan kota terisi', 'complete' => filled($product->name) && filled($product->category) && filled($product->series) && filled($product->city)],
            ['key' => 'images', 'label' => 'Minimal satu gambar listing', 'complete' => $product->images->isNotEmpty()],
            ['key' => 'variants', 'label' => 'Minimal satu SKU dengan data kondisi', 'complete' => $product->variants->isNotEmpty() && $product->variants->every(fn ($variant): bool => filled($variant->sku) && filled($variant->label) && filled($variant->condition))],
            ['key' => 'attributes', 'label' => 'Karakter dan material sesuai kategori', 'complete' => filled($attributes['material'] ?? null) && (! $requiresCharacter || filled($attributes['character'] ?? null))],
            ['key' => 'includes', 'label' => 'Kelengkapan kostum dijelaskan', 'complete' => count($product->costume_includes ?? []) > 0],
            ['key' => 'measurements', 'label' => 'Ukuran detail tersedia', 'complete' => $product->category === 'Aksesoris' || $measurements !== []],
            ['key' => 'care', 'label' => 'Aturan perawatan dijelaskan', 'complete' => filled($product->care_instructions)],
            ['key' => 'pricing', 'label' => 'Harga dan syarat durasi lengkap', 'complete' => $pricingComplete],
        ];

        return [
            'ready' => collect($items)->every(fn (array $item): bool => $item['complete']),
            'items' => $items,
        ];
    }

    public function assertReady(Product $product): void
    {
        $readiness = $this->evaluate($product);
        if (! $readiness['ready']) {
            $missing = collect($readiness['items'])
                ->where('complete', false)
                ->pluck('label')
                ->implode(', ');
            throw ValidationException::withMessages([
                'status' => 'Listing belum siap diterbitkan: '.$missing.'.',
            ]);
        }
    }
}
