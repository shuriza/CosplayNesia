<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductLifecycle
{
    public function __construct(private readonly ProductModeration $moderation) {}

    /** @var array<string, list<string>> */
    private const SELLER_TRANSITIONS = [
        Product::STATUS_DRAFT => [Product::STATUS_PUBLISHED, Product::STATUS_ARCHIVED],
        Product::STATUS_PUBLISHED => [Product::STATUS_PAUSED, Product::STATUS_ARCHIVED],
        Product::STATUS_PAUSED => [Product::STATUS_PUBLISHED, Product::STATUS_ARCHIVED],
        Product::STATUS_REJECTED => [Product::STATUS_DRAFT, Product::STATUS_ARCHIVED],
        Product::STATUS_ARCHIVED => [],
    ];

    public function transition(User $seller, Product $product, string $target): Product
    {
        return DB::transaction(function () use ($seller, $product, $target): Product {
            $store = Store::query()->where('user_id', $seller->id)->lockForUpdate()->first();
            abort_unless($store?->isApproved(), 403, 'Toko harus aktif untuk mengubah status listing.');

            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->seller_id === $seller->id, 403);

            if (! in_array($target, self::SELLER_TRANSITIONS[$locked->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => "Status listing tidak dapat diubah dari {$locked->status} menjadi {$target}.",
                ]);
            }
            if ($target === Product::STATUS_PUBLISHED) {
                return $this->moderation->submit($seller, $locked);
            }

            $attributes = ['status' => $target];
            if ($target === Product::STATUS_PUBLISHED) {
                $attributes['published_at'] = now();
                $attributes['archived_at'] = null;
            } elseif ($target === Product::STATUS_ARCHIVED) {
                $attributes['archived_at'] = now();
            }

            $locked->forceFill($attributes)->save();

            return $locked->refresh()->load(['images', 'variants']);
        }, 3);
    }
}
