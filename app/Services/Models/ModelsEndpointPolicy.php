<?php

namespace App\Services\Models;

final class ModelsEndpointPolicy
{
    public static function allows(string $baseUrl): bool
    {
        $parts = parse_url($baseUrl);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        if ($scheme === 'https') {
            return true;
        }

        $localHosts = ['127.0.0.1', 'host.docker.internal', 'dofe-models-api-local'];

        return (bool) config('geoflow.models_allow_insecure_local', false)
            && $scheme === 'http'
            && in_array($host, $localHosts, true);
    }
}
