<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CartService
{
    /** @param array<int, array<string, mixed>> $entries */
    public function resolve(array $entries): array
    {
        return $this->payload($this->validEntries($entries));
    }

    /** @param array<int, array<string, mixed>> $entries */
    public function merge(User $user, array $entries, bool $replace = false): array
    {
        DB::transaction(function () use ($user, $entries, $replace): void {
            $valid = $this->validEntries($entries);
            $existing = CartItem::query()
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_variant_id');

            if ($replace) {
                CartItem::query()
                    ->where('user_id', $user->id)
                    ->whereNotIn('product_variant_id', $valid->pluck('product_variant_id'))
                    ->delete();
            }

            foreach ($valid as $entry) {
                $current = $existing->get($entry['product_variant_id']);
                $incomingAt = $this->clientTimestamp($entry['client_updated_at'] ?? null);
                if (! $replace && $current?->client_updated_at && $incomingAt->lt($current->client_updated_at)) {
                    continue;
                }
                CartItem::query()->updateOrCreate(
                    ['user_id' => $user->id, 'product_variant_id' => $entry['product_variant_id']],
                    [
                        'product_id' => $entry['product_id'],
                        'quantity' => $entry['quantity'],
                        'rental_start_date' => $entry['rental_start_date'],
                        'rental_end_date' => $entry['rental_end_date'],
                        'client_updated_at' => $incomingAt,
                    ],
                );
            }
        }, 3);

        return $this->forUser($user);
    }

    public function forUser(User $user): array
    {
        $rows = CartItem::query()
            ->where('user_id', $user->id)
            ->oldest('id')
            ->get()
            ->map(fn (CartItem $item): array => [
                'cart_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'quantity' => $item->quantity,
                'rental_start_date' => $item->rental_start_date?->toDateString(),
                'rental_end_date' => $item->rental_end_date?->toDateString(),
                'client_updated_at' => $item->client_updated_at?->toISOString(),
            ])->all();

        $valid = $this->validEntries($rows);
        CartItem::query()
            ->where('user_id', $user->id)
            ->whereNotIn('id', $valid->pluck('cart_item_id'))
            ->delete();

        return $this->payload($valid);
    }

    /** @param array<int, array<string, mixed>> $entries */
    private function validEntries(array $entries): Collection
    {
        $productIds = collect($entries)->pluck('product_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $products = Product::query()
            ->with(['images', 'variants', 'store:id,slug,name,status,pickup_enabled'])
            ->withReviewSummary()
            ->whereIn('id', $productIds)
            ->published()
            ->where(function ($query): void {
                $query->whereNull('seller_id')->orWhereHas('store', fn ($store) => $store->where('status', Store::STATUS_APPROVED));
            })
            ->get()
            ->keyBy('id');

        return collect($entries)->map(function (array $entry) use ($products): ?array {
            $product = $products->get((int) $entry['product_id']);
            if (! $product) {
                return null;
            }
            $variant = $product->variants->firstWhere('id', (int) $entry['product_variant_id']);
            if (! $variant) {
                return null;
            }
            if ($variant->stock < 1) {
                return null;
            }

            return [
                'cart_item_id' => isset($entry['cart_item_id']) ? (int) $entry['cart_item_id'] : null,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'quantity' => min((int) ($entry['quantity'] ?? 1), $variant->stock),
                'rental_start_date' => $product->type === Product::TYPE_RENTAL ? ($entry['rental_start_date'] ?? null) : null,
                'rental_end_date' => $product->type === Product::TYPE_RENTAL ? ($entry['rental_end_date'] ?? null) : null,
                'client_updated_at' => $entry['client_updated_at'] ?? null,
                'product' => $product,
            ];
        })->filter()->values();
    }

    private function payload(Collection $entries): array
    {
        return $entries->map(function (array $entry): array {
            $product = $entry['product'];
            $product->setAttribute('selected_variant_id', $entry['product_variant_id']);

            return [
                'cart_item_id' => $entry['cart_item_id'],
                'product' => $product,
                'product_id' => $entry['product_id'],
                'product_variant_id' => $entry['product_variant_id'],
                'quantity' => $entry['quantity'],
                'rental_start_date' => $entry['rental_start_date'],
                'rental_end_date' => $entry['rental_end_date'],
                'client_updated_at' => $entry['client_updated_at'],
            ];
        })->all();
    }

    private function clientTimestamp(mixed $value): CarbonImmutable
    {
        try {
            $timestamp = CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return CarbonImmutable::now();
        }

        return $timestamp->isFuture() ? CarbonImmutable::now() : $timestamp;
    }
}
