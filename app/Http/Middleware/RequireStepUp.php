<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step-up authentication for sensitive reads.
 *
 * A backoffice session is not enough on its own: the operator must re-confirm their password, and
 * that confirmation expires after a short TTL. The timestamp lives in the server-side session, so
 * it cannot be forged by a client header. Every successful and failed confirmation is recorded by
 * the caller (AdminAuthController) so the attempt itself is auditable.
 */
class RequireStepUp
{
    public const SESSION_KEY = 'backoffice.step_up_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->confirmed($request)) {
            return response()->json([
                'message' => 'Konfirmasi kata sandi diperlukan untuk tindakan ini.',
                'step_up_required' => true,
            ], 423);
        }

        return $next($request);
    }

    private function confirmed(Request $request): bool
    {
        $confirmedAt = $request->session()->get(self::SESSION_KEY);
        if ($confirmedAt === null) {
            return false;
        }

        $ttl = (int) config('cosplaynesia.backoffice.step_up_ttl_minutes', 15);

        return now()->diffInMinutes($confirmedAt, true) <= $ttl;
    }
}
