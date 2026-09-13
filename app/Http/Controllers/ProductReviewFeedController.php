<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductReviewFeedController extends Controller
{
    public function index(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->is_active, 404);

        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $reviews = ProductReview::query()
            ->forPublicFeed($product)
            ->cursorPaginate((int) ($filters['per_page'] ?? 5));
        $summary = $product->newQuery()->withReviewSummary()->findOrFail($product->id);

        return response()->json([
            'product_id' => $product->id,
            'summary' => [
                'rating' => $summary->rating === null ? null : round((float) $summary->rating, 2),
                'review_count' => (int) $summary->review_count,
                'distribution' => $product->ratingDistribution(),
            ],
            'data' => collect($reviews->items())
                ->map(fn (ProductReview $review): array => [
                    'id' => $review->id,
                    'rating' => $review->rating,
                    'body' => $review->body,
                    'reviewer_label' => $review->reviewerLabel(),
                    'seller_reply' => $review->seller_reply,
                    'seller_replied_at' => $review->seller_replied_at,
                    'created_at' => $review->created_at,
                ])
                ->values(),
            'pagination' => [
                'next_cursor' => $reviews->nextCursor()?->encode(),
                'has_more' => $reviews->hasMorePages(),
                'per_page' => $reviews->perPage(),
            ],
        ]);
    }
}
