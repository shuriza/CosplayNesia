<?php

namespace App\Http\Controllers;

use App\Exceptions\FulfillmentTransitionNotAllowedException;
use App\Http\Requests\UpdateFulfillmentStatusRequest;
use App\Models\OrderFulfillment;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FulfillmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(OrderFulfillment::statuses())],
        ]);
        $fulfillments = OrderFulfillment::query()
            ->forSeller($request->user())
            ->with(['order.user:id,name', 'items.rentalReservation'])
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->get()
            ->map(fn (OrderFulfillment $fulfillment): array => $this->payload($fulfillment, false))
            ->values();

        return response()->json($fulfillments);
    }

    public function show(Request $request, OrderFulfillment $fulfillment): JsonResponse
    {
        abort_unless($fulfillment->seller_id === $request->user()->id, 403);

        $fulfillment->load(['order', 'items.rentalReservation']);

        return response()->json($this->payload($fulfillment, true));
    }

    public function updateStatus(
        UpdateFulfillmentStatusRequest $request,
        OrderFulfillment $fulfillment,
        CheckoutService $checkout,
    ): JsonResponse {
        try {
            $updated = $checkout->transition(
                $request->user(),
                $fulfillment,
                $request->validated('status'),
            );
        } catch (FulfillmentTransitionNotAllowedException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Status pesanan diperbarui.',
            'fulfillment' => $this->payload($updated, false),
        ]);
    }

    private function payload(OrderFulfillment $fulfillment, bool $detail): array
    {
        $items = $fulfillment->items;

        $payload = [
            'id' => $fulfillment->id,
            'order_id' => $fulfillment->order_id,
            'seller_id' => $fulfillment->seller_id,
            'seller_name' => $fulfillment->seller_name,
            'status' => $fulfillment->status,
            'status_changed_at' => $fulfillment->status_changed_at,
            'created_at' => $fulfillment->created_at,
            'subtotal' => $items->sum(fn ($item): int => (int) $item->unit_price * (int) $item->quantity),
            'items' => $items->map(fn ($item): array => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'product_type' => $item->product_type,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'rental_start_date' => $item->rental_start_date?->toDateString(),
                'rental_end_date' => $item->rental_end_date?->toDateString(),
                'rental_status' => $item->rentalReservation?->status,
            ])->values(),
            'available_transitions' => $this->availableTransitions($fulfillment),
        ];

        if (! $detail) {
            $payload['buyer'] = ['name' => $fulfillment->order?->user?->name ?? 'Pembeli'];
        }

        if ($detail) {
            $payload['handoff'] = [
                'recipient_name' => $fulfillment->order?->recipient_name,
                'recipient_phone' => $fulfillment->order?->recipient_phone,
                'address_line1' => $fulfillment->order?->address_line1,
                'address_line2' => $fulfillment->order?->address_line2,
                'city' => $fulfillment->order?->city,
                'province' => $fulfillment->order?->province,
                'postal_code' => $fulfillment->order?->postal_code,
                'handoff_note' => $fulfillment->order?->handoff_note,
            ];
        }

        return $payload;
    }

    private function availableTransitions(OrderFulfillment $fulfillment): array
    {
        return match ($fulfillment->status) {
            OrderFulfillment::STATUS_RECEIVED => [OrderFulfillment::STATUS_ACCEPTED, OrderFulfillment::STATUS_CANCELLED],
            OrderFulfillment::STATUS_ACCEPTED => [OrderFulfillment::STATUS_READY, OrderFulfillment::STATUS_CANCELLED],
            OrderFulfillment::STATUS_READY => [OrderFulfillment::STATUS_COMPLETED],
            default => [],
        };
    }
}
