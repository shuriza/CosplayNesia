<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductModeration
{
    public function __construct(
        private readonly ProductReadiness $readiness,
        private readonly SecurityEventRecorder $events,
    ) {}

    public function markContentChanged(Product $product): Product
    {
        $attributes = [
            'content_version' => $product->content_version + 1,
            'submitted_for_review_at' => null,
            'moderated_at' => null,
            'moderated_by' => null,
            'rejection_reason' => null,
        ];
        if (in_array($product->status, [Product::STATUS_PUBLISHED, Product::STATUS_PAUSED, Product::STATUS_REJECTED], true)) {
            $attributes['status'] = Product::STATUS_DRAFT;
            $attributes['published_at'] = null;
        }
        $product->forceFill($attributes)->save();

        return $product->refresh()->load(['images', 'variants', 'store:id,slug,name,status']);
    }

    public function submit(User $seller, Product $product, ?Request $request = null): Product
    {
        return DB::transaction(function () use ($seller, $product, $request): Product {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->seller_id === $seller->id, 403);
            $this->readiness->assertReady($locked);

            if ($locked->status === Product::STATUS_PAUSED && $locked->approved_version === $locked->content_version) {
                $locked->forceFill(['status' => Product::STATUS_PUBLISHED, 'published_at' => now()])->save();

                return $locked->refresh();
            }
            if ($locked->status !== Product::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => 'Hanya listing draft yang dapat diajukan untuk review.']);
            }
            if ($locked->submitted_for_review_at !== null) {
                throw ValidationException::withMessages(['status' => 'Listing ini sudah menunggu review moderator.']);
            }

            $locked->forceFill([
                'submitted_for_review_at' => now(),
                'moderated_at' => null,
                'moderated_by' => null,
                'rejection_reason' => null,
            ])->save();
            $this->events->record($seller, 'product.submitted', $request, [
                'product_id' => $locked->id,
                'content_version' => $locked->content_version,
            ]);

            return $locked->refresh();
        }, 3);
    }

    public function review(User $reviewer, Product $product, string $decision, ?string $reason, ?Request $request = null): Product
    {
        abort_unless($reviewer->canReviewListings(), 403);

        return DB::transaction(function () use ($reviewer, $product, $decision, $reason, $request): Product {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Product::STATUS_DRAFT || $locked->submitted_for_review_at === null) {
                throw ValidationException::withMessages(['status' => 'Listing tidak sedang menunggu review.']);
            }

            if ($decision === Product::STATUS_REJECTED && ! filled($reason)) {
                throw ValidationException::withMessages(['reason' => 'Alasan penolakan wajib diisi.']);
            }

            $approved = $decision === Product::STATUS_PUBLISHED;
            $locked->forceFill([
                'status' => $decision,
                'approved_version' => $approved ? $locked->content_version : $locked->approved_version,
                'submitted_for_review_at' => null,
                'moderated_at' => now(),
                'moderated_by' => $reviewer->id,
                'rejection_reason' => $approved ? null : $reason,
                'published_at' => $approved ? now() : null,
            ])->save();
            $this->events->record($reviewer, $approved ? 'product.approved' : 'product.rejected', $request, [
                'product_id' => $locked->id,
                'content_version' => $locked->content_version,
                'reason' => $approved ? null : $reason,
            ]);

            return $locked->refresh()->load(['images', 'variants', 'store:id,slug,name,status']);
        }, 3);
    }
}
