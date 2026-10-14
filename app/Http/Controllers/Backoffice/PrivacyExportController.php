<?php

namespace App\Http\Controllers\Backoffice;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\AdminAuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Data export for a privacy request.
 *
 * The export is assembled from an explicit allowlist of fields. It deliberately omits anything that
 * is a secret or a security control rather than a fact about the person: password hashes, session
 * fingerprints, pending-email tokens, identity hashes, KYC document paths, and other people's data.
 * A privacy export that leaks a token or a counterparty's address is worse than no export.
 *
 * Reading it is a step-up action and is itself audited, because exporting an account is the kind of
 * thing an attacker with a borrowed session would try first.
 */
class PrivacyExportController extends BackofficeController
{
    public function show(Request $request, User $user, AdminAuditRecorder $audit): JsonResponse
    {
        $export = [
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'email_verified_at' => $user->email_verified_at,
                'legal_accepted_at' => $user->legal_accepted_at,
                'terms_version' => $user->terms_version,
                'privacy_version' => $user->privacy_version,
                'rental_policy_version' => $user->rental_policy_version,
                'created_at' => $user->created_at,
                'deactivated_at' => $user->deactivated_at,
                'anonymized_at' => $user->anonymized_at,
            ],
            'store' => $this->store($user),
            'listings' => $this->listings($user),
            'orders_placed' => $this->orders($user),
            'security_events' => $user->securityEvents()
                ->latest('id')
                ->limit(200)
                ->get()
                ->map(fn ($event): array => [
                    'type' => $event->type,
                    'created_at' => $event->created_at,
                    // Metadata is omitted: it can carry a domain, an id, or a fingerprint.
                ])
                ->all(),
        ];

        $audit->record(
            actor: $request->user(),
            action: 'privacy_export.read',
            targetType: 'user',
            targetId: $user->id,
            request: $request,
        );

        return response()->json(['export' => $export]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function store(User $user): ?array
    {
        $store = $user->store()->first();
        if ($store === null) {
            return null;
        }

        return [
            'slug' => $store->slug,
            'name' => $store->name,
            'description' => $store->description,
            'city' => $store->city,
            'status' => $store->status,
            'policy' => $store->policy,
            'legal_name' => $store->legal_name,
            'submitted_at' => $store->submitted_at,
            'approved_at' => $store->approved_at,
            'suspended_at' => $store->suspended_at,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listings(User $user): array
    {
        return Product::query()
            ->where('seller_id', $user->id)
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'series' => $product->series,
                'category' => $product->category,
                'type' => $product->type,
                'price' => $product->price,
                'status' => $product->status,
                'created_at' => $product->created_at,
            ])
            ->all();
    }

    /**
     * Buyer-side orders only. Fulfillment rows are omitted because their recipient name, phone, and
     * address belong to the buyer, not to this account.
     *
     * @return array<int, array<string, mixed>>
     */
    private function orders(User $user): array
    {
        return Order::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'status' => $order->status,
                'total_amount' => $order->total_amount,
                'merchandise_subtotal' => $order->merchandise_subtotal,
                'platform_fee' => $order->platform_fee,
                'fee_currency' => $order->fee_currency,
                'created_at' => $order->created_at,
            ])
            ->all();
    }
}
