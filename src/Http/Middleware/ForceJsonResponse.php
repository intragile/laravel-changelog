<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the request expect JSON so validation errors and 404s are never redirects or HTML,
 * while the host's exception handler still formats them.
 */
final class ForceJsonResponse
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
