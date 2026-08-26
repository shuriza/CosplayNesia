<?php

namespace App\Http\Controllers;

use App\Http\Requests\AvailabilityRequest;
use App\Models\Product;
use App\Models\RentalReservation;
use Illuminate\Http\JsonResponse;

class AvailabilityController extends Controller
{
    public function show(AvailabilityRequest $request, Product $product): JsonResponse
    {
        abort_unless($product->is_active && $product->type === Product::TYPE_RENTAL, 404);

        $validated = $request->validated();
        $reserved = RentalReservation::query()
            ->where('product_id', $product->id)
            ->where('status', RentalReservation::STATUS_RESERVED)
            ->whereDate('start_date', '<=', $validated['end_date'])
            ->whereDate('end_date', '>=', $validated['start_date'])
            ->sum('quantity');
        $stock = (int) $product->stock;
        $available = max(0, $stock - (int) $reserved);

        return response()->json([
            'product_id' => $product->id,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'stock' => $stock,
            'reserved_quantity' => (int) $reserved,
            'available_quantity' => $available,
            'requested_quantity' => (int) ($validated['quantity'] ?? 1),
            'available' => $available >= (int) ($validated['quantity'] ?? 1),
        ]);
    }
}
