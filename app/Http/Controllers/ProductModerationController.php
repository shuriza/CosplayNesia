<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ProductModeration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductModerationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canReviewListings(), 403);
        $products = Product::query()
            ->with(['images', 'variants', 'store:id,slug,name,status'])
            ->where('status', Product::STATUS_DRAFT)
            ->whereNotNull('submitted_for_review_at')
            ->oldest('submitted_for_review_at')
            ->oldest('id')
            ->cursorPaginate(20);

        return response()->json([
            'data' => $products->items(),
            'pagination' => [
                'next_cursor' => $products->nextCursor()?->encode(),
                'has_more' => $products->hasMorePages(),
            ],
        ]);
    }

    public function review(Request $request, Product $product, ProductModeration $moderation): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([Product::STATUS_PUBLISHED, Product::STATUS_REJECTED])],
            'reason' => [Rule::requiredIf($request->input('status') === Product::STATUS_REJECTED), 'nullable', 'string', 'max:1000'],
        ]);
        $reviewed = $moderation->review(
            $request->user(),
            $product,
            $validated['status'],
            $validated['reason'] ?? null,
            $request,
        );

        return response()->json($reviewed);
    }
}
