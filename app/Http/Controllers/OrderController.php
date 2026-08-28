<?php

namespace App\Http\Controllers;

use App\Exceptions\RentalCancellationNotAllowedException;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\OrderItem;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
        $query = $request->user()->orders()
            ->with(['items.rentalReservation', 'items.fulfillment', 'items.review', 'fulfillments'])
            ->latest()
            ->latest('id');
        $orders = $query->cursorPaginate((int) ($filters['per_page'] ?? 5));

        return response()->json([
            'data' => collect($orders->items())
                ->map(fn (Order $order): array => $this->payload($order, false))
                ->values(),
            'pagination' => [
                'next_cursor' => $orders->nextCursor()?->encode(),
                'has_more' => $orders->hasMorePages(),
                'per_page' => $orders->perPage(),
            ],
        ]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);

        $order->load(['activities', 'items.rentalReservation', 'items.fulfillment', 'items.review', 'fulfillments.items.rentalReservation']);

        return response()->json($this->payload($order, true, $request->user()->id));
    }

    public function cancelRental(
        Request $request,
        Order $order,
        OrderItem $item,
        CheckoutService $checkout,
    ): JsonResponse {
        try {
            $reservation = $checkout->cancelRental($request->user(), $order, $item);
        } catch (RentalCancellationNotAllowedException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Reservasi sewa dibatalkan.',
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'reservation' => $reservation,
        ]);
    }

    private function payload(Order $order, bool $detail, ?int $viewerId = null): array
    {
        $payload = [
            'id' => $order->id,
            'total_amount' => $order->total_amount,
            'status' => $order->status,
            'created_at' => $order->created_at,
            'items' => $order->items->map(fn (OrderItem $item): array => $this->itemPayload($item))->values(),
            'fulfillments' => $order->fulfillments->map(fn ($fulfillment): array => [
                'id' => $fulfillment->id,
                'seller_name' => $fulfillment->seller_name,
                'status' => $fulfillment->status,
            ])->values(),
        ];

        if ($detail) {
            $payload['handoff'] = [
                'recipient_name' => $order->recipient_name,
                'recipient_phone' => $order->recipient_phone,
                'recipient_email' => $order->recipient_email,
                'address_line1' => $order->address_line1,
                'address_line2' => $order->address_line2,
                'city' => $order->city,
                'province' => $order->province,
                'postal_code' => $order->postal_code,
                'handoff_note' => $order->handoff_note,
            ];
            $payload['timeline'] = $order->activities->map(fn (OrderActivity $activity): array => $this->timelinePayload($activity, $viewerId))->values();
        }

        return $payload;
    }

    private function itemPayload(OrderItem $item): array
    {
        return [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'name' => $item->product_name,
            'price' => $item->unit_price,
            'product_name' => $item->product_name,
            'unit_price' => $item->unit_price,
            'product_type' => $item->product_type,
            'quantity' => $item->quantity,
            'rental_start_date' => $item->rental_start_date?->toDateString(),
            'rental_end_date' => $item->rental_end_date?->toDateString(),
            'rental_status' => $item->rentalReservation?->status,
            'fulfillment_status' => $item->fulfillment?->status,
            'review' => $item->review ? [
                'rating' => $item->review->rating,
                'created_at' => $item->review->created_at,
            ] : null,
            'can_review' => $item->product_id !== null
                && $item->isFulfilledForReview()
                && $item->review === null,
        ];
    }
}
