<?php

namespace Tests\Unit;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Services\Knowledge\KnowledgeInfraClient;
use App\Services\Outbound\ResolvedOutboundTarget;
use App\Services\Outbound\SafeOutboundHttpClient;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class KnowledgeInfraClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set([
            'geoflow.knowledge_api_url' => 'https://knowledge.local.dofe.ai/api',
            'geoflow.knowledge_sso_issuer' => 'https://sso.ixicai.cn/api',
            'geoflow.knowledge_sso_client_id' => 'geoflow-dofe-ai',
            'geoflow.knowledge_sso_client_secret' => 'test-secret',
            'geoflow.knowledge_sso_scope' => 'service:access',
            'geoflow.knowledge_tenant_slug' => 'yootun',
            'geoflow.knowledge_space_ids' => ['space-1'],
            'geoflow.knowledge_timeout_seconds' => 15,
            'geoflow.outbound_json_max_bytes' => 1024 * 1024,
        ]);
    }

    public function test_search_separates_sso_and_knowledge_request_identity(): void
    {
        $transport = new KnowledgeRecordingTransport([
            $this->jsonResponse(['access_token' => 'token-1', 'expires_in' => 300]),
            $this->jsonResponse([
                'code' => 200,
                'data' => ['list' => [['title' => 'Evidence']], 'total' => 1, 'page' => 1, 'limit' => 5],
            ]),
        ]);
        $client = new KnowledgeInfraClient(new SafeOutboundHttpClient(
            new KnowledgeHostResolver,
            $transport,
        ));

        $result = $client->search('query');

        $this->assertSame(1, $result['total']);
        $this->assertCount(2, $transport->calls);
        $this->assertSame('https://sso.ixicai.cn/api/oauth/token', $transport->calls[0]['url']);
        $this->assertSame('service:access', $transport->calls[0]['data']['scope']);
        $this->assertArrayNotHasKey('X-Knowledge-Tenant', $transport->calls[0]['headers']);
        $this->assertSame('https://knowledge.local.dofe.ai/api/yootun/v1/search', $transport->calls[1]['url']);
        $this->assertSame('geoflow', $transport->calls[1]['headers']['X-Knowledge-Source-System']);
        $this->assertSame('yootun', $transport->calls[1]['headers']['X-Knowledge-Tenant']);
        $this->assertSame('Bearer token-1', $transport->calls[1]['headers']['Authorization']);
        $this->assertSame(['space-1'], $transport->calls[1]['data']['spaceIds']);
    }

    public function test_rejects_non_allowlisted_knowledge_endpoint(): void
    {
        config()->set('geoflow.knowledge_api_url', 'https://attacker.example/api');
        $client = new KnowledgeInfraClient(new SafeOutboundHttpClient(
            new KnowledgeHostResolver,
            new KnowledgeRecordingTransport,
        ));

        $this->expectExceptionMessage('knowledge endpoint is not allow-listed');
        $client->search('query');
    }

    public function test_search_refreshes_a_rejected_token_once(): void
    {
        $transport = new KnowledgeRecordingTransport([
            $this->jsonResponse(['access_token' => 'stale-token', 'expires_in' => 300]),
            $this->jsonResponse(['code' => 401], 401),
            $this->jsonResponse(['access_token' => 'fresh-token', 'expires_in' => 300]),
            $this->jsonResponse([
                'code' => 200,
                'data' => ['list' => [], 'total' => 0, 'page' => 1, 'limit' => 5],
            ]),
        ]);
        $client = new KnowledgeInfraClient(new SafeOutboundHttpClient(
            new KnowledgeHostResolver,
            $transport,
        ));

        $result = $client->search('query');

        $this->assertSame(0, $result['total']);
        $this->assertCount(4, $transport->calls);
        $this->assertSame('Bearer stale-token', $transport->calls[1]['headers']['Authorization']);
        $this->assertSame('Bearer fresh-token', $transport->calls[3]['headers']['Authorization']);
    }

    public function test_search_does_not_retry_an_acl_rejection(): void
    {
        $transport = new KnowledgeRecordingTransport([
            $this->jsonResponse(['access_token' => 'token-1', 'expires_in' => 300]),
            $this->jsonResponse(['code' => 403], 403),
        ]);
        $client = new KnowledgeInfraClient(new SafeOutboundHttpClient(
            new KnowledgeHostResolver,
            $transport,
        ));

        try {
            $client->search('query');
            $this->fail('Expected ACL rejection');
        } catch (\RuntimeException $error) {
            $this->assertSame('knowledge search returned HTTP 403', $error->getMessage());
        }
        $this->assertCount(2, $transport->calls);
    }

    public function test_token_failure_reports_only_the_oauth_error_code(): void
    {
        $transport = new KnowledgeRecordingTransport([
            $this->jsonResponse([
                'error' => 'unauthorized_client',
                'error_description' => 'upstream-sensitive-detail',
            ], 400),
        ]);
        $client = new KnowledgeInfraClient(new SafeOutboundHttpClient(
            new KnowledgeHostResolver,
            $transport,
        ));

        try {
            $client->search('query');
            $this->fail('Expected OAuth failure');
        } catch (\RuntimeException $error) {
            $this->assertSame(
                'knowledge SSO token request returned HTTP 400 (unauthorized_client)',
                $error->getMessage(),
            );
            $this->assertStringNotContainsString('upstream-sensitive-detail', $error->getMessage());
        }
    }

    /** @param array<string, mixed> $payload */
    private function jsonResponse(array $payload, int $status = 200): Response
    {
        return new Response(new PsrResponse($status, ['Content-Type' => 'application/json'], json_encode($payload)));
    }
}

final class KnowledgeHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        return ['93.184.216.34'];
    }
}

final class KnowledgeRecordingTransport implements OutboundTransport
{
    /** @var list<array{url:string,headers:array<string,mixed>,data:array<string,mixed>}> */
    public array $calls = [];

    /** @param list<Response> $responses */
    public function __construct(private array $responses = []) {}

    public function send(
        PendingRequest $request,
        string $method,
        ResolvedOutboundTarget $target,
        array $data,
        int $maxBytes,
        bool $crossOrigin = false,
    ): Response {
        $this->calls[] = [
            'url' => $target->url,
            'headers' => $request->getOptions()['headers'] ?? [],
            'data' => $data,
        ];

        return array_shift($this->responses) ?? new Response(new PsrResponse(500, [], '{}'));
    }
}
