<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['product_id' => ['required', 'integer', 'exists:products,id']]);
        $request->user()->favoriteProducts()->syncWithoutDetaching([$validated['product_id']]);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $request->user()->favoriteProducts()->detach($product->id);

        return response()->json(['success' => true]);
    }
}
