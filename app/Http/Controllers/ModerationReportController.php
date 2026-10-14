<?php

namespace App\Http\Controllers;

use App\Http\Requests\ModerationReportRequest;
use App\Services\ModerationReports;
use Illuminate\Http\JsonResponse;

/**
 * Filing a report from the buyer/seller side.
 *
 * The response is deliberately uniform: it never says whether anyone has ever reported the target
 * before. If this endpoint confirmed "three other people also reported this listing", reporting
 * would become a way to read the moderation queue and a way to build a case against a rival seller.
 */
class ModerationReportController extends Controller
{
    public function store(ModerationReportRequest $request, ModerationReports $reports): JsonResponse
    {
        $validated = $request->validated();

        $reports->file(
            $request->user(),
            $validated['target_type'],
            (int) $validated['target_id'],
            $validated['category'],
            $validated['reason'],
        );

        return response()->json([
            'message' => 'Laporan diterima dan akan ditinjau oleh tim moderasi.',
        ], 201);
    }
}
