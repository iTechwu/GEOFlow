<?php

namespace Tests\Feature;

use App\Models\McpAuditLog;
use App\Services\GeoFlow\CatalogGeoFlowService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\Sso\SsoIdentityService;
use App\Services\Sso\SsoOidcClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class McpEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function enableMcp(string $token = 'ci-secret', ?string $readToken = null): void
    {
        config([
            'geoflow.mcp_enabled' => true,
            'geoflow.mcp_token' => $token,
            'geoflow.mcp_read_token' => $readToken ?? '',
            'geoflow.mcp_server_name' => 'geoflow-ci',
        ]);
    }

    /**
     * @return array{CatalogGeoFlowService&MockInterface, TaskLifecycleService&MockInterface}
     */
    private function mockServices(): array
    {
        $catalog = Mockery::mock(CatalogGeoFlowService::class);
        $tasks = Mockery::mock(TaskLifecycleService::class);
        app()->instance(CatalogGeoFlowService::class, $catalog);
        app()->instance(TaskLifecycleService::class, $tasks);

        return [$catalog, $tasks];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function toolCall(string $tool, array $arguments): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ];
    }

    public function test_mcp_is_disabled_by_default(): void
    {
        config(['geoflow.mcp_enabled' => false]);

        $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
        ])->assertNotFound();
    }

    public function test_mcp_requires_the_deployment_token(): void
    {
        config(['geoflow.mcp_enabled' => true, 'geoflow.mcp_token' => 'ci-secret']);

        $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
        ])->assertUnauthorized()->assertJsonPath('error.code', -32001);
    }

    public function test_initialize_and_tools_list_are_json_rpc_responses(): void
    {
        $this->enableMcp();
        $this->mockServices();

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'geoflow-ci')
            ->assertJsonPath('result.protocolVersion', '2025-06-18');

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])
            ->assertOk()
            ->assertJsonPath('result.tools.0.name', 'geoflow.catalog')
            ->assertJsonPath('result.tools.5.name', 'geoflow.tasks.enqueue');
    }

    public function test_read_token_allows_read_tools(): void
    {
        $this->enableMcp('ci-secret', 'ci-read');
        [$catalog] = $this->mockServices();
        $catalog->shouldReceive('getCatalog')->once()->andReturn(['models' => []]);

        $this->withHeader('Authorization', 'Bearer ci-read')
            ->postJson('/mcp', $this->toolCall('geoflow.catalog', []))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.models', []);
    }

    public function test_read_token_rejects_write_tools(): void
    {
        $this->enableMcp('ci-secret', 'ci-read');
        [$catalog, $tasks] = $this->mockServices();
        $tasks->shouldNotReceive('startTask');

        $this->withHeader('Authorization', 'Bearer ci-read')
            ->postJson('/mcp', $this->toolCall('geoflow.tasks.start', ['task_id' => 5]))
            ->assertOk()
            ->assertJsonPath('result.isError', true);
    }

    public function test_write_token_allows_write_tools(): void
    {
        $this->enableMcp();
        [$catalog, $tasks] = $this->mockServices();
        $tasks->shouldReceive('startTask')->once()->with(5, false)->andReturn(['id' => 5, 'status' => 'active']);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.tasks.start', ['task_id' => 5]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.id', 5);
    }

    public function test_write_tool_with_idempotency_key_replays_cached_result(): void
    {
        $this->enableMcp();
        [$catalog, $tasks] = $this->mockServices();
        $tasks->shouldReceive('enqueueTask')
            ->once()
            ->with(5, 'generate_article', [])
            ->andReturn(['task_id' => 5, 'job_id' => 42, 'status' => 'pending']);

        $payload = $this->toolCall('geoflow.tasks.enqueue', ['task_id' => 5, 'idempotency_key' => 'enqueue-42']);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $payload)
            ->assertOk()
            ->assertJsonPath('result.structuredContent.job_id', 42);

        // 重试同一幂等键应命中缓存，不再二次入队。
        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $payload)
            ->assertOk()
            ->assertJsonPath('result.structuredContent.job_id', 42);
    }

    public function test_write_tool_records_audit_log(): void
    {
        $this->enableMcp();
        [$catalog, $tasks] = $this->mockServices();
        $tasks->shouldReceive('stopTask')->once()->with(7)->andReturn(['id' => 7, 'cancelled_jobs' => 0]);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.tasks.stop', ['task_id' => 7]))
            ->assertOk();

        $this->assertDatabaseHas('mcp_audit_logs', [
            'tool' => 'geoflow.tasks.stop',
            'scope' => 'write',
            'target_id' => 7,
            'outcome' => 'success',
        ]);
    }

    public function test_notification_without_id_returns_no_content(): void
    {
        $this->enableMcp();
        $this->mockServices();

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])
            ->assertNoContent();
    }

    public function test_unknown_tool_returns_invalid_params(): void
    {
        $this->enableMcp();
        $this->mockServices();

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.unknown', []))
            ->assertOk()
            ->assertJsonPath('error.code', -32602);
    }

    public function test_invalid_task_id_returns_invalid_params(): void
    {
        $this->enableMcp();
        [$catalog, $tasks] = $this->mockServices();
        $tasks->shouldNotReceive('getTask');

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.tasks.get', ['task_id' => 0]))
            ->assertOk()
            ->assertJsonPath('error.code', -32602);
    }

    public function test_idempotency_conflict_returns_tool_error(): void
    {
        $this->enableMcp();
        [$catalog, $tasks] = $this->mockServices();
        $tasks->shouldReceive('enqueueTask')
            ->once()
            ->with(5, 'generate_article', [])
            ->andReturn(['task_id' => 5, 'job_id' => 42, 'status' => 'pending']);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.tasks.enqueue', ['task_id' => 5, 'idempotency_key' => 'enqueue-42']))
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.tasks.enqueue', ['task_id' => 6, 'idempotency_key' => 'enqueue-42']))
            ->assertOk()
            ->assertJsonPath('result.isError', true);
    }

    public function test_tool_business_exception_returns_json_rpc_error(): void
    {
        $this->enableMcp();
        [$catalog, $tasks] = $this->mockServices();
        $tasks->shouldReceive('startTask')->once()->with(5, false)->andThrow(new \RuntimeException('boom'));

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.tasks.start', ['task_id' => 5]))
            ->assertOk()
            ->assertJsonPath('error.code', -32000);
    }

    public function test_write_tool_audit_does_not_leak_payload_content(): void
    {
        $this->enableMcp();
        [$catalog, $tasks] = $this->mockServices();
        $tasks->shouldReceive('enqueueTask')
            ->once()
            ->with(5, 'generate_article', ['prompt' => 'TOP-SECRET-PROMPT'])
            ->andReturn(['task_id' => 5, 'job_id' => 42, 'status' => 'pending']);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.tasks.enqueue', ['task_id' => 5, 'payload' => ['prompt' => 'TOP-SECRET-PROMPT']]))
            ->assertOk();

        $details = McpAuditLog::query()->firstOrFail()->details;
        $this->assertStringNotContainsString('TOP-SECRET-PROMPT', $details);
        $this->assertStringNotContainsString('prompt', $details);
    }

    public function test_read_tool_records_audit_log(): void
    {
        $this->enableMcp();
        [$catalog] = $this->mockServices();
        $catalog->shouldReceive('getCatalog')->once()->andReturn(['models' => []]);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.catalog', []))
            ->assertOk();

        $this->assertDatabaseHas('mcp_audit_logs', [
            'tool' => 'geoflow.catalog',
            'scope' => 'write',
            'outcome' => 'success',
        ]);
    }

    public function test_sso_token_scopes_task_tools_by_selected_team(): void
    {
        $this->enableMcp('ci-secret');

        $oidc = Mockery::mock(SsoOidcClient::class);
        $identities = Mockery::mock(SsoIdentityService::class);
        $oidc->shouldReceive('userInfoClaims')
            ->once()
            ->with('sso-token')
            ->andReturn(['sub' => 'user-1', 'selected_team_id' => 'team-a']);
        $identities->shouldReceive('synchronize')->once()->andReturnNull();
        $identities->shouldReceive('selectedTeamId')->once()->andReturn('team-a');
        app()->instance(SsoOidcClient::class, $oidc);
        app()->instance(SsoIdentityService::class, $identities);

        [$catalog, $tasks] = $this->mockServices();
        $tasks->shouldReceive('ensureTaskInScope')->once()->with(5, 'team-a');
        $tasks->shouldReceive('getTask')->once()->with(5)->andReturn(['id' => 5, 'name' => 'T']);

        $this->withHeader('Authorization', 'Bearer sso-token')
            ->postJson('/mcp', $this->toolCall('geoflow.tasks.get', ['task_id' => 5]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.id', 5);
    }

    public function test_sso_token_without_team_is_rejected(): void
    {
        $this->enableMcp('ci-secret');

        $oidc = Mockery::mock(SsoOidcClient::class);
        $identities = Mockery::mock(SsoIdentityService::class);
        $oidc->shouldReceive('userInfoClaims')
            ->once()
            ->with('sso-token')
            ->andReturn(['sub' => 'user-1']);
        $identities->shouldReceive('synchronize')->once()->andReturnNull();
        $identities->shouldReceive('selectedTeamId')->once()->andReturnNull();
        app()->instance(SsoOidcClient::class, $oidc);
        app()->instance(SsoIdentityService::class, $identities);

        $this->mockServices();

        $this->withHeader('Authorization', 'Bearer sso-token')
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', -32001);
    }
}
