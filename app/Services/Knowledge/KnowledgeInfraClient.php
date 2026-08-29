<?php

namespace App\Services\Knowledge;

use App\Services\Outbound\SafeOutboundHttpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class KnowledgeInfraClient
{
    public function __construct(private readonly SafeOutboundHttpClient $safeHttp) {}

    public function isConfigured(): bool
    {
        return self::baseUrl() !== ''
            && self::issuer() !== ''
            && self::clientId() !== ''
            && self::clientSecret() !== ''
            && config('geoflow.knowledge_space_ids', []) !== [];
    }

    /** @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int} */
    public function search(string $query, int $topK = 5, bool $includeMemories = true): array
    {
        if (! $this->isConfigured()) throw new RuntimeException('knowledge client is not configured');
        $response = $this->safeHttp->post(
            $this->request()->withToken($this->accessToken()),
            self::baseUrl().'/yootun/v1/search',
            [
                'query' => trim($query),
                'spaceIds' => array_values(config('geoflow.knowledge_space_ids', [])),
                'topK' => max(1, min(50, $topK)),
                'includeMemories' => $includeMemories,
            ],
            (int) config('geoflow.outbound_json_max_bytes', 4 * 1024 * 1024),
        );
        if (! $response->successful()) throw new RuntimeException('knowledge search returned HTTP '.$response->status());

        $payload = $response->json();
        $data = is_array($payload) && ($payload['code'] ?? 200) === 200 ? ($payload['data'] ?? $payload) : null;
        if (! is_array($data) || ! is_array($data['list'] ?? null)) throw new RuntimeException('knowledge search response is invalid');

        return [
            'list' => array_values(array_filter($data['list'], 'is_array')),
            'total' => (int) ($data['total'] ?? count($data['list'])),
            'page' => (int) ($data['page'] ?? 1),
            'limit' => (int) ($data['limit'] ?? $topK),
        ];
    }

    private function accessToken(): string
    {
        $key = 'geoflow:knowledge:m2m:'.sha1(self::clientId());
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') return $cached;

        $response = $this->safeHttp->post(
            $this->request(false)->asForm()->withBasicAuth(self::clientId(), self::clientSecret()),
            self::issuer().'/oauth/token',
            ['grant_type' => 'client_credentials', 'scope' => self::scope()],
            (int) config('geoflow.outbound_json_max_bytes', 4 * 1024 * 1024),
        );
        if (! $response->successful()) throw new RuntimeException('knowledge SSO token request returned HTTP '.$response->status());
        $payload = $response->json();
        $token = is_array($payload) ? trim((string) ($payload['access_token'] ?? '')) : '';
        if ($token === '') throw new RuntimeException('knowledge SSO token response is invalid');
        $ttl = max(1, min(300, (int) ($payload['expires_in'] ?? 300) - 30));
        Cache::put($key, $token, $ttl);
        return $token;
    }

    private function request(bool $withKnowledgeContext = true): PendingRequest
    {
        if (! KnowledgeEndpointPolicy::allows(self::baseUrl()) || ! KnowledgeEndpointPolicy::allows(self::issuer())) {
            throw new RuntimeException('knowledge endpoint is not allow-listed');
        }
        $request = Http::acceptJson()
            ->connectTimeout(5)
            ->timeout((int) config('geoflow.knowledge_timeout_seconds', 15))
            ->retry([200, 500], when: static function (Throwable $exception): bool {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && ($exception->response->status() === 429 || $exception->response->serverError()));
            }, throw: false);

        return $withKnowledgeContext ? $request->withHeaders([
                'X-Knowledge-Source-System' => 'geoflow',
                'X-Knowledge-Tenant' => self::tenantSlug(),
                'X-API-Version' => '1',
            ]) : $request;
    }

    private static function baseUrl(): string { return rtrim((string) config('geoflow.knowledge_api_url', ''), '/'); }
    private static function issuer(): string { return rtrim((string) config('geoflow.knowledge_sso_issuer', ''), '/'); }
    private static function clientId(): string { return trim((string) config('geoflow.knowledge_sso_client_id', '')); }
    private static function clientSecret(): string { return trim((string) config('geoflow.knowledge_sso_client_secret', '')); }
    private static function scope(): string { return trim((string) config('geoflow.knowledge_sso_scope', 'service:access')); }
    private static function tenantSlug(): string { return trim((string) config('geoflow.knowledge_tenant_slug', 'yootun')); }
}
