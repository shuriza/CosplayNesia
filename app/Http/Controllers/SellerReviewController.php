<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateReviewReplyRequest;
use App\Models\ProductReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:20'],
            'unanswered' => ['nullable', 'boolean'],
        ]);

        $reviews = ProductReview::query()
            ->forSellerInbox($request->user())
            ->when($filters['unanswered'] ?? false, fn ($query) => $query->whereNull('seller_reply'))
            ->cursorPaginate((int) ($filters['per_page'] ?? 5));

        return response()->json([
            'data' => collect($reviews->items())
                ->map(fn (ProductReview $review): array => $this->payload($review))
                ->values(),
            'pagination' => [
                'next_cursor' => $reviews->nextCursor()?->encode(),
                'has_more' => $reviews->hasMorePages(),
                'per_page' => $reviews->perPage(),
            ],
        ]);
    }

    public function update(UpdateReviewReplyRequest $request, ProductReview $review): JsonResponse
    {
        $updated = $this->mutate($request->user()->id, $review->id, [
            'seller_reply' => $request->validated('reply'),
            'seller_replied_at' => now(),
        ]);

        return response()->json([
            'message' => 'Balasan tersimpan.',
            'review' => $this->payload($updated),
        ]);
    }

    public function destroy(Request $request, ProductReview $review): JsonResponse
    {
        $updated = $this->mutate($request->user()->id, $review->id, [
            'seller_reply' => null,
            'seller_replied_at' => null,
        ]);

        return response()->json([
            'message' => 'Balasan dihapus.',
            'review' => $this->payload($updated),
        ]);
    }

    /**
     * Ownership is re-checked against the locked row so a concurrent listing transfer cannot
     * let a stale seller_id win the write.
     */
    private function mutate(int $sellerId, int $reviewId, array $attributes): ProductReview
    {
        return DB::transaction(function () use ($sellerId, $reviewId, $attributes): ProductReview {
            $locked = ProductReview::query()
                ->whereKey($reviewId)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($locked->seller_id === $sellerId, 403);

            $locked->update($attributes);

            return $locked->fresh(['user:id,name', 'orderItem:id,product_name']);
        }, 3);
    }

    private function payload(ProductReview $review): array
    {
        return [
            'id' => $review->id,
            'product_id' => $review->product_id,
            'product_name' => $review->orderItem?->product_name,
            'rating' => $review->rating,
            'body' => $review->body,
            'reviewer_label' => $review->reviewerLabel(),
            'seller_reply' => $review->seller_reply,
            'seller_replied_at' => $review->seller_replied_at,
            'created_at' => $review->created_at,
        ];
    }
}
