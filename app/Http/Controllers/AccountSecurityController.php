<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountPasswordRequest;
use App\Models\AccountSession;
use App\Models\User;
use App\Services\AccountSessionManager;
use App\Services\SecurityEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountSecurityController extends Controller
{
    public function sessions(Request $request, AccountSessionManager $sessions): JsonResponse
    {
        $current = $sessions->current($request, $request->user());
        $entries = $request->user()->accountSessions()
            ->whereNull('revoked_at')
            ->latest('last_seen_at')
            ->get()
            ->map(fn (AccountSession $session): array => [
                'id' => $session->id,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_seen_at' => $session->last_seen_at,
                'is_current' => $session->is($current),
            ]);

        return response()->json(['data' => $entries]);
    }

    public function revokeSession(
        Request $request,
        AccountSession $session,
        AccountSessionManager $sessions,
        SecurityEventRecorder $events,
    ): JsonResponse {
        abort_unless($session->user_id === $request->user()->id, 404);
        $current = $sessions->current($request, $request->user());
        abort_if($session->is($current), 409, 'Gunakan keluar untuk mengakhiri sesi saat ini.');
        if ($session->revoked_at === null) {
            $session->update(['revoked_at' => now()]);
            $events->record($request->user(), 'session.revoked', $request, ['session_id' => $session->id]);
        }

        return response()->json(null, 204);
    }

    public function revokeAll(Request $request, AccountSessionManager $sessions, SecurityEventRecorder $events): JsonResponse
    {
        $user = $request->user();
        $sessions->revokeAll($user);
        $events->record($user, 'sessions.revoked_all', $request);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['csrf_token' => $request->session()->token()]);
    }

    public function acceptLegal(Request $request, SecurityEventRecorder $events): JsonResponse
    {
        $user = DB::transaction(function () use ($request, $events): User {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $user->update([
                'terms_version' => config('cosplaynesia.legal.terms_version'),
                'privacy_version' => config('cosplaynesia.legal.privacy_version'),
                'rental_policy_version' => config('cosplaynesia.legal.rental_policy_version'),
                'legal_accepted_at' => now(),
            ]);
            $events->record($user, 'legal.accepted', $request, [
                'terms_version' => $user->terms_version,
                'privacy_version' => $user->privacy_version,
                'rental_policy_version' => $user->rental_policy_version,
            ]);

            return $user->fresh();
        }, 3);
        Auth::setUser($user);

        return response()->json(['user' => [...$user->toArray(), 'pending_email' => $user->pending_email]]);
    }

    public function deactivate(
        AccountPasswordRequest $request,
        AccountSessionManager $sessions,
        SecurityEventRecorder $events,
    ): JsonResponse {
        $user = DB::transaction(function () use ($request, $sessions, $events): User {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $events->record($user, 'account.deactivated', $request);
            $user->products()->update(['is_active' => false]);
            $sessions->revokeAll($user);
            $user->forceFill([
                'name' => 'Akun dinonaktifkan',
                'email' => 'deleted+'.$user->id.'+'.Str::lower(Str::random(12)).'@invalid.local',
                'pending_email' => null,
                'pending_email_token_hash' => null,
                'pending_email_requested_at' => null,
                'remember_token' => Str::random(60),
                'password' => Str::random(64),
                'deactivated_at' => now(),
                'anonymized_at' => now(),
            ])->save();

            return $user;
        }, 3);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Akun telah dinonaktifkan.', 'user_id' => $user->id]);
    }
}
