<?php

namespace App\Http\Controllers\Backoffice;

use App\Models\Product;
use App\Models\Store;
use App\Services\ProductModeration;
use App\Services\StoreLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Operator mutations: remove a listing, restore it, suspend a store, bring it back.
 *
 * Every action requires a reason and a fresh step-up confirmation, and every action writes an
 * append-only audit row with a redacted before/after pair. Nothing here deletes a row: a takedown
 * is a status change, so the listing, its orders, and its reviews all survive and stay traceable.
 */
class ModerationController extends BackofficeController
{
    public function takedownProduct(Request $request, Product $product, ProductModeration $moderation): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $updated = $moderation->takedown($request->user(), $product, $validated['reason'], $request);

        return response()->json(['product' => $this->productPayload($updated)]);
    }

    public function restoreProduct(Request $request, Product $product, ProductModeration $moderation): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $moderation->restore($request->user(), $product, $validated['reason'] ?? null, $request);

        return response()->json(['product' => $this->productPayload($updated)]);
    }

    public function suspendStore(Request $request, Store $store, StoreLifecycle $lifecycle): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $updated = $lifecycle->review($request->user(), $store, Store::STATUS_SUSPENDED, $validated['reason'], $request);

        return response()->json(['store' => $this->storePayload($updated)]);
    }

    public function reactivateStore(Request $request, Store $store, StoreLifecycle $lifecycle): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $updated = $lifecycle->review($request->user(), $store, Store::STATUS_APPROVED, $validated['reason'], $request);

        return response()->json(['store' => $this->storePayload($updated)]);
    }

    /**
     * Listings an operator has removed, so the console can offer a restore without an id hunt.
     */
    public function takenDownProducts(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $products = Product::query()
            ->where('status', Product::STATUS_TAKEN_DOWN)
            ->with('store:id,slug,name,status')
            ->orderByDesc('taken_down_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['per_page'] ?? 20));

        return response()->json($this->paged($products, fn (Product $product): array => $this->productPayload($product) + [
            'takedown_reason' => $product->takedown_reason,
            'taken_down_at' => $product->taken_down_at,
        ]));
    }

    /**
     * Stop (or restart) money leaving for a store. A freeze blocks the payout step; it does not
     * move any rupiah, so the ledger stays the single record of what is owed.
     */
    public function freezePayout(Request $request, Store $store, StoreLifecycle $lifecycle): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $updated = $lifecycle->setPayoutFreeze($request->user(), $store, true, $validated['reason'], $request);

        return response()->json(['store' => $this->storePayload($updated)]);
    }

    public function unfreezePayout(Request $request, Store $store, StoreLifecycle $lifecycle): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $updated = $lifecycle->setPayoutFreeze($request->user(), $store, false, $validated['reason'], $request);

        return response()->json(['store' => $this->storePayload($updated)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'status' => $product->status,
            'moderation_hold_status' => $product->moderation_hold_status,
            'store' => $product->store ? [
                'id' => $product->store->id,
                'slug' => $product->store->slug,
                'name' => $product->store->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storePayload(Store $store): array
    {
        return [
            'id' => $store->id,
            'slug' => $store->slug,
            'name' => $store->name,
            'status' => $store->status,
            'review_note' => $store->review_note,
            'payout_frozen' => (bool) $store->payout_frozen,
            'payout_freeze_reason' => $store->payout_frozen ? $store->payout_freeze_reason : null,
            'payout_frozen_at' => $store->payout_frozen_at,
        ];
    }
}
