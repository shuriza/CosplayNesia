<?php

namespace App\Http\Controllers\Backoffice;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only account lookup for support and moderation.
 *
 * List rows mask emails; a full address is only returned on the detail view, which is a sensitive
 * read behind step-up and is audited. Nothing here mutates a user.
 */
class UserController extends BackofficeController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $users = User::query()
            ->when($filters['q'] ?? null, function ($query, string $term): void {
                $like = '%'.$term.'%';
                $query->where(fn ($inner) => $inner
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like));
            })
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->where('role', $role))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['per_page'] ?? 20));

        return response()->json($this->paged($users, fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $this->maskEmail($user->email),
            'role' => $user->role,
            'email_verified' => $user->email_verified_at !== null,
            'deactivated' => $user->deactivated_at !== null,
            'created_at' => $user->created_at,
        ]));
    }

    public function show(User $user): JsonResponse
    {
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'email_verified_at' => $user->email_verified_at,
            'deactivated_at' => $user->deactivated_at,
            'anonymized_at' => $user->anonymized_at,
            'legal_accepted_at' => $user->legal_accepted_at,
            'created_at' => $user->created_at,
            'counts' => [
                'orders' => $user->orders()->count(),
                'products' => $user->products()->count(),
                'fulfillments' => $user->fulfillments()->count(),
                'security_events' => $user->securityEvents()->count(),
            ],
            'store' => $user->store()->first(['id', 'slug', 'name', 'status']),
        ]);
    }
}
