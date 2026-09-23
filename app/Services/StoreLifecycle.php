<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreLifecycle
{
    public function __construct(private readonly SecurityEventRecorder $events) {}

    public function review(User $admin, Store $store, string $target, ?string $note, ?Request $request = null): Store
    {
        return DB::transaction(function () use ($admin, $store, $target, $note, $request): Store {
            // Global mutation lock order: relevant user, store, then products by id.
            $owner = User::query()->whereKey($store->user_id)->lockForUpdate()->firstOrFail();
            $locked = Store::query()->lockForUpdate()->findOrFail($store->id);
            $valid = $locked->status === Store::STATUS_SUBMITTED
                ? in_array($target, [Store::STATUS_APPROVED, Store::STATUS_REJECTED], true)
                : $locked->status === Store::STATUS_APPROVED && $target === Store::STATUS_SUSPENDED;
            abort_unless($valid, 409, 'Transisi status toko tidak diizinkan.');

            $attributes = [
                'status' => $target,
                'review_note' => $note,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ];
            if ($target === Store::STATUS_APPROVED) {
                $attributes['approved_at'] = now();
                $owner->update(['role' => User::ROLE_SELLER]);
            } elseif ($target === Store::STATUS_SUSPENDED) {
                $attributes['suspended_at'] = now();
                // Global lock order: store first, then products by id.
                $products = $locked->products()->orderBy('id')->lockForUpdate()->get();
                $products->where('status', Product::STATUS_PUBLISHED)
                    ->each->update(['status' => Product::STATUS_PAUSED]);
            }
            $locked->update($attributes);
            $this->events->record($admin, 'store.'.$target, $request, ['store_id' => $locked->id]);

            return $locked->fresh();
        }, 3);
    }
}
