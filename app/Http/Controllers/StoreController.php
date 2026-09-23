<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewStoreRequest;
use App\Http\Requests\StoreLogisticsRequest;
use App\Http\Requests\StoreOnboardingRequest;
use App\Models\Store;
use App\Services\SecurityEventRecorder;
use App\Services\StoreLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoreController extends Controller
{
    public function updateLogistics(StoreLogisticsRequest $request): JsonResponse
    {
        $store = $request->user()->store;
        abort_unless($store?->isApproved(), 403, 'Toko harus disetujui sebelum mengatur pengiriman.');
        $store->update($request->validated());

        return response()->json(['store' => $this->ownerPayload($store->refresh())]);
    }

    public function show(Store $store): JsonResponse
    {
        abort_unless($store->isApproved(), 404);
        $products = $store->products()->published()->withReviewSummary()->latest()->latest('id')->cursorPaginate(12);

        return response()->json([
            'store' => $this->publicPayload($store),
            'products' => $products->items(),
            'pagination' => ['next_cursor' => $products->nextCursor()?->encode(), 'has_more' => $products->hasMorePages()],
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        $store = $request->user()->store;

        return response()->json(['store' => $store ? $this->ownerPayload($store) : null]);
    }

    public function submit(
        StoreOnboardingRequest $request,
        SecurityEventRecorder $events,
    ): JsonResponse {
        $user = $request->user();
        $current = Store::query()->where('user_id', $user->id)->first();
        abort_if($current && in_array($current->status, [Store::STATUS_SUBMITTED, Store::STATUS_APPROVED, Store::STATUS_SUSPENDED], true), 409, 'Pengajuan toko tidak dapat diubah pada status saat ini.');

        $path = $request->file('document')->store('seller-documents', 'private');
        $oldPath = $current?->document_path;
        $validated = $request->safe()->except(['document', 'identity_number']);
        try {
            $store = DB::transaction(function () use ($request, $user, $current, $validated, $path, $events): Store {
                $store = Store::query()->lockForUpdate()->find($current?->id) ?? new Store(['user_id' => $user->id]);
                $base = Str::slug($validated['name']) ?: 'store';
                $store->fill([
                    ...$validated,
                    'slug' => $store->exists ? $store->slug : $base.'-'.$user->id,
                    'status' => Store::STATUS_SUBMITTED,
                    'identity_number_hash' => hash_hmac('sha256', $request->validated('identity_number'), (string) config('app.key')),
                    'document_path' => $path,
                    'review_note' => null,
                    'submitted_at' => now(),
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                    'approved_at' => null,
                    'suspended_at' => null,
                ]);
                $store->save();
                $events->record($user, 'store.submitted', $request, ['store_id' => $store->id]);

                return $store->fresh();
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }
        if ($oldPath && $oldPath !== $path) {
            Storage::disk('private')->delete($oldPath);
        }

        return response()->json(['store' => $this->ownerPayload($store)], $current ? 200 : 201);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canReviewStores(), 403);
        $stores = Store::query()
            ->whereIn('status', [Store::STATUS_SUBMITTED, Store::STATUS_APPROVED])
            ->orderBy('status', 'desc')
            ->oldest('submitted_at')
            ->oldest('id')
            ->cursorPaginate(20);

        return response()->json(['data' => collect($stores->items())->map(fn (Store $store) => $this->adminPayload($store))]);
    }

    public function review(ReviewStoreRequest $request, Store $store, StoreLifecycle $lifecycle): JsonResponse
    {
        $reviewed = $lifecycle->review(
            $request->user(),
            $store,
            $request->validated('status'),
            $request->validated('note'),
            $request,
        );

        return response()->json(['store' => $this->adminPayload($reviewed)]);
    }

    public function document(Request $request, Store $store, SecurityEventRecorder $events)
    {
        abort_unless($request->user()->canReviewStores() || $store->user_id === $request->user()->id, 403);
        abort_unless($store->document_path && Storage::disk('private')->exists($store->document_path), 404);
        $events->record($request->user(), 'store.document_accessed', $request, [
            'store_id' => $store->id,
            'owner_access' => $store->user_id === $request->user()->id,
        ]);

        return Storage::disk('private')->download($store->document_path);
    }

    public function myDocument(Request $request, SecurityEventRecorder $events)
    {
        $store = Store::query()->where('user_id', $request->user()->id)->firstOrFail();

        return $this->document($request, $store, $events);
    }

    private function publicPayload(Store $store): array
    {
        return $store->only(['id', 'slug', 'name', 'description', 'city', 'logo_url', 'banner_url', 'operating_hours', 'policy', 'approved_at']);
    }

    private function ownerPayload(Store $store): array
    {
        return [
            ...$this->publicPayload($store),
            'status' => $store->status,
            'review_note' => $store->review_note,
            'submitted_at' => $store->submitted_at,
            'origin_address_line1' => $store->origin_address_line1,
            'origin_address_line2' => $store->origin_address_line2,
            'origin_province' => $store->origin_province,
            'origin_postal_code' => $store->origin_postal_code,
            'pickup_enabled' => $store->pickup_enabled,
            'pickup_instructions' => $store->pickup_instructions,
        ];
    }

    private function adminPayload(Store $store): array
    {
        return [...$this->ownerPayload($store), 'legal_name' => $store->legal_name, 'has_document' => $store->document_path !== null, 'user_id' => $store->user_id];
    }
}
