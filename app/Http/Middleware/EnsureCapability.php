<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a specific capability for a route.
 *
 * This runs *before* step-up on purpose. A step-up middleware that ran first would answer a
 * support operator asking to remove a listing with "confirm your password", which implies the
 * action is theirs to take once confirmed. Permission is decided first; only then does the question
 * of a fresh confirmation arise.
 *
 * Usage: ->middleware('capability:review_listings')
 */
class EnsureCapability
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        abort_unless($request->user()?->hasCapability($capability) === true, 403);

        return $next($request);
    }
}
