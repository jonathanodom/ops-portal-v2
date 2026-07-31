<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireCapability
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $membership = $request->attributes->get('membership');

        abort_unless($membership?->hasCapability($capability), 403);

        return $next($request);
    }
}
