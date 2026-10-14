<?php

namespace App\Http\Controllers\Backoffice;

use App\Models\SupportNote;
use App\Services\AdminAuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Private operator notes on a user, store, product, or order.
 *
 * Notes are write-and-read only from here. They are deliberately not exposed on any buyer- or
 * seller-facing endpoint: support context ("customer disputed this twice", "watch for chargeback")
 * would be damaging in a customer conversation, and the surest way to keep it private is to have
 * exactly one reader.
 *
 * The target is not foreign-keyed, so a note outlives the row it describes — which is the point:
 * the note explaining why an account was suspended must survive the account's deletion.
 */
class SupportNoteController extends BackofficeController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'target_type' => ['required', Rule::in(SupportNote::targetTypes())],
            'target_id' => ['required', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $notes = SupportNote::query()
            ->with('author:id,name,role')
            ->where('target_type', $filters['target_type'])
            ->where('target_id', $filters['target_id'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['per_page'] ?? 20));

        return response()->json($this->paged($notes, fn (SupportNote $note): array => [
            'id' => $note->id,
            'body' => $note->body,
            'author' => $note->author ? ['id' => $note->author->id, 'name' => $note->author->name] : null,
            'author_role' => $note->author_role,
            'occurred_at' => $note->occurred_at,
        ]));
    }

    public function store(Request $request, AdminAuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'target_type' => ['required', Rule::in(SupportNote::targetTypes())],
            'target_id' => ['required', 'integer', 'min:1'],
            'body' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $note = SupportNote::query()->create([
            'author_id' => $request->user()->id,
            'author_role' => $request->user()->role,
            'target_type' => $validated['target_type'],
            'target_id' => $validated['target_id'],
            'body' => $validated['body'],
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        // The note body itself is the record; the audit row tracks that a note was added, without
        // copying the private text into a second table.
        $audit->record(
            actor: $request->user(),
            action: 'support_note.created',
            targetType: $validated['target_type'],
            targetId: $validated['target_id'],
            request: $request,
        );

        return response()->json([
            'note' => [
                'id' => $note->id,
                'body' => $note->body,
                'author_role' => $note->author_role,
                'occurred_at' => $note->occurred_at,
            ],
        ], 201);
    }
}
