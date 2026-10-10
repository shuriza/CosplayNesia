<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the backoffice. Access is denied unless the authenticated account has the backoffice
 * capability; there is no implicit "any logged-in user" path. Unauthenticated requests are already
 * rejected by the auth guard before this middleware runs.
 */
class EnsureBackofficeAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->canAccessBackoffice() === true, 403);

        return $next($request);
    }
}
