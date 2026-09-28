<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\ProductGallery;
use App\Services\ProductLifecycle;
use App\Services\ProductModeration;
use App\Services\ProductReadiness;
use App\Services\ProductVariantInventory;
use App\Services\RentalCapacity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function __construct(private readonly ProductModeration $moderation) {}

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
            ->with(['store:id,slug,name,status', 'images', 'variants'])
            ->withReviewSummary()
            ->when(
                $request->user(),
                fn ($query, $user) => $query->withExists([
                    'favoritedBy as is_favorite' => fn ($favorites) => $favorites->whereKey($user->id),
                ]),
                fn ($query) => $query->selectRaw('products.*, 0 as is_favorite'),
            )
            ->published()
            ->where(function ($query): void {
                $query->whereNull('seller_id')->orWhereHas('store', fn ($store) => $store->where('status', Store::STATUS_APPROVED));
            })
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

    public function store(StoreProductRequest $request, ProductGallery $gallery): JsonResponse
    {
        $user = $request->user();
        $product = DB::transaction(function () use ($request, $user, $gallery): Product {
            $store = Store::query()->where('user_id', $user->id)->lockForUpdate()->first();
            abort_unless($store?->isApproved(), 403, 'Toko harus disetujui sebelum menerbitkan listing.');

            $attributes = $this->normalizePricing($request->safe()->except(['images']));
            $product = $user->products()->create([
                ...$attributes,
                'store_id' => $store->id,
                'series' => $request->validated('series') ?: 'Original',
                'size' => $request->validated('size') ?: 'All size',
                'city' => $request->validated('city') ?: $store->city,
                'seller' => $store->name,
                'popular' => 0,
                'badge' => 'Baru',
                'image' => config('cosplaynesia.default_product_image'),
                'status' => Product::STATUS_DRAFT,
            ]);

            return $gallery->add($product, $request->file('images', []));
        }, 3);

        return response()->json($product, 201);
    }

    public function owned(Request $request, ProductReadiness $readiness): JsonResponse
    {
        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
        $query = $request->user()->products()
            ->with(['images', 'variants'])
            ->withReviewSummary()
            ->latest()
            ->latest('id');
        $products = $query->cursorPaginate((int) ($filters['per_page'] ?? 5));
        collect($products->items())->each(
            fn (Product $product) => $product->setAttribute('readiness', $readiness->evaluate($product)),
        );

        return response()->json([
            'data' => $products->items(),
            'pagination' => [
                'next_cursor' => $products->nextCursor()?->encode(),
                'has_more' => $products->hasMorePages(),
                'per_page' => $products->perPage(),
            ],
        ]);
    }

    public function update(
        StoreProductRequest $request,
        Product $product,
        RentalCapacity $capacity,
        ProductGallery $gallery,
        ProductVariantInventory $inventory,
    ): JsonResponse {
        $attributes = $this->normalizePricing($request->safe()->except(['images']), $product);
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

        $updated = DB::transaction(function () use ($request, $product, $attributes, $capacity, $gallery, $inventory): Product {
            $store = Store::query()->where('user_id', $request->user()->id)->lockForUpdate()->first();
            abort_unless($store?->isApproved(), 403, 'Toko harus aktif untuk mengubah listing.');
            $lockedProduct = Product::query()
                ->whereKey($product->id)
                ->where('seller_id', $request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_if($lockedProduct->status === Product::STATUS_ARCHIVED, 422, 'Listing yang sudah diarsipkan tidak dapat diubah.');

            if ($lockedProduct->type === Product::TYPE_RENTAL && (
                array_key_exists('stock', $attributes) || array_key_exists('type', $attributes)
            )) {
                $from = today(config('app.timezone'))->toDateString();
                $allocatedProductCapacity = $capacity->peak($lockedProduct, $from);
                if (($attributes['type'] ?? Product::TYPE_RENTAL) !== Product::TYPE_RENTAL && $allocatedProductCapacity > 0) {
                    throw ValidationException::withMessages([
                        'type' => 'Jenis produk tidak dapat diubah selama masih ada reservasi atau blok sewa aktif.',
                    ]);
                }
                $defaultVariantId = $lockedProduct->variants()->where('is_default', true)->value('id');
                $allocatedVariantCapacity = $defaultVariantId === null
                    ? $allocatedProductCapacity
                    : $capacity->peak($lockedProduct, $from, null, (int) $defaultVariantId);
                if (array_key_exists('stock', $attributes) && (int) $attributes['stock'] < $allocatedVariantCapacity) {
                    throw ValidationException::withMessages([
                        'stock' => "Stok minimal {$allocatedVariantCapacity} karena sudah dialokasikan untuk reservasi atau blok sewa aktif.",
                    ]);
                }
            }

            $stock = array_key_exists('stock', $attributes) ? (int) $attributes['stock'] : null;
            unset($attributes['stock']);
            $lockedProduct->update($attributes);
            if ($stock !== null) {
                $inventory->updateDefaultStock($lockedProduct, $stock);
            }

            $updated = $gallery->add($lockedProduct, $request->file('images', []));

            return $this->moderation->markContentChanged($updated);
        }, 3);

        return response()->json($updated);
    }

    public function updateStatus(Request $request, Product $product, ProductLifecycle $lifecycle): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                Product::STATUS_DRAFT,
                Product::STATUS_PUBLISHED,
                Product::STATUS_PAUSED,
                Product::STATUS_ARCHIVED,
            ])],
        ]);

        return response()->json($lifecycle->transition($request->user(), $product, $validated['status']));
    }

    public function preview(Request $request, Product $product, ProductReadiness $readiness): JsonResponse
    {
        $this->authorizeOwner($request, $product);
        $product->load(['images', 'variants', 'store:id,slug,name,status']);
        $product->setAttribute('readiness', $readiness->evaluate($product));

        return response()->json($product);
    }

    public function destroy(Request $request, Product $product, ProductLifecycle $lifecycle): JsonResponse
    {
        $lifecycle->transition($request->user(), $product, Product::STATUS_ARCHIVED);

        return response()->json(null, 204);
    }

    public function addImages(Request $request, Product $product, ProductGallery $gallery): JsonResponse
    {
        $this->authorizeOwner($request, $product);
        $validated = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:8'],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ]);

        $updated = $gallery->add($product, $validated['images']);

        return response()->json($this->moderation->markContentChanged($updated));
    }

    public function reorderImages(Request $request, Product $product, ProductGallery $gallery): JsonResponse
    {
        $this->authorizeOwner($request, $product);
        $validated = $request->validate([
            'image_ids' => ['required', 'array', 'min:1', 'max:8'],
            'image_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $updated = $gallery->reorder($product, $validated['image_ids']);

        return response()->json($this->moderation->markContentChanged($updated));
    }

    public function deleteImage(Request $request, Product $product, ProductImage $image, ProductGallery $gallery): JsonResponse
    {
        $this->authorizeOwner($request, $product);
        abort_unless($image->product_id === $product->id, 404);

        $updated = $gallery->remove($product, $image);

        return response()->json($this->moderation->markContentChanged($updated));
    }

    public function addVariant(Request $request, Product $product, ProductVariantInventory $inventory): JsonResponse
    {
        $this->authorizeOwner($request, $product);
        $validated = $request->validate($this->variantRules($product));
        $validated['sku'] = Str::upper(trim($validated['sku']));

        $updated = $inventory->add($product, $validated);

        return response()->json($this->moderation->markContentChanged($updated), 201);
    }

    public function updateVariant(
        Request $request,
        Product $product,
        ProductVariant $variant,
        ProductVariantInventory $inventory,
    ): JsonResponse {
        $this->authorizeOwner($request, $product);
        abort_unless($variant->product_id === $product->id, 404);
        $validated = $request->validate($this->variantRules($product, $variant));
        if (isset($validated['sku'])) {
            $validated['sku'] = Str::upper(trim($validated['sku']));
        }

        $updated = $inventory->update($product, $variant, $validated);

        return response()->json($this->moderation->markContentChanged($updated));
    }

    public function deleteVariant(
        Request $request,
        Product $product,
        ProductVariant $variant,
        ProductVariantInventory $inventory,
    ): JsonResponse {
        $this->authorizeOwner($request, $product);
        abort_unless($variant->product_id === $product->id, 404);

        $updated = $inventory->remove($product, $variant);

        return response()->json($this->moderation->markContentChanged($updated));
    }

    private function authorizeOwner(Request $request, Product $product): void
    {
        abort_unless($product->seller_id === $request->user()?->id, 403);
        abort_unless($request->user()->store?->isApproved(), 403, 'Toko harus aktif untuk mengelola galeri.');
        abort_if($product->status === Product::STATUS_ARCHIVED, 422, 'Listing yang sudah diarsipkan tidak dapat diubah.');
    }

    /** @return array<string, list<mixed>> */
    private function variantRules(Product $product, ?ProductVariant $variant = null): array
    {
        $presence = $variant ? 'sometimes' : 'required';

        return [
            'sku' => [
                $presence, 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('product_variants', 'sku')->where('product_id', $product->id)->ignore($variant?->id),
            ],
            'label' => [$presence, 'string', 'max:100'],
            'size' => ['sometimes', 'nullable', 'string', 'max:40'],
            'color' => ['sometimes', 'nullable', 'string', 'max:50'],
            'condition' => [$presence, Rule::in([
                ProductVariant::CONDITION_NEW,
                ProductVariant::CONDITION_LIKE_NEW,
                ProductVariant::CONDITION_GOOD,
                ProductVariant::CONDITION_FAIR,
            ])],
            'stock' => [$presence, 'integer', 'min:0', 'max:10000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizePricing(array $attributes, ?Product $product = null): array
    {
        $type = $attributes['type'] ?? $product?->type;
        if ($type === Product::TYPE_SALE) {
            $attributes['price'] = $attributes['sale_price'] ?? $product?->sale_price;
            $attributes['rental_price_per_day'] = null;
            $attributes['rental_deposit'] = null;
            $attributes['rental_min_days'] = null;
            $attributes['rental_max_days'] = null;
        } elseif ($type === Product::TYPE_RENTAL) {
            $attributes['price'] = $attributes['rental_price_per_day'] ?? $product?->rental_price_per_day;
            $attributes['sale_price'] = null;
        }

        return $attributes;
    }
}
