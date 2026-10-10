<?php

namespace App\Http\Controllers\Backoffice;

use App\Models\AdminAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only view of the admin audit trail. There is intentionally no endpoint to edit or delete a
 * log entry; the model rejects updates and deletes outright.
 */
class AuditController extends BackofficeController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'actor_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:60'],
            'target_type' => ['nullable', 'string', 'max:60'],
            'target_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $logs = AdminAuditLog::query()
            ->with('actor:id,name,role')
            ->when($filters['actor_id'] ?? null, fn ($query, int $id) => $query->where('actor_id', $id))
            ->when($filters['action'] ?? null, fn ($query, string $action) => $query->where('action', $action))
            ->when($filters['target_type'] ?? null, fn ($query, string $type) => $query->where('target_type', $type))
            ->when($filters['target_id'] ?? null, fn ($query, int $id) => $query->where('target_id', $id))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['per_page'] ?? 20));

        return response()->json($this->paged($logs, fn (AdminAuditLog $log): array => [
            'id' => $log->id,
            'actor' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->name] : null,
            'actor_role' => $log->actor_role,
            'action' => $log->action,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'reason' => $log->reason,
            'context' => $log->context,
            'request_id' => $log->request_id,
            'occurred_at' => $log->occurred_at,
        ]));
    }
}
