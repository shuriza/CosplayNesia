<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());
        Auth::login($user);
        $request->session()->regenerate();
        $this->bindSessionToPassword($request, $user);

        return response()->json(['user' => $user], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::attempt($request->validated())) {
            return response()->json(['message' => 'Email atau kata sandi tidak valid.'], 422);
        }

        $request->session()->regenerate();
        $this->bindSessionToPassword($request, $request->user());

        return response()->json(['user' => $request->user()]);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()]);
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $validated = $request->safe()->only(['name', 'email']);
        $user = DB::transaction(function () use ($request, $validated): User {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if (! Hash::check($request->validated('current_password'), $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'Kata sandi saat ini tidak valid.',
                ]);
            }

            $nameChanged = $user->name !== $validated['name'];
            $emailChanged = $user->email !== $validated['email'];

            $user->fill($validated);
            if ($emailChanged) {
                $user->forceFill(['email_verified_at' => null]);
            }
            $user->save();
            if ($nameChanged) {
                $user->products()->update(['seller' => $validated['name']]);
            }

            return $user->fresh();
        }, 3);

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'user' => $user,
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
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

        return response()->json(['message' => 'Kata sandi berhasil diperbarui.']);
    }

    public function logout(Request $request): JsonResponse
    {
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
}
