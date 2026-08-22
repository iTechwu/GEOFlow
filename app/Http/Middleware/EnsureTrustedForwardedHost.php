<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTrustedForwardedHost
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local', 'testing') || $request->is('up')) {
            return $next($request);
        }

        $configuredHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $requestHost = strtolower($request->getHost());

        if (! is_string($configuredHost) || $configuredHost === '' || $requestHost !== strtolower($configuredHost)) {
            abort(Response::HTTP_BAD_REQUEST, 'Invalid Host header.');
        }

        return $next($request);
    }
}
