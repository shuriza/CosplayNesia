<?php

namespace App\Services;

use App\Models\AccountSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AccountSessionManager
{
    private const SESSION_KEY = 'account_session_token';

    public function ensure(Request $request, User $user): AccountSession
    {
        $token = (string) $request->session()->get(self::SESSION_KEY, '');
        $session = $token === '' ? null : AccountSession::query()
            ->where('session_fingerprint', hash('sha256', $token))
            ->first();

        if ($session && $session->user_id === $user->id && $session->revoked_at !== null) {
            abort(401, 'Sesi ini telah dicabut.');
        }

        if ($token === '' || ($session && $session->user_id !== $user->id)) {
            return $this->rotate($request, $user);
        }

        $fingerprint = hash('sha256', $token);
        $session ??= AccountSession::query()->firstOrCreate(
            ['session_fingerprint' => $fingerprint],
            [
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500) ?: null,
                'last_seen_at' => now(),
            ],
        );

        if ($session->last_seen_at->lt(now()->subMinutes(5))) {
            $session->update([
                'last_seen_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500) ?: null,
            ]);
        }

        return $session;
    }

    public function rotate(Request $request, User $user): AccountSession
    {
        $token = Str::random(64);
        $request->session()->put(self::SESSION_KEY, $token);

        return AccountSession::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'session_fingerprint' => hash('sha256', $token),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500) ?: null,
            'last_seen_at' => now(),
        ]);
    }

    public function current(Request $request, User $user): AccountSession
    {
        return $this->ensure($request, $user);
    }

    public function revokeOthers(User $user, string $currentId): int
    {
        return $user->accountSessions()
            ->whereKeyNot($currentId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function revokeAll(User $user): int
    {
        return $user->accountSessions()->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
