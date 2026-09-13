<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductReviewRequest;
use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\RentalReservation;
use App\Models\UserNotification;
use App\Services\NotificationRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProductReviewController extends Controller
{
    public function store(
        StoreProductReviewRequest $request,
        Order $order,
        OrderItem $item,
        NotificationRecorder $notifications,
    ): JsonResponse {
        abort_unless($order->user_id === $request->user()->id, 403);
        abort_unless($item->order_id === $order->id, 404);

        $rating = (int) $request->validated('rating');
        $body = $request->reviewBody();

        try {
            $review = DB::transaction(function () use ($request, $order, $item, $rating, $body, $notifications): ProductReview {
                $lockedOrder = Order::query()
                    ->whereKey($order->id)
                    ->where('user_id', $request->user()->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $fulfillment = OrderFulfillment::query()
                    ->whereKey($item->fulfillment_id)
                    ->where('order_id', $lockedOrder->id)
                    ->lockForUpdate()
                    ->first();
                $lockedItem = OrderItem::query()
                    ->whereKey($item->id)
                    ->where('order_id', $lockedOrder->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $reservation = RentalReservation::query()
                    ->where('order_item_id', $lockedItem->id)
                    ->lockForUpdate()
                    ->first();
                $lockedItem->setRelation('fulfillment', $fulfillment);
                $lockedItem->setRelation('rentalReservation', $reservation);

                if (! $lockedItem->isFulfilledForReview() || $lockedItem->product_id === null) {
                    abort(409, 'Produk hanya dapat dinilai setelah pesanan selesai.');
                }
                if ($lockedItem->review()->exists()) {
                    abort(409, 'Produk pada pesanan ini sudah dinilai.');
                }

                $product = Product::query()->lockForUpdate()->find($lockedItem->product_id);
                if ($product === null) {
                    abort(409, 'Produk tidak lagi tersedia untuk dinilai.');
                }

                $created = $lockedItem->review()->create([
                    'product_id' => $product->id,
                    'seller_id' => $product->seller_id,
                    'user_id' => $request->user()->id,
                    'rating' => $rating,
                    'body' => $body,
                ]);

                $notifications->record([
                    'recipient_id' => $product->seller_id,
                    'actor_id' => $request->user()->id,
                    'order_id' => $lockedOrder->id,
                    'fulfillment_id' => $lockedItem->fulfillment_id,
                    'product_review_id' => $created->id,
                    'type' => UserNotification::TYPE_REVIEW_RECEIVED,
                    'payload' => [
                        'product_name' => $lockedItem->product_name,
                        'rating' => $rating,
                        'has_body' => $body !== null,
                    ],
                    'event_key' => "notify:review:{$created->id}:received",
                ]);

                return $created;
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                abort(409, 'Produk pada pesanan ini sudah dinilai.');
            }

            throw $exception;
        }

        $product = $review->product()->withReviewSummary()->firstOrFail();

        return response()->json([
            'message' => 'Terima kasih atas penilaianmu.',
            'review' => $review,
            'product' => $product,
        ], 201);
    }
}
