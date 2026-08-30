<?php

namespace App\Services\Models;

final class ModelsEndpointPolicy
{
    private const PRODUCTION_HOST = 'models.dofe.ai';
    private const PUBLIC_ROUTER_HOST = 'ixicai.cn';

    public static function allows(string $baseUrl): bool
    {
        $parts = parse_url($baseUrl);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        if ($scheme === 'https') {
            $port = $parts['port'] ?? null;

            return in_array($host, [self::PRODUCTION_HOST, self::PUBLIC_ROUTER_HOST], true)
                && ($port === null || $port === 443);
        }

        $localHosts = ['127.0.0.1', 'host.docker.internal'];
        $privateTargets = config('geoflow.outbound_private_targets', []);
        if (! is_array($privateTargets)) {
            return false;
        }

        $privateTargets = array_map(
            static fn (mixed $target): string => strtolower(trim((string) $target)),
            $privateTargets,
        );
        $target = $host.':'.(string) ($parts['port'] ?? 80);

        return (bool) config('geoflow.models_allow_insecure_local', false)
            && $scheme === 'http'
            && in_array($host, $localHosts, true)
            && in_array($target, $privateTargets, true);
    }
}
