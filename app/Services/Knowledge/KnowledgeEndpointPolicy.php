<?php

namespace App\Services\Knowledge;

final class KnowledgeEndpointPolicy
{
    public static function allows(string $baseUrl): bool
    {
        $parts = parse_url($baseUrl);
        if (! is_array($parts)) return false;

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host === '' || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) return false;

        $port = $parts['port'] ?? 443;
        if ($scheme === 'https' && (int) $port === 443 && in_array($host, ['knowledge.dofe.ai', 'knowledge.local.dofe.ai', 'sso.ixicai.cn'], true)) {
            return true;
        }

        $privateTargets = config('geoflow.outbound_private_targets', []);
        $target = $host.':'.(string) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return (bool) config('geoflow.models_allow_insecure_local', false)
            && in_array($scheme, ['http', 'https'], true)
            && is_array($privateTargets)
            && in_array($target, array_map(static fn (mixed $value): string => strtolower(trim((string) $value)), $privateTargets), true);
    }
}
