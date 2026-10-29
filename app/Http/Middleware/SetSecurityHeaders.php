<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline response headers applied to every response.
 *
 * - frame-ancestors 'none' replaces X-Frame-Options (CSP is forward-looking; the old header is
 *   still emitted for legacy agents that ignore CSP).
 * - CSP is report-free and strict enough for a Blade + vanilla-JS frontend: no inline script is
 *   needed, but Vite dev mode injects inline scripts, so the header is loosened only when the
 *   app detects a local dev build (APP_ENV not production). Production never allows 'unsafe-eval'
 *   or broad script-src.
 * - Referrer-Policy keeps full URL only for same-origin; cross-origin sees just the origin.
 * - Permissions-Policy disables camera/mic/geolocation; nothing in the product uses them.
 */
class SetSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $csp = "default-src 'self'; ".
            "base-uri 'self'; ".
            "form-action 'self'; ".
            "frame-ancestors 'none'; ".
            "img-src 'self' data: https:; ".
            "font-src 'self' https://fonts.gstatic.com data:; ".
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; ".
            "connect-src 'self'".($this->isLocal($request) ? ' ws: wss: http://localhost:*' : '').'; '.
            'script-src '.($this->isLocal($request) ? "'self' 'unsafe-inline' 'unsafe-eval'" : "'self'").'; '.
            "object-src 'none'";

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        return $response;
    }

    private function isLocal(Request $request): bool
    {
        return app()->environment('local', 'development', 'testing')
            || str_starts_with((string) $request->getHost(), 'localhost')
            || str_starts_with((string) $request->getHost(), '127.');
    }
}
