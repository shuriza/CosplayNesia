<?php

namespace App\Http\Controllers\Backoffice;

use App\Models\Order;
use App\Models\OrderFulfillment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only order lookup.
 *
 * This is the support view: it can see every seller's fulfillment on an order (unlike the buyer
 * and seller endpoints, which are scoped to their side). Handoff PII is masked in lists and shown
 * in full only on the audited detail view.
 */
class OrderController extends BackofficeController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:30'],
            'user_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $orders = Order::query()
            ->with('user:id,name,email')
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->whereKey((int) $term))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['user_id'] ?? null, fn ($query, int $userId) => $query->where('user_id', $userId))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['per_page'] ?? 20));

        return response()->json($this->paged($orders, fn (Order $order): array => [
            'id' => $order->id,
            'status' => $order->status,
            'grand_total' => $order->grand_total,
            'total_amount' => $order->total_amount,
            'fee_currency' => $order->fee_currency,
            'fee_policy_version' => $order->fee_policy_version,
            'buyer' => $order->user ? [
                'id' => $order->user->id,
                'name' => $order->user->name,
                'email' => $this->maskEmail($order->user->email),
            ] : null,
            'created_at' => $order->created_at,
        ]));
    }

    public function show(Order $order): JsonResponse
    {
        $order->load([
            'user:id,name,email',
            'items',
            'fulfillments.items',
            'activities',
        ]);

        return response()->json([
            'id' => $order->id,
            'status' => $order->status,
            'total_amount' => $order->total_amount,
            'merchandise_subtotal' => $order->merchandise_subtotal,
            'shipping_fee' => $order->shipping_fee,
            'platform_fee' => $order->platform_fee,
            'rental_deposit' => $order->rental_deposit,
            'grand_total' => $order->grand_total,
            'fee_currency' => $order->fee_currency,
            'fee_policy_version' => $order->fee_policy_version,
            'created_at' => $order->created_at,
            'buyer' => $order->user ? [
                'id' => $order->user->id,
                'name' => $order->user->name,
                'email' => $order->user->email,
            ] : null,
            'handoff' => [
                'recipient_name' => $order->recipient_name,
                'recipient_phone' => $this->maskPhone($order->recipient_phone),
                'recipient_email' => $this->maskEmail($order->recipient_email),
                'city' => $order->city,
                'province' => $order->province,
            ],
            'items' => $order->items->map(fn ($item): array => [
                'id' => $item->id,
                'fulfillment_id' => $item->fulfillment_id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'product_type' => $item->product_type,
                'variant_sku' => $item->variant_sku,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'rental_start_date' => $item->rental_start_date,
                'rental_end_date' => $item->rental_end_date,
            ])->values(),
            'fulfillments' => $order->fulfillments->map(fn (OrderFulfillment $fulfillment): array => [
                'id' => $fulfillment->id,
                'seller_id' => $fulfillment->seller_id,
                'seller_name' => $fulfillment->seller_name,
                'status' => $fulfillment->status,
                'settlement_status' => $fulfillment->settlement_status,
                'settled_at' => $fulfillment->settled_at,
                'handoff_method' => $fulfillment->handoff_method,
                'items_count' => $fulfillment->items->count(),
            ])->values(),
            'activities' => $order->activities->map(fn ($activity): array => [
                'id' => $activity->id,
                'event_type' => $activity->event_type,
                'actor_role' => $activity->actor_role,
                'from_status' => $activity->from_status,
                'to_status' => $activity->to_status,
                'occurred_at' => $activity->occurred_at,
            ])->values(),
        ]);
    }
}
