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
        $additionalHosts = array_values(array_filter(
            (array) config('geoflow.additional_trusted_hosts', []),
            static fn (mixed $host): bool => is_string($host) && $host !== '',
        ));
        $trustedHosts = array_map(
            static fn (string $host): string => strtolower($host),
            is_string($configuredHost) && $configuredHost !== ''
                ? [$configuredHost, ...$additionalHosts]
                : $additionalHosts,
        );

        if (! in_array($requestHost, $trustedHosts, true)) {
            abort(Response::HTTP_BAD_REQUEST, 'Invalid Host header.');
        }

        return $next($request);
    }
}
