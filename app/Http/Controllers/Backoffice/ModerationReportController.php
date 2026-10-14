<?php

namespace App\Http\Controllers\Backoffice;

use App\Models\ModerationReport;
use App\Services\ModerationReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The operator queue: what has been reported, with the evidence snapshot attached.
 *
 * Listing is a read but is still step-up gated because the queue contains reported private content
 * (a message body, a review body) that is only appropriate for someone who just re-confirmed.
 * Resolving is the write side and always records who decided what, and why.
 */
class ModerationReportController extends BackofficeController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(ModerationReport::statuses())],
            'target_type' => ['nullable', Rule::in(ModerationReport::targetTypes())],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $reports = ModerationReport::query()
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->status($status))
            ->when($filters['target_type'] ?? null, fn ($query, string $type) => $query->where('target_type', $type))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['per_page'] ?? 20));

        return response()->json($this->paged($reports, fn (ModerationReport $report): array => $this->payload($report)));
    }

    public function show(ModerationReport $report): JsonResponse
    {
        return response()->json(['report' => $this->payload($report)]);
    }

    public function resolve(
        Request $request,
        ModerationReport $report,
        ModerationReports $reports,
    ): JsonResponse {
        $validated = $request->validate([
            // A report ends in one of two states; there is no "reopen", because reopening is a new
            // report against the same target, not an edit of the decision that was already made.
            'status' => ['required', Rule::in([ModerationReport::STATUS_RESOLVED, ModerationReport::STATUS_DISMISSED])],
            'resolution' => ['required', 'string', 'min:5', 'max:1000'],
            'action' => ['nullable', Rule::in(ModerationReport::resolutionActions())],
        ]);

        // Dismissing cannot carry a punitive action: a report that was rejected must not take down
        // the listing anyway, which would let the queue punish someone for being reported.
        $action = $validated['action'] ?? ModerationReport::ACTION_NONE;
        if ($validated['status'] === ModerationReport::STATUS_DISMISSED && $action !== ModerationReport::ACTION_NONE) {
            $action = ModerationReport::ACTION_NONE;
        }

        $resolved = $reports->resolve(
            $request->user(),
            $report,
            $validated['status'],
            $validated['resolution'],
            $action,
            $request,
        );

        return response()->json(['report' => $this->payload($resolved)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ModerationReport $report): array
    {
        return [
            'id' => $report->id,
            'target_type' => $report->target_type,
            'target_id' => $report->target_id,
            'category' => $report->category,
            'reason' => $report->reason,
            'evidence' => $report->evidence,
            'status' => $report->status,
            'resolution' => $report->resolution,
            'resolution_action' => $report->resolution_action,
            // The reporter is named to the operator but the reported party is not: a report is an
            // accusation, and the queue should not read like a dossier on the accused.
            'reporter' => $report->reporter ? [
                'id' => $report->reporter_id,
                'name' => $report->reporter->name,
                'role' => $report->reporter_role,
            ] : null,
            'resolved_at' => $report->resolved_at,
            'created_at' => $report->created_at,
        ];
    }
}
