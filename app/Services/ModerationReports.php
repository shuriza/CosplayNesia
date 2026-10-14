<?php

namespace App\Services;

use App\Models\FulfillmentMessage;
use App\Models\ModerationReport;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Filing and resolving reports.
 *
 * A report is stored with a bounded snapshot of what was reported rather than only a foreign key.
 * That is the whole point of "evidence": a seller who is reported is also the person most likely to
 * edit or delete the listing, so by the time an operator opens the queue the referenced row may no
 * longer say what it said. The snapshot is taken inside the same transaction as the report, so the
 * evidence and the accusation can never disagree.
 *
 * Filing deliberately does not notify the reported party. A notification on every report would turn
 * reporting into a way to harass a rival seller, and the reporter gains nothing from a reply.
 */
class ModerationReports
{
    public function __construct(
        private readonly AdminAuditRecorder $audit,
        private readonly ProductModeration $moderation,
    ) {}

    public function file(
        User $reporter,
        string $targetType,
        int $targetId,
        string $category,
        string $reason,
    ): ModerationReport {
        return DB::transaction(function () use ($reporter, $targetType, $targetId, $category, $reason): ModerationReport {
            // A report about something that does not exist would enter the queue with no evidence,
            // so it is refused rather than filed for an operator to discover later.
            $evidence = $this->evidence($targetType, $targetId);
            abort_if($evidence === null, 404, 'Yang dilaporkan tidak ditemukan.');
            // One open report per reporter per target. Without this, reporting becomes a way to
            // bury a listing in the operator queue, which is a griefing vector, not moderation.
            $duplicate = ModerationReport::query()
                ->where('reporter_id', $reporter->id)
                ->where('target_type', $targetType)
                ->where('target_id', $targetId)
                ->open()
                ->lockForUpdate()
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'target_id' => 'Kamu sudah melaporkan hal ini dan laporannya masih diproses.',
                ]);
            }

            return ModerationReport::query()->create([
                'reporter_id' => $reporter->id,
                'reporter_role' => $reporter->role,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'category' => $category,
                'reason' => $reason,
                'evidence' => $evidence,
                'status' => ModerationReport::STATUS_OPEN,
            ]);
        }, 3);
    }

    public function resolve(
        User $operator,
        ModerationReport $report,
        string $status,
        string $resolution,
        string $action,
        ?Request $request = null,
    ): ModerationReport {
        abort_unless($operator->canReviewListings(), 403);

        return DB::transaction(function () use ($operator, $report, $status, $resolution, $action, $request): ModerationReport {
            $locked = ModerationReport::query()->lockForUpdate()->findOrFail($report->id);
            if (! $locked->isOpen()) {
                throw ValidationException::withMessages([
                    'status' => 'Laporan ini sudah diselesaikan dan tidak dapat diubah lagi.',
                ]);
            }

            $previous = $locked->status;
            $locked->forceFill([
                'status' => $status,
                'resolution' => $resolution,
                'resolution_action' => $action,
                'resolved_by' => $operator->id,
                'resolved_at' => now(),
            ])->save();

            // A takedown resolution is applied, not just recorded: the outcome is the takedown.
            if ($action === ModerationReport::ACTION_TAKEDOWN
                && $locked->target_type === ModerationReport::TARGET_PRODUCT) {
                $product = Product::query()->find($locked->target_id);
                if ($product !== null) {
                    $this->moderation->takedown($operator, $product, $resolution, $request);
                }
            }

            $this->audit->recordChange(
                actor: $operator,
                action: 'moderation_report.'.$status,
                targetType: 'moderation_report',
                targetId: $locked->id,
                before: ['status' => $previous],
                after: ['status' => $status],
                keys: ['status'],
                reason: $resolution,
                request: $request,
            );

            return $locked->fresh();
        }, 3);
    }

    /**
     * Narrow snapshot of the reported thing. Ids and content only: no email, no phone, no address,
     * no KYC document, and nothing from a party who merely happens to be on the other side.
     *
     * @return array<string, mixed>|null
     */
    private function evidence(string $targetType, int $targetId): ?array
    {
        if ($targetType === ModerationReport::TARGET_PRODUCT) {
            $product = Product::query()->find($targetId);

            return $product === null ? null : [
                'product_id' => $product->id,
                'name' => $product->name,
                'series' => $product->series,
                'status' => $product->status,
                'seller_id' => $product->seller_id,
            ];
        }

        if ($targetType === ModerationReport::TARGET_USER) {
            $user = User::query()->find($targetId);

            // Name only. A report is not a licence to copy someone's contact details.
            return $user === null ? null : ['user_id' => $user->id, 'name' => $user->name];
        }

        if ($targetType === ModerationReport::TARGET_MESSAGE) {
            $message = FulfillmentMessage::query()->find($targetId);

            return $message === null ? null : [
                'message_id' => $message->id,
                'fulfillment_id' => $message->fulfillment_id,
                'sender_role' => $message->sender_role,
                'body' => mb_substr((string) $message->body, 0, 500),
            ];
        }

        $review = ProductReview::query()->find($targetId);

        return $review === null ? null : [
            'review_id' => $review->id,
            'rating' => $review->rating,
            'body' => $review->body === null ? null : mb_substr($review->body, 0, 500),
            'seller_reply' => $review->seller_reply === null ? null : mb_substr($review->seller_reply, 0, 500),
        ];
    }
}
