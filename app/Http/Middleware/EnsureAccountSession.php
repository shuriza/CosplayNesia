<?php

namespace App\Http\Middleware;

use App\Services\AccountSessionManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountSession
{
    public function __construct(private readonly AccountSessionManager $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->deactivated_at !== null) {
            Auth::logout();
            $request->session()->invalidate();
            abort(401, 'Akun telah dinonaktifkan.');
        }
        if ($user) {
            $this->sessions->ensure($request, $user);
        }

        return $next($request);
    }
}
