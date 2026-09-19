<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SecurityEventRecorder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): JsonResponse
    {
        return response()->json(['verified' => $request->user()->hasVerifiedEmail()]);
    }

    public function send(Request $request, SecurityEventRecorder $events): JsonResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
            $events->record($request->user(), 'email.verification_sent', $request);
        }

        return response()->json(['message' => 'Jika belum terverifikasi, email verifikasi telah dikirim.']);
    }

    public function verify(Request $request, User $user, string $hash, SecurityEventRecorder $events): JsonResponse|RedirectResponse
    {
        abort_unless(hash_equals($hash, sha1($user->getEmailForVerification())), 403);
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
            $events->record($user, 'email.verified', $request);
        }
        $verified = $user->fresh();

        if (! $request->expectsJson()) {
            return redirect('/?email_verified=1');
        }

        return response()->json(['message' => 'Email berhasil diverifikasi.', 'user' => $verified]);
    }

    public function confirmChange(Request $request, User $user, string $token, SecurityEventRecorder $events): JsonResponse|RedirectResponse
    {
        $updated = DB::transaction(function () use ($request, $user, $token, $events): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $valid = $locked->pending_email !== null
                && $locked->pending_email_token_hash !== null
                && $locked->pending_email_requested_at?->gte(now()->subMinutes(60))
                && hash_equals($locked->pending_email_token_hash, hash('sha256', $token));
            abort_unless($valid, 422, 'Permintaan perubahan email tidak valid atau kedaluwarsa.');
            abort_if(User::query()->where('email', $locked->pending_email)->whereKeyNot($locked->id)->exists(), 422, 'Email sudah digunakan.');

            $oldDomain = str($locked->email)->after('@')->lower()->toString();
            $newDomain = str($locked->pending_email)->after('@')->lower()->toString();
            $locked->forceFill([
                'email' => $locked->pending_email,
                'email_verified_at' => now(),
                'pending_email' => null,
                'pending_email_token_hash' => null,
                'pending_email_requested_at' => null,
            ])->save();
            $events->record($locked, 'email.changed', $request, ['from_domain' => $oldDomain, 'to_domain' => $newDomain]);

            return $locked->fresh();
        }, 3);

        if (! $request->expectsJson()) {
            return redirect('/?email_changed=1');
        }

        return response()->json(['message' => 'Email berhasil diperbarui.', 'user' => [...$updated->toArray(), 'pending_email' => null]]);
    }
}
