<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SavedSearch;
use App\Models\UserNotification;
use App\Notifications\NotificationCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Runs every enabled saved search against the live catalog and notifies the shopper about
 * listings they have not been told about yet.
 *
 * Correctness rules:
 *  - Filters are re-validated with the exact rules the catalog endpoint uses, so a stale payload
 *    saved by an older UI can never widen into a scan the shopper did not ask for.
 *  - Matching reuses the same query shape as `ProductController::index`, because an alert that
 *    shows a listing the catalog would not is worse than a missed alert.
 *  - `last_matched_product_id` is a cursor into the catalog ordering (popular desc, id desc):
 *    a product is announced once per search, even if it stays visible on later scans.
 */
class SavedSearchScanner
{
    private const SCAN_LIMIT = 200;

    public function __construct(private readonly CatalogAvailability $availability) {}

    public function scan(?int $searchId = null): array
    {
        $searches = SavedSearch::query()
            ->with('user:id,name')
            ->where('alert_enabled', true)
            ->when($searchId !== null, fn ($query) => $query->whereKey($searchId))
            ->orderBy('id')
            ->limit(500)
            ->get();

        $summary = ['searches' => $searches->count(), 'matched' => 0, 'notified' => 0, 'errors' => 0];

        foreach ($searches as $search) {
            try {
                $result = $this->scanOne($search);
                $summary['matched'] += $result['matched'];
                $summary['notified'] += $result['notified'];
            } catch (\Throwable) {
                // One malformed search must not stop the rest of the batch; the failure count is
                // the operational signal that something needs looking at.
                $summary['errors']++;
            }
        }

        return $summary;
    }

    /**
     * @return array{matched: int, notified: int}
     */
    public function scanOne(SavedSearch $search): array
    {
        $filters = $this->validatedFilters($search->filters);
        $start = $filters['start_date'] ?? null;
        $end = $filters['end_date'] ?? null;
        $isAvailabilityAlert = $start !== null && $end !== null;

        $query = Product::query()
            ->published()
            ->where(function ($query): void {
                $query->whereNull('seller_id')->orWhereHas('store', fn ($store) => $store->where('status', 'approved'));
            })
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->search($term))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['size'] ?? null, fn ($query, $size) => $query->where(function ($nested) use ($size): void {
                $nested->where('size', $size)->orWhereHas('variants', fn ($variants) => $variants->where('size', $size));
            }))
            ->when($filters['city'] ?? null, fn ($query, $city) => $query->whereRaw('lower(city) = ?', [mb_strtolower($city)]))
            ->when(isset($filters['min_price']), fn ($query) => $query->where('price', '>=', $filters['min_price']))
            ->when(isset($filters['max_price']), fn ($query) => $query->where('price', '<=', $filters['max_price']))
            ->when($filters['condition'] ?? null, fn ($query, $condition) => $query->whereHas('variants', fn ($variants) => $variants->where('condition', $condition)))
            ->orderByDesc('popular')
            ->orderByDesc('id')
            // The scan cursor only ever moves forward, and the catalog ordering is the contract,
            // so a product cannot be re-announced after the shopper has seen its alert.
            ->where('id', '>', (int) ($search->last_matched_product_id ?? 0));

        $products = $query->limit(self::SCAN_LIMIT)->get();

        if ($isAvailabilityAlert) {
            $availableIds = $this->availability->availableProductIds(
                $products->pluck('id'),
                $start,
                $end,
                (int) ($filters['quantity'] ?? 1),
                $filters['size'] ?? null,
                $filters['condition'] ?? null,
            );
            $products = $products->whereIn('id', $availableIds)->values();
        }

        $matched = $products->count();
        $notified = 0;

        if ($matched > 0) {
            DB::transaction(function () use ($search, $products, $isAvailabilityAlert, &$notified): void {
                foreach ($products as $product) {
                    $notified += $this->notify($search, $product, $isAvailabilityAlert) ? 1 : 0;
                }
                $search->forceFill([
                    'last_matched_product_id' => (int) $products->max('id'),
                    'last_scanned_at' => now(),
                ])->save();
            }, 3);
        } else {
            $search->forceFill(['last_scanned_at' => now()])->save();
        }

        return ['matched' => $matched, 'notified' => $notified];
    }

    private function notify(SavedSearch $search, Product $product, bool $isAvailabilityAlert): bool
    {
        $type = $isAvailabilityAlert ? UserNotification::TYPE_AVAILABILITY_ALERT : UserNotification::TYPE_NEW_MATCH;
        $eventKey = sprintf(
            'saved-search:%d:%s:%d',
            $search->id,
            $isAvailabilityAlert ? 'avail' : 'match',
            $product->id,
        );

        // insertOrIgnore against the unique event_key makes re-scanning the same window cheap and
        // idempotent: a concurrent scheduler run cannot double-announce the same listing.
        $inserted = UserNotification::query()->insertOrIgnore([[
            'recipient_id' => $search->user_id,
            'type' => $type,
            'payload' => json_encode([
                'product_name' => $product->name,
                'product_id' => $product->id,
                'search_name' => $search->name,
                'seller_name' => $product->seller,
                'price' => (int) $product->price,
                'product_type' => $product->type,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'event_key' => $eventKey,
            'created_at' => now(),
        ]]);

        if ($inserted === 0) {
            return false;
        }

        $notification = UserNotification::query()->where('event_key', $eventKey)->first();
        if ($notification !== null && NotificationCatalog::isDeliverable($type)) {
            app(NotificationChannel::class)->enqueue($notification);
        }

        return true;
    }

    /**
     * Re-validates stored filters with the catalog's own rules. Stored payloads are input, not
     * configuration: they were written by a browser that could be outdated or tampered with.
     *
     * @return array<string, mixed>
     */
    private function validatedFilters(mixed $filters): array
    {
        $data = is_array($filters) ? $filters : [];
        $validator = validator($data, [
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::in(['Anime', 'Game', 'VTuber', 'Aksesoris'])],
            'type' => ['nullable', Rule::in([Product::TYPE_RENTAL, Product::TYPE_SALE])],
            'size' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:80'],
            'min_price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'max_price' => ['nullable', 'integer', 'min:0', 'max:100000000', 'gte:min_price'],
            'condition' => ['nullable', Rule::in(['new', 'like_new', 'good', 'fair'])],
            'start_date' => ['nullable', 'date', 'required_with:end_date'],
            'end_date' => ['nullable', 'date', 'required_with:start_date', 'after_or_equal:start_date'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        return array_filter($validator->validated(), fn ($value) => $value !== null && $value !== '');
    }
}
