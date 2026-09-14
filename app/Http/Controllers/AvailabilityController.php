<?php

namespace App\Http\Controllers;

use App\Http\Requests\AvailabilityRequest;
use App\Models\Product;
use App\Services\RentalCapacity;
use Illuminate\Http\JsonResponse;

class AvailabilityController extends Controller
{
    public function show(AvailabilityRequest $request, Product $product, RentalCapacity $capacity): JsonResponse
    {
        abort_unless($product->is_active && $product->type === Product::TYPE_RENTAL, 404);

        $validated = $request->validated();
        $summary = $capacity->summary($product, $validated['start_date'], $validated['end_date']);
        $stock = (int) $product->stock;
        $available = $summary['available_quantity'];

        return response()->json([
            'product_id' => $product->id,
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
