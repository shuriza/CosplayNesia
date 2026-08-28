<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Models\Product;
use App\Models\RentalReservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::in(['Anime', 'Game', 'VTuber', 'Aksesoris'])],
            'sort' => ['nullable', Rule::in(['popular', 'newest', 'low', 'high', 'price_asc', 'price_desc'])],
            'favorites' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        $favoritesOnly = (bool) ($filters['favorites'] ?? false);
        if ($favoritesOnly) {
            abort_unless($request->user(), 401);
        }

        $sort = $filters['sort'] ?? 'popular';
        $ordering = match ($sort) {
            'newest' => ['created_at', 'desc', 'desc'],
            'low', 'price_asc' => ['price', 'asc', 'asc'],
            'high', 'price_desc' => ['price', 'desc', 'desc'],
            default => ['popular', 'desc', 'desc'],
        };

        $query = Product::query()
            ->withReviewSummary()
            ->when(
                $request->user(),
                fn ($query, $user) => $query->withExists([
                    'favoritedBy as is_favorite' => fn ($favorites) => $favorites->whereKey($user->id),
                ]),
                fn ($query) => $query->selectRaw('products.*, 0 as is_favorite'),
            )
            ->where('is_active', true)
            ->search($filters['q'] ?? null)
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($favoritesOnly, fn ($query) => $query->whereHas(
                'favoritedBy',
                fn ($favorites) => $favorites->whereKey($request->user()->id),
            ))
            ->orderBy($ordering[0], $ordering[1])
            ->orderBy('id', $ordering[2]);

        $perPage = (int) ($filters['per_page'] ?? 8);
        $products = $query->cursorPaginate($perPage);

        return response()->json([
            'data' => $products->items(),
            'pagination' => [
                'next_cursor' => $products->nextCursor()?->encode(),
                'has_more' => $products->hasMorePages(),
                'per_page' => $products->perPage(),
            ],
        ]);
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
            'popular' => 0,
            'badge' => 'Baru',
            'image' => $request->validated('image') ?: config('cosplaynesia.default_product_image'),
        ]);

        return response()->json($product->newQuery()->withReviewSummary()->findOrFail($product->id), 201);
    }

    public function owned(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
        $query = $request->user()->products()
            ->withReviewSummary()
            ->latest()
            ->latest('id');
        $products = $query->cursorPaginate((int) ($filters['per_page'] ?? 5));

        return response()->json([
            'data' => $products->items(),
            'pagination' => [
                'next_cursor' => $products->nextCursor()?->encode(),
                'has_more' => $products->hasMorePages(),
                'per_page' => $products->perPage(),
            ],
        ]);
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

        $updated = DB::transaction(function () use ($request, $product, $attributes): Product {
            $lockedProduct = Product::query()
                ->whereKey($product->id)
                ->where('seller_id', $request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedProduct->type === Product::TYPE_RENTAL && (
                array_key_exists('stock', $attributes) || array_key_exists('type', $attributes)
            )) {
                $reservedCapacity = $this->peakReservedCapacity($lockedProduct);
                if (($attributes['type'] ?? Product::TYPE_RENTAL) !== Product::TYPE_RENTAL && $reservedCapacity > 0) {
                    throw ValidationException::withMessages([
                        'type' => 'Jenis produk tidak dapat diubah selama masih ada pesanan sewa aktif.',
                    ]);
                }
                if (array_key_exists('stock', $attributes) && (int) $attributes['stock'] < $reservedCapacity) {
                    throw ValidationException::withMessages([
                        'stock' => "Stok minimal {$reservedCapacity} karena sudah dialokasikan untuk pesanan sewa aktif.",
                    ]);
                }
            }

            $lockedProduct->update($attributes);

            return $lockedProduct->newQuery()->withReviewSummary()->findOrFail($lockedProduct->id);
        }, 3);

        return response()->json($updated);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->seller_id === $request->user()->id, 403);
        $product->delete();

        return response()->json(null, 204);
    }

    private function peakReservedCapacity(Product $product): int
    {
        $events = [];
        $reservations = RentalReservation::query()
            ->where('product_id', $product->id)
            ->where('status', RentalReservation::STATUS_RESERVED)
            ->whereDate('end_date', '>=', today(config('app.timezone')))
            ->get(['start_date', 'end_date', 'quantity']);

        foreach ($reservations as $reservation) {
            $start = $reservation->start_date->toDateString();
            $afterEnd = $reservation->end_date->copy()->addDay()->toDateString();
            $events[$start] = ($events[$start] ?? 0) + $reservation->quantity;
            $events[$afterEnd] = ($events[$afterEnd] ?? 0) - $reservation->quantity;
        }

        ksort($events);
        $reserved = 0;
        $peak = 0;
        foreach ($events as $quantity) {
            $reserved += $quantity;
            $peak = max($peak, $reserved);
        }

        return $peak;
    }
}
