<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Middleware\RequireStepUp;
use App\Services\AdminAuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Step-up confirmation for the backoffice.
 *
 * Logging into the app is not sufficient to read sensitive data. The operator re-confirms their
 * password; the confirmation is stored server-side with a TTL. Both outcomes are audited so a
 * burst of failed confirmations is visible.
 */
class AuthController extends BackofficeController
{
    public function confirm(Request $request, AdminAuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($validated['password'], (string) $user->password)) {
            $audit->record($user, 'backoffice.step_up_failed', 'user', $user->id, null, [], $request);

            throw ValidationException::withMessages([
                'password' => ['Kata sandi salah.'],
            ]);
        }

        $request->session()->put(RequireStepUp::SESSION_KEY, now());
        $audit->record($user, 'backoffice.step_up_confirmed', 'user', $user->id, null, [], $request);

        $ttl = (int) config('cosplaynesia.backoffice.step_up_ttl_minutes', 15);

        return response()->json([
            'message' => 'Konfirmasi berhasil.',
            'expires_in_minutes' => $ttl,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $confirmedAt = $request->session()->get(RequireStepUp::SESSION_KEY);
        $ttl = (int) config('cosplaynesia.backoffice.step_up_ttl_minutes', 15);
        $active = $confirmedAt !== null && now()->diffInMinutes($confirmedAt, true) <= $ttl;

        return response()->json([
            'confirmed' => $active,
            'confirmed_at' => $active ? $confirmedAt : null,
            'expires_in_minutes' => $ttl,
        ]);
    }
}
