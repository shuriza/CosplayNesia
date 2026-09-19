<?php

namespace App\Http\Controllers;

use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use App\Services\AccountSessionManager;
use App\Services\SecurityEventRecorder;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    public function request(ForgotPasswordRequest $request, SecurityEventRecorder $events): JsonResponse
    {
        Password::sendResetLink($request->only('email'));
        $events->record(User::query()->where('email', $request->validated('email'))->first(), 'password.reset_requested', $request);

        return response()->json(['message' => 'Jika email terdaftar, tautan reset telah dikirim.']);
    }

    public function reset(
        ResetPasswordRequest $request,
        AccountSessionManager $sessions,
        SecurityEventRecorder $events,
    ): JsonResponse {
        $status = Password::reset($request->validated(), function (User $user, string $password) use ($sessions, $events, $request): void {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            $sessions->revokeAll($user);
            $events->record($user, 'password.reset_completed', $request);
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Token reset tidak valid atau telah kedaluwarsa.'], 422);
        }

        return response()->json(['message' => 'Kata sandi berhasil direset.']);
    }
}
