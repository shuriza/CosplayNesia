<?php

namespace App\Http\Controllers;

use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function resolve(Request $request, CartService $carts): JsonResponse
    {
        $validated = $request->validate($this->rules());

        return response()->json(['items' => $carts->resolve($validated['items'])]);
    }

    public function show(Request $request, CartService $carts): JsonResponse
    {
        return response()->json(['items' => $carts->forUser($request->user())]);
    }

    public function merge(Request $request, CartService $carts): JsonResponse
    {
        $validated = $request->validate($this->rules());

        return response()->json([
            'items' => $carts->merge(
                $request->user(),
                $validated['items'],
                (bool) ($validated['replace'] ?? false),
            ),
        ]);
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        return [
            'replace' => ['sometimes', 'boolean'],
            'items' => ['present', 'array', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.product_variant_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.rental_start_date' => ['nullable', 'date_format:Y-m-d'],
            'items.*.rental_end_date' => ['nullable', 'date_format:Y-m-d'],
            'items.*.client_updated_at' => ['nullable', 'date'],
        ];
    }
}
