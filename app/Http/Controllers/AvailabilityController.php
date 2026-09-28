<?php

namespace App\Http\Controllers;

use App\Http\Requests\AvailabilityRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\RentalCapacity;
use Illuminate\Http\JsonResponse;

class AvailabilityController extends Controller
{
    public function show(AvailabilityRequest $request, Product $product, RentalCapacity $capacity): JsonResponse
    {
        $sellerCanReceiveOrders = $product->seller_id === null || $product->store?->status === Store::STATUS_APPROVED;
        abort_unless($product->isPublished() && $sellerCanReceiveOrders && $product->type === Product::TYPE_RENTAL, 404);

        $validated = $request->validated();
        $variant = ProductVariant::query()->whereKey($validated['variant_id'])->where('product_id', $product->id)->firstOrFail();
        $summary = $capacity->summary($product, $validated['start_date'], $validated['end_date'], $variant->id);
        $stock = (int) $variant->stock;
        $available = $summary['available_quantity'];

        return response()->json([
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'stock' => $stock,
            'reserved_quantity' => $summary['reserved_quantity'],
            'blocked_quantity' => $summary['blocked_quantity'],
            'unavailable_quantity' => $summary['unavailable_quantity'],
            'available_quantity' => $available,
            'requested_quantity' => (int) ($validated['quantity'] ?? 1),
            'available' => $available >= (int) ($validated['quantity'] ?? 1),
        ]);
    }
}
