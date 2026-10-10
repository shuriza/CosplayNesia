<?php

namespace App\Http\Controllers\Backoffice;

use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only store lookup for moderation and support.
 *
 * The private KYC document and identity hash are never part of these payloads: the existing
 * document endpoint already gates and audits that access separately.
 */
class StoreController extends BackofficeController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $stores = Store::query()
            ->with('user:id,name,email,role')
            ->when($filters['q'] ?? null, function ($query, string $term): void {
                $like = '%'.$term.'%';
                $query->where(fn ($inner) => $inner
                    ->where('name', 'like', $like)
                    ->orWhere('slug', 'like', $like));
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['per_page'] ?? 20));

        return response()->json($this->paged($stores, fn (Store $store): array => [
            'id' => $store->id,
            'slug' => $store->slug,
            'name' => $store->name,
            'city' => $store->city,
            'status' => $store->status,
            'owner' => $store->user ? [
                'id' => $store->user->id,
                'name' => $store->user->name,
                'email' => $this->maskEmail($store->user->email),
            ] : null,
            'submitted_at' => $store->submitted_at,
            'reviewed_at' => $store->reviewed_at,
        ]));
    }

    public function show(Store $store): JsonResponse
    {
        $store->loadMissing('user:id,name,email,role');

        return response()->json([
            'id' => $store->id,
            'slug' => $store->slug,
            'name' => $store->name,
            'description' => $store->description,
            'city' => $store->city,
            'status' => $store->status,
            'policy' => $store->policy,
            'legal_name' => $store->legal_name,
            'review_note' => $store->review_note,
            'submitted_at' => $store->submitted_at,
            'approved_at' => $store->approved_at,
            'suspended_at' => $store->suspended_at,
            'reviewed_at' => $store->reviewed_at,
            'pickup_enabled' => $store->pickup_enabled,
            'owner' => $store->user ? [
                'id' => $store->user->id,
                'name' => $store->user->name,
                'email' => $store->user->email,
                'role' => $store->user->role,
            ] : null,
            'counts' => [
                'products' => $store->products()->count(),
            ],
        ]);
    }
}
