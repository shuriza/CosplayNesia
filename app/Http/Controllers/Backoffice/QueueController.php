<?php

namespace App\Http\Controllers\Backoffice;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Read-only queue health.
 *
 * Failed job payloads and exceptions can contain secrets or PII, so they are never returned: the
 * list exposes counts and a redacted exception summary only. Retry/delete remain operator actions
 * performed via artisan, not through this console.
 */
class QueueController extends BackofficeController
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canViewBackofficeQueue(), 403);

        $filters = $request->validate([
            'queue' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $queue = $filters['queue'] ?? 'default';

        $pending = (int) DB::table('jobs')->where('queue', $queue)->count();
        $failedQuery = DB::table('failed_jobs')->when(
            $filters['queue'] ?? null,
            fn ($query, string $queue) => $query->where('queue', $queue),
        );

        $failed = $failedQuery
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['per_page'] ?? 20));

        return response()->json([
            'pending' => $pending,
            'failed_total' => (int) DB::table('failed_jobs')->count(),
            ...$this->paged($failed, fn ($job): array => [
                'id' => $job->id,
                'uuid' => $job->uuid,
                'connection' => $job->connection,
                'queue' => $job->queue,
                'failed_at' => $job->failed_at,
                'exception_summary' => $this->summarize($job->exception),
            ]),
        ]);
    }

    /**
     * First line of the exception only, truncated: enough to triage, not enough to leak a payload.
     */
    private function summarize(?string $exception): ?string
    {
        if ($exception === null) {
            return null;
        }

        $firstLine = Str::before($exception, "\n");

        return Str::limit($firstLine, 200);
    }
}
