<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreLifecycle
{
    public function __construct(
        private readonly SecurityEventRecorder $events,
        private readonly AdminAuditRecorder $audit,
    ) {}

    public function review(User $admin, Store $store, string $target, ?string $note, ?Request $request = null): Store
    {
        return DB::transaction(function () use ($admin, $store, $target, $note, $request): Store {
            // Global mutation lock order: relevant user, store, then products by id.
            $owner = User::query()->whereKey($store->user_id)->lockForUpdate()->firstOrFail();
            $locked = Store::query()->lockForUpdate()->findOrFail($store->id);
            $valid = match (true) {
                $locked->status === Store::STATUS_SUBMITTED => in_array($target, [Store::STATUS_APPROVED, Store::STATUS_REJECTED], true),
                $locked->status === Store::STATUS_APPROVED => $target === Store::STATUS_SUSPENDED,
                // Reactivation is the only way back from a suspension, and it is operator-only.
                $locked->status === Store::STATUS_SUSPENDED => $target === Store::STATUS_APPROVED,
                default => false,
            };
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
            $previous = $locked->status;
            $locked->update($attributes);
            $this->events->record($admin, 'store.'.$target, $request, ['store_id' => $locked->id]);
            $this->audit->recordChange(
                actor: $admin,
                action: 'store.'.$target,
                targetType: 'store',
                targetId: $locked->id,
                before: ['status' => $previous],
                after: ['status' => $target],
                keys: ['status'],
                reason: $note,
                request: $request,
            );

            return $locked->fresh();
        }, 3);
    }

    /**
     * Freeze or release payouts for a store.
     *
     * A freeze is a flag on the store plus an audit row; it never touches the ledger. The ledger
     * stays the authoritative record of what is owed — a freeze only stops the (not yet built)
     * payout step from disbursing it. That separation matters: if a freeze mutated balances, the
     * reconciliation report could no longer prove that every rupiah has a source and a target.
     *
     * Idempotent in both directions: freezing an already-frozen store re-records the reason rather
     * than failing, because an operator retrying a slow request has already achieved that state.
     */
    public function setPayoutFreeze(
        User $operator,
        Store $store,
        bool $frozen,
        string $reason,
        ?Request $request = null,
    ): Store {
        abort_unless($operator->hasCapability(User::CAPABILITY_FINANCE), 403);

        return DB::transaction(function () use ($operator, $store, $frozen, $reason, $request): Store {
            $locked = Store::query()->lockForUpdate()->findOrFail($store->id);
            $previous = (bool) $locked->payout_frozen;

            $locked->forceFill([
                'payout_frozen' => $frozen,
                'payout_freeze_reason' => $frozen ? $reason : null,
                'payout_frozen_at' => $frozen ? ($locked->payout_frozen_at ?? now()) : null,
                'payout_frozen_by' => $frozen ? $operator->id : null,
            ])->save();

            $action = $frozen ? 'store.payout_frozen' : 'store.payout_unfrozen';
            $this->events->record($operator, $action, $request, [
                'store_id' => $locked->id,
                'reason' => $reason,
            ]);
            $this->audit->recordChange(
                actor: $operator,
                action: $action,
                targetType: 'store',
                targetId: $locked->id,
                before: ['payout_frozen' => $previous],
                after: ['payout_frozen' => $frozen],
                keys: ['payout_frozen'],
                reason: $reason,
                request: $request,
            );

            return $locked->fresh();
        }, 3);
    }
}
