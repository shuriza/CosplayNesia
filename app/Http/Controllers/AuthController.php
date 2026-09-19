<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use App\Notifications\ConfirmEmailChangeNotification;
use App\Services\AccountSessionManager;
use App\Services\SecurityEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(
        RegisterRequest $request,
        AccountSessionManager $sessions,
        SecurityEventRecorder $events,
    ): JsonResponse {
        $validated = $request->safe()->only(['name', 'email', 'password']);
        $user = User::create([
            ...$validated,
            'terms_version' => config('cosplaynesia.legal.terms_version'),
            'privacy_version' => config('cosplaynesia.legal.privacy_version'),
            'rental_policy_version' => config('cosplaynesia.legal.rental_policy_version'),
            'legal_accepted_at' => now(),
        ]);
        Auth::login($user);
        $request->session()->regenerate();
        $this->bindSessionToPassword($request, $user);
        $sessions->rotate($request, $user);
        $events->record($user, 'account.registered', $request, [
            'terms_version' => $user->terms_version,
            'privacy_version' => $user->privacy_version,
            'rental_policy_version' => $user->rental_policy_version,
        ]);
        $user->sendEmailVerificationNotification();

        return response()->json(['user' => $this->userPayload($user)], 201);
    }

    public function login(
        LoginRequest $request,
        AccountSessionManager $sessions,
        SecurityEventRecorder $events,
    ): JsonResponse {
        if (! Auth::attempt($request->validated())) {
            $events->record(User::query()->where('email', $request->validated('email'))->first(), 'login.failed', $request);

            return response()->json(['message' => 'Email atau kata sandi tidak valid.'], 422);
        }

        if ($request->user()->deactivated_at !== null) {
            $events->record($request->user(), 'login.deactivated_rejected', $request);
            Auth::logout();

            return response()->json(['message' => 'Email atau kata sandi tidak valid.'], 422);
        }

        $request->session()->regenerate();
        $this->bindSessionToPassword($request, $request->user());
        $sessions->rotate($request, $request->user());
        $events->record($request->user(), 'login.succeeded', $request);

        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user() ? $this->userPayload($request->user()) : null]);
    }

    public function updateProfile(UpdateProfileRequest $request, SecurityEventRecorder $events): JsonResponse
    {
        $validated = $request->safe()->only(['name', 'email']);
        [$user, $emailToken] = DB::transaction(function () use ($request, $validated, $events): array {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if (! Hash::check($request->validated('current_password'), $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'Kata sandi saat ini tidak valid.',
                ]);
            }

            $nameChanged = $user->name !== $validated['name'];
            $emailChanged = $user->email !== $validated['email'];

            $user->fill(['name' => $validated['name']]);
            $emailToken = null;
            if ($emailChanged) {
                $emailToken = Str::random(64);
                $user->forceFill([
                    'pending_email' => $validated['email'],
                    'pending_email_token_hash' => hash('sha256', $emailToken),
                    'pending_email_requested_at' => now(),
                ]);
            }
            $user->save();
            if ($nameChanged) {
                $user->products()->update(['seller' => $validated['name']]);
            }
            $events->record($user, 'profile.updated', $request, [
                'name_changed' => $nameChanged,
                'email_change_requested' => $emailChanged,
            ]);

            return [$user->fresh(), $emailToken];
        }, 3);

        if ($emailToken !== null) {
            $url = URL::temporarySignedRoute('email-change.confirm', now()->addMinutes(60), [
                'user' => $user->id,
                'token' => $emailToken,
            ]);
            Notification::route('mail', $user->pending_email)
                ->notify(new ConfirmEmailChangeNotification($url));
        }

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'user' => $this->userPayload($user),
        ]);
    }

    public function updatePassword(
        UpdatePasswordRequest $request,
        AccountSessionManager $sessions,
        SecurityEventRecorder $events,
    ): JsonResponse {
        $user = DB::transaction(function () use ($request): User {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if (! Hash::check($request->validated('current_password'), $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'Kata sandi saat ini tidak valid.',
                ]);
            }

            $user->update(['password' => $request->validated('password')]);

            return $user->fresh();
        }, 3);
        Auth::setUser($user);
        $request->session()->regenerate();
        $this->bindSessionToPassword($request, $user);
        $current = $sessions->ensure($request, $user);
        $sessions->revokeOthers($user, $current->id);
        $events->record($user, 'password.changed', $request);

        return response()->json(['message' => 'Kata sandi berhasil diperbarui.']);
    }

    public function logout(Request $request, AccountSessionManager $sessions, SecurityEventRecorder $events): JsonResponse
    {
        if ($request->user()) {
            $current = $sessions->current($request, $request->user());
            $current->update(['revoked_at' => now()]);
            $events->record($request->user(), 'logout.succeeded', $request);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['csrf_token' => $request->session()->token()]);
    }

    private function bindSessionToPassword(Request $request, User $user): void
    {
        $request->session()->put(
            'password_hash_'.Auth::getDefaultDriver(),
            Auth::hashPasswordForCookie($user->getAuthPassword()),
        );
    }

    private function userPayload(User $user): array
    {
        return [
            ...$user->toArray(),
            'pending_email' => $user->pending_email,
        ];
    }
}
