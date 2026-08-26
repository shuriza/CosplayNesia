<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::in(['Anime', 'Game', 'VTuber', 'Aksesoris'])],
            'sort' => ['nullable', Rule::in(['popular', 'newest', 'low', 'high', 'price_asc', 'price_desc'])],
        ]);

        $sort = $filters['sort'] ?? 'popular';
        $ordering = match ($sort) {
            'newest' => ['newest', 'desc'],
            'low', 'price_asc' => ['price', 'asc'],
            'high', 'price_desc' => ['price', 'desc'],
            default => ['popular', 'desc'],
        };

        $products = Product::query()
            ->where('is_active', true)
            ->search($filters['q'] ?? null)
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->orderBy(...$ordering)
            ->orderBy('id')
            ->get();

        return response()->json($products);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $user = $request->user();
        $product = $user->products()->create([
            ...$request->validated(),
            'series' => $request->validated('series') ?: 'Original',
            'size' => $request->validated('size') ?: 'All size',
            'city' => $request->validated('city') ?: 'Online',
            'seller' => $user->name,
            'rating' => 5,
            'popular' => 0,
            'newest' => Product::max('newest') + 1,
            'badge' => 'Baru',
            'image' => $request->validated('image') ?: config('cosplaynesia.default_product_image'),
        ]);

        return response()->json($product, 201);
    }

    public function owned(Request $request): JsonResponse
    {
        return response()->json($request->user()->products()->latest()->get());
    }

    public function update(StoreProductRequest $request, Product $product): JsonResponse
    {
        $attributes = $request->validated();
        $defaults = [
            'series' => 'Original',
            'size' => 'All size',
            'city' => 'Online',
            'image' => config('cosplaynesia.default_product_image'),
        ];

        foreach ($defaults as $attribute => $default) {
            if (array_key_exists($attribute, $attributes) && blank($attributes[$attribute])) {
                $attributes[$attribute] = $default;
            }
        }

        $product->update($attributes);

        return response()->json($product->fresh());
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->seller_id === $request->user()->id, 403);
        $product->delete();

        return response()->json(null, 204);
    }
}
