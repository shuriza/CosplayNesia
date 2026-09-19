<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTransactionReady
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user?->hasVerifiedEmail()) {
            abort(403, 'Verifikasi email diperlukan untuk bertransaksi.');
        }
        if (! $user->hasCurrentLegalConsent()) {
            abort(409, 'Persetujuan syarat dan kebijakan terbaru diperlukan.');
        }

        return $next($request);
    }
}
