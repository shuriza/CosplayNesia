<?php

namespace App\Services;

use App\Models\Product;
use App\Models\RentalReservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CatalogAvailability
{
    /**
     * Return rental products with at least one SKU that can satisfy the requested interval.
     * Blocks apply to every SKU of a product, while reservations apply to their snapshotted SKU.
     *
     * Size and condition are evaluated on the same SKU as capacity: a product whose M SKU is fully
     * reserved must not appear for `size=M` just because its L SKU happens to be free.
     *
     * @param  Collection<int, int>|array<int, int>  $productIds
     * @return array<int, int>
     */
    public function availableProductIds(
        Collection|array $productIds,
        string $start,
        string $end,
        int $quantity,
        ?string $size = null,
        ?string $condition = null,
    ): array {
        $ids = collect($productIds)->map(fn ($id): int => (int) $id)->filter()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $products = Product::query()
            ->whereIn('id', $ids)
            ->where('type', Product::TYPE_RENTAL)
            ->with(['variants' => function ($variants): void {
                // Narrow in PHP rather than SQL: the same closure keeps product-level `size`
                // (legacy listings carry it on the product row) and SKU-level attributes together.
                $variants->orderBy('position')->orderBy('id');
            }])
            ->get(['id', 'size']);

        $reservations = DB::table('rental_reservations')
            ->whereIn('product_id', $products->pluck('id'))
            ->where('status', RentalReservation::STATUS_RESERVED)
            ->where('end_date', '>=', $start)
            ->where('start_date', '<=', $end)
            ->get(['product_id', 'product_variant_id', 'quantity', 'start_date', 'end_date'])
            ->groupBy('product_id');

        $blocks = DB::table('rental_blocks')
            ->whereIn('product_id', $products->pluck('id'))
            ->whereNull('cancelled_at')
            ->where('end_date', '>=', $start)
            ->where('start_date', '<=', $end)
            ->get(['product_id', 'quantity', 'start_date', 'end_date'])
            ->groupBy('product_id');

        $available = [];
        foreach ($products as $product) {
            $productReservations = $reservations->get($product->id, collect());
            $productBlocks = $blocks->get($product->id, collect());

            foreach ($product->variants as $variant) {
                if (! $this->variantMatches($variant, $product, $size, $condition)) {
                    continue;
                }
                if ($this->variantAvailable($variant->stock, $productReservations, $productBlocks, $variant->id, $start, $end, $quantity)) {
                    $available[] = (int) $product->id;
                    break;
                }
            }
        }

        return $available;
    }

    private function variantMatches(object $variant, Product $product, ?string $size, ?string $condition): bool
    {
        if ($size !== null && ! $this->sizeMatches($variant->size, $product->size, $size)) {
            return false;
        }

        return $condition === null || $variant->condition === $condition;
    }

    /**
     * A SKU that declares its own size is judged on that size alone — inheriting the product
     * level size here is exactly how an L SKU would satisfy a size=M request. Only an SKU
     * without a size of its own falls back to the product's size, and "All size" fits anything.
     */
    private function sizeMatches(?string $variantSize, ?string $productSize, string $size): bool
    {
        $wanted = mb_strtolower(trim($size));
        $sku = $variantSize === null ? '' : mb_strtolower(trim($variantSize));

        if ($sku !== '') {
            return $sku === $wanted;
        }

        $inherited = $productSize === null ? '' : mb_strtolower(trim($productSize));

        return $inherited === $wanted || $inherited === 'all size';
    }

    private function variantAvailable(
        int $stock,
        Collection $reservations,
        Collection $blocks,
        int $variantId,
        string $start,
        string $end,
        int $quantity,
    ): bool {
        if ($stock < $quantity) {
            return false;
        }

        $events = [];
        foreach ($blocks as $allocation) {
            $this->addEvents($events, $allocation, $start, $end);
        }
        // A reservation snapshotted before SKUs existed has no variant id. It is counted against
        // every SKU rather than ignored: over-promising availability is worse than hiding a SKU
        // that might still be free.
        foreach ($reservations->where(fn (object $row): bool => $row->product_variant_id === null || (int) $row->product_variant_id === $variantId) as $allocation) {
            $this->addEvents($events, $allocation, $start, $end);
        }

        $used = 0;
        $peak = 0;
        $last = CarbonImmutable::parse($end);
        for ($day = CarbonImmutable::parse($start); $day->lte($last); $day = $day->addDay()) {
            $date = $day->toDateString();
            $used += $events[$date] ?? 0;
            $peak = max($peak, $used);
        }

        return $stock - $peak >= $quantity;
    }

    private function addEvents(array &$events, object $allocation, string $start, string $end): void
    {
        $from = max($start, (string) $allocation->start_date);
        $through = min($end, (string) $allocation->end_date);
        $afterEnd = CarbonImmutable::parse($through)->addDay()->toDateString();
        $quantity = (int) $allocation->quantity;
        $events[$from] = ($events[$from] ?? 0) + $quantity;
        $events[$afterEnd] = ($events[$afterEnd] ?? 0) - $quantity;
    }
}
