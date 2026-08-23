<?php

namespace Tests\Feature;

use App\Http\McpAuthContext;
use App\Models\McpAuditLog;
use App\Services\GeoFlow\ArticleGeoFlowService;
use App\Services\GeoFlow\CatalogGeoFlowService;
use App\Services\GeoFlow\MaterialLibraryService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\Mcp\McpAnalyticsService;
use App\Services\Mcp\McpDistributionService;
use App\Services\Mcp\McpEnterpriseKnowledgeService;
use App\Services\Mcp\McpFrontendService;
use App\Services\Mcp\McpLeadService;
use App\Services\Mcp\McpSiteService;
use App\Services\Mcp\McpSystemService;
use App\Services\Mcp\McpUrlImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
            'geoflow.mcp_allow_cross_tenant' => true,
            'geoflow.mcp_server_name' => 'geoflow-ci',
        ]);
    }

    /**
     * @return array{CatalogGeoFlowService&MockInterface, TaskLifecycleService&MockInterface, MaterialLibraryService&MockInterface}
     */
    private function mockServices(): array
    {
        $catalog = Mockery::mock(CatalogGeoFlowService::class);
        $tasks = Mockery::mock(TaskLifecycleService::class);
        $materials = Mockery::mock(MaterialLibraryService::class);
        app()->instance(CatalogGeoFlowService::class, $catalog);
        app()->instance(TaskLifecycleService::class, $tasks);
        app()->instance(MaterialLibraryService::class, $materials);

        return [$catalog, $tasks, $materials];
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

        $toolsResponse = $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])
            ->assertOk()
            ->assertJsonPath('result.tools.0.name', 'geoflow.catalog')
            ->assertJsonPath('result.tools.3.inputSchema.properties.image_library_id.anyOf.0.type', 'integer')
            ->assertJsonPath('result.tools.3.inputSchema.properties.image_library_id.anyOf.1.type', 'null')
            ->assertJsonPath('result.tools.7.name', 'geoflow.tasks.enqueue')
            ->assertJsonPath('result.tools.8.name', 'geoflow.articles.list')
            ->assertJsonPath('result.tools.10.inputSchema.properties.task_id.anyOf.1.type', 'null')
            ->assertJsonPath('result.tools.15.name', 'geoflow.materials.summary')
            ->assertJsonPath('result.tools.18.inputSchema.properties.type.enum', ['keyword-libraries', 'title-libraries', 'image-libraries', 'knowledge-bases'])
            ->assertJsonPath('result.tools.23.name', 'geoflow.materials.items.delete');

        $this->assertStringContainsString('"properties":{}', $toolsResponse->getContent());
    }

    public function test_mcp_rate_limit_is_isolated_by_credential_behind_the_same_ip(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $writeToken = 'write-'.$suffix;
        $readToken = 'read-'.$suffix;
        $this->enableMcp($writeToken, $readToken);
        config([
            'geoflow.mcp_rate_limit_per_minute' => 2,
            'geoflow.mcp_ip_rate_limit_per_minute' => 10,
        ]);

        $initialize = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'];

        $this->withHeader('Authorization', 'Bearer '.$writeToken)->postJson('/mcp', $initialize)->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$writeToken)->postJson('/mcp', $initialize)->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$writeToken)->postJson('/mcp', $initialize)->assertTooManyRequests();

        $this->withHeader('Authorization', 'Bearer '.$readToken)
            ->postJson('/mcp', $initialize)
            ->assertOk();
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

    public function test_read_token_tool_discovery_hides_write_tools(): void
    {
        $this->enableMcp('ci-secret', 'ci-read');

        $response = $this->withHeader('Authorization', 'Bearer ci-read')
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])
            ->assertOk();

        $names = collect($response->json('result.tools'))->pluck('name')->all();
        $this->assertContains('geoflow.catalog', $names);
        $this->assertContains('geoflow.tasks.list', $names);
        $this->assertNotContains('geoflow.tasks.create', $names);
        $this->assertNotContains('geoflow.articles.publish', $names);
        $this->assertNotContains('geoflow.jobs.cancel', $names);
    }

    public function test_read_token_allows_tenant_scoped_analytics_read(): void
    {
        $this->enableMcp('ci-secret', 'ci-read');
        config(['geoflow.mcp_default_tenant' => 'team-a']);
        $this->mockServices();
        $analytics = Mockery::mock(McpAnalyticsService::class);
        app()->instance(McpAnalyticsService::class, $analytics);
        $analytics->shouldReceive('overview')->once()->with([], Mockery::type(McpAuthContext::class))->andReturn([
            'tenant_id' => 'team-a',
            'kpis' => ['articles' => 1],
        ]);

        $this->withHeader('Authorization', 'Bearer ci-read')
            ->postJson('/mcp', $this->toolCall('geoflow.analytics.overview', []))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.tenant_id', 'team-a');
    }

    public function test_capabilities_describe_tenant_scope_and_protected_domains(): void
    {
        $this->enableMcp();
        config(['geoflow.mcp_default_tenant' => 'team-a']);
        $this->mockServices();

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.capabilities', []))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.tenant.id', 'team-a')
            ->assertJsonPath('result.structuredContent.tenant.mode', 'tenant_scoped')
            ->assertJsonPath('result.structuredContent.exposed.2.domain', 'url_import')
            ->assertJsonPath('result.structuredContent.exposed.3.domain', 'analytics')
            ->assertJsonPath('result.structuredContent.admin_only.0.domain', 'distribution');
    }

    public function test_analytics_overview_is_exposed_as_a_tenant_scoped_read_tool(): void
    {
        $this->enableMcp();
        config(['geoflow.mcp_default_tenant' => 'team-a']);
        $this->mockServices();
        $analytics = Mockery::mock(McpAnalyticsService::class);
        app()->instance(McpAnalyticsService::class, $analytics);
        $analytics->shouldReceive('overview')->once()->with([], Mockery::type(McpAuthContext::class))->andReturn([
            'tenant_id' => 'team-a',
            'kpis' => ['articles' => 2],
        ]);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.analytics.overview', []))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.tenant_id', 'team-a')
            ->assertJsonPath('result.structuredContent.kpis.articles', 2);
    }

    public function test_enterprise_knowledge_workflow_requires_explicit_publish_confirmation(): void
    {
        $this->enableMcp();
        config(['geoflow.mcp_default_tenant' => 'team-a']);
        $this->mockServices();
        $knowledge = Mockery::mock(McpEnterpriseKnowledgeService::class);
        app()->instance(McpEnterpriseKnowledgeService::class, $knowledge);
        $knowledge->shouldReceive('create')->once()->andReturn(['id' => 12, 'status' => 'queued']);
        $knowledge->shouldReceive('publish')->once()->with(12, 'PUBLISH', Mockery::type(McpAuthContext::class))->andReturn(['project_id' => 12, 'status' => 'published', 'knowledge_base_id' => 8]);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.enterprise_knowledge.create', ['name' => 'Company', 'content' => 'Source text']))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.id', 12);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.enterprise_knowledge.publish', ['project_id' => 12, 'confirmation' => 'PUBLISH']))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.status', 'published');
    }

    public function test_published_site_search_is_exposed_as_a_tenant_scoped_read_tool(): void
    {
        $this->enableMcp();
        config(['geoflow.mcp_default_tenant' => 'team-a']);
        $this->mockServices();
        $site = Mockery::mock(McpSiteService::class);
        app()->instance(McpSiteService::class, $site);
        $site->shouldReceive('search')->once()->with([], Mockery::type(McpAuthContext::class))->andReturn([
            'tenant_id' => 'team-a',
            'items' => [['slug' => 'hello-world']],
            'pagination' => ['page' => 1, 'per_page' => 20, 'total' => 1, 'total_pages' => 1],
        ]);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.site.search', []))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.tenant_id', 'team-a')
            ->assertJsonPath('result.structuredContent.items.0.slug', 'hello-world');
    }

    public function test_distribution_health_is_exposed_as_a_redacted_tenant_scoped_read_tool(): void
    {
        $this->enableMcp('ci-secret', 'ci-read');
        config(['geoflow.mcp_default_tenant' => 'team-a']);
        $this->mockServices();
        $distribution = Mockery::mock(McpDistributionService::class);
        app()->instance(McpDistributionService::class, $distribution);
        $distribution->shouldReceive('health')->once()->with(7, Mockery::type(McpAuthContext::class))->andReturn([
            'tenant_id' => 'team-a',
            'channel' => ['id' => 7, 'name' => 'Target', 'last_health_status' => 'ok'],
            'checked' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer ci-read')
            ->postJson('/mcp', $this->toolCall('geoflow.distribution.health', ['channel_id' => 7]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.tenant_id', 'team-a')
            ->assertJsonPath('result.structuredContent.channel.last_health_status', 'ok');
    }

    public function test_lead_submissions_are_exposed_without_payload_by_default(): void
    {
        $this->enableMcp('ci-secret', 'ci-read');
        config(['geoflow.mcp_default_tenant' => 'team-a']);
        $this->mockServices();
        $leads = Mockery::mock(McpLeadService::class);
        app()->instance(McpLeadService::class, $leads);
        $leads->shouldReceive('submissions')->once()->with([], Mockery::type(McpAuthContext::class))->andReturn([
            'tenant_id' => 'team-a',
            'items' => [['id' => 12, 'status' => 'new', 'payload_fields' => ['email']]],
            'pagination' => ['page' => 1, 'per_page' => 20, 'total' => 1, 'total_pages' => 1],
        ]);

        $this->withHeader('Authorization', 'Bearer ci-read')
            ->postJson('/mcp', $this->toolCall('geoflow.leads.submissions', []))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.tenant_id', 'team-a')
            ->assertJsonPath('result.structuredContent.items.0.payload_fields.0', 'email')
            ->assertJsonMissingPath('result.structuredContent.items.0.payload');
    }

    public function test_frontend_capabilities_are_exposed_to_read_tokens(): void
    {
        $this->enableMcp('ci-secret', 'ci-read');
        $this->mockServices();
        $frontend = Mockery::mock(McpFrontendService::class);
        app()->instance(McpFrontendService::class, $frontend);
        $frontend->shouldReceive('capabilities')->once()->with(Mockery::type(McpAuthContext::class))->andReturn([
            'tenant_id' => 'team-a',
            'scope' => 'read_only_global_catalog',
            'themes' => [['id' => 'default']],
            'homepage' => ['module_types' => ['hero']],
        ]);

        $this->withHeader('Authorization', 'Bearer ci-read')
            ->postJson('/mcp', $this->toolCall('geoflow.site.capabilities', []))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.homepage.module_types.0', 'hero');
    }

    public function test_system_status_is_exposed_to_read_tokens_without_runtime_secrets(): void
    {
        $this->enableMcp('ci-secret', 'ci-read');
        $this->mockServices();
        $system = Mockery::mock(McpSystemService::class);
        app()->instance(McpSystemService::class, $system);
        $system->shouldReceive('status')->once()->with(Mockery::type(McpAuthContext::class))->andReturn([
            'tenant_id' => 'team-a',
            'mcp' => ['enabled' => true],
            'database' => ['reachable' => true],
            'queue' => ['driver' => 'redis'],
        ]);

        $this->withHeader('Authorization', 'Bearer ci-read')
            ->postJson('/mcp', $this->toolCall('geoflow.system.status', []))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.database.reachable', true)
            ->assertJsonMissingPath('result.structuredContent.database.host');
    }

    public function test_job_cancel_is_exposed_as_a_write_tool(): void
    {
        $this->enableMcp();
        config(['geoflow.mcp_default_tenant' => 'team-a']);
        $this->mockServices();
        $mockTasks = Mockery::mock(TaskLifecycleService::class);
        app()->instance(TaskLifecycleService::class, $mockTasks);
        $mockTasks->shouldReceive('ensureJobInScope')->once()->with(8, 'team-a');
        $mockTasks->shouldReceive('cancelJob')->once()->with(8, 'team-a', 'stop')->andReturn([
            'id' => 8,
            'task_id' => 3,
            'status' => 'cancelled',
        ]);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.jobs.cancel', ['job_id' => 8, 'reason' => 'stop']))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.status', 'cancelled');
    }

    public function test_system_token_uses_configured_default_tenant_scope(): void
    {
        $this->enableMcp();
        config(['geoflow.mcp_default_tenant' => 'sso-team-default']);
        [$catalog] = $this->mockServices();
        $catalog->shouldReceive('getCatalog')->once()->with('sso-team-default')->andReturn(['models' => []]);

        $this->withHeader('Authorization', 'Bearer ci-secret')
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

    public function test_tasks_create_is_exposed_as_an_idempotent_mcp_write_tool(): void
    {
        $this->enableMcp();
        [$catalog, $tasks] = $this->mockServices();
        app()->instance(ArticleGeoFlowService::class, Mockery::mock(ArticleGeoFlowService::class));
        $tasks->shouldReceive('createTask')->once()->with(Mockery::on(static fn (array $input): bool => $input['name'] === 'GEO smoke task' && $input['title_library_id'] === 1 && $input['prompt_id'] === 2 && $input['ai_model_id'] === 3 && ! array_key_exists('idempotency_key', $input)))->andReturn(['id' => 11, 'name' => 'GEO smoke task']);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.tasks.create', [
                'name' => 'GEO smoke task',
                'title_library_id' => 1,
                'prompt_id' => 2,
                'ai_model_id' => 3,
                'idempotency_key' => 'task-create-11',
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.id', 11);
    }

    public function test_tool_arguments_must_match_the_advertised_schema(): void
    {
        $this->enableMcp();
        [$catalog, $tasks] = $this->mockServices();
        $tasks->shouldNotReceive('createTask');

        $response = $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.tasks.create', [
                'name' => 'Invalid task',
                'title_library_id' => '1',
                'unexpected' => true,
            ]))
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.structuredContent.error.code', 'validation_failed');

        $fieldErrors = $response->json('result.structuredContent.error.details.field_errors');
        $this->assertSame('缺少必填参数', $fieldErrors['arguments.prompt_id']);
        $this->assertSame('缺少必填参数', $fieldErrors['arguments.ai_model_id']);
        $this->assertSame('参数类型必须为 integer', $fieldErrors['arguments.title_library_id']);
        $this->assertSame('不支持该参数', $fieldErrors['arguments.unexpected']);
    }

    public function test_material_tools_use_the_default_tenant_scope(): void
    {
        $this->enableMcp();
        config(['geoflow.mcp_default_tenant' => 'team-a']);
        [$catalog, $tasks, $materials] = $this->mockServices();
        $materials->shouldReceive('list')
            ->once()
            ->with('title-libraries', 1, 20, ['search' => 'GEO'], 'team-a')
            ->andReturn(['type' => 'title-libraries', 'items' => []]);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.materials.list', [
                'type' => 'title-libraries',
                'search' => 'GEO',
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.type', 'title-libraries');
    }

    public function test_material_item_write_is_tenant_scoped_and_idempotent(): void
    {
        $this->enableMcp();
        config(['geoflow.mcp_default_tenant' => 'team-a']);
        [$catalog, $tasks, $materials] = $this->mockServices();
        $materials->shouldReceive('createItem')
            ->once()
            ->with('title-libraries', 9, ['title' => 'GEO 标题'], 'team-a')
            ->andReturn(['type' => 'title-libraries', 'parent_id' => 9, 'item' => ['id' => 21]]);

        $payload = $this->toolCall('geoflow.materials.items.create', [
            'type' => 'title-libraries',
            'parent_id' => 9,
            'title' => 'GEO 标题',
            'idempotency_key' => 'title-21',
        ]);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $payload)
            ->assertOk()
            ->assertJsonPath('result.structuredContent.item.id', 21);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $payload)
            ->assertOk()
            ->assertJsonPath('result.structuredContent.item.id', 21);
    }

    public function test_task_monitoring_tools_are_exposed_with_tenant_scope(): void
    {
        $this->enableMcp();
        config(['geoflow.mcp_default_tenant' => 'team-a']);
        [$catalog, $tasks] = $this->mockServices();
        $tasks->shouldReceive('ensureTaskInScope')->once()->with(5, 'team-a');
        $tasks->shouldReceive('listTaskJobs')->once()->with(5, 'failed', 10)->andReturn(['items' => [['id' => 9, 'status' => 'failed']]]);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.tasks.jobs', ['task_id' => 5, 'status' => 'failed', 'limit' => 10]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.items.0.id', 9);
    }

    public function test_job_detail_is_checked_against_the_tenant_before_loading(): void
    {
        $this->enableMcp();
        config(['geoflow.mcp_default_tenant' => 'team-a']);
        [$catalog, $tasks] = $this->mockServices();
        $tasks->shouldReceive('ensureJobInScope')->once()->with(9, 'team-a');
        $tasks->shouldReceive('getJobForMcp')->once()->with(9)->andReturn(['id' => 9, 'status' => 'failed']);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.jobs.get', ['job_id' => 9]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.id', 9);
    }

    public function test_url_import_preview_and_commit_require_tenant_and_confirmation(): void
    {
        $this->enableMcp();
        config(['geoflow.mcp_default_tenant' => 'team-a']);
        [$catalog, $tasks, $materials] = $this->mockServices();
        $urlImports = Mockery::mock(McpUrlImportService::class);
        app()->instance(McpUrlImportService::class, $urlImports);
        $urlImports->shouldReceive('create')->once()->andReturn(['id' => 41, 'status' => 'queued']);
        $urlImports->shouldReceive('commit')->once()->with(41, Mockery::type(McpAuthContext::class))->andReturn(['job_id' => 41, 'status' => 'imported', 'summary' => ['titles' => 3]]);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.url_import.create', ['url' => 'https://example.com', 'project_name' => 'Example']))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.id', 41);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.url_import.commit', ['job_id' => 41, 'confirmation' => 'IMPORT']))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.summary.titles', 3);
    }

    public function test_url_import_commit_schema_rejects_missing_confirmation(): void
    {
        $this->enableMcp();
        config(['geoflow.mcp_default_tenant' => 'team-a']);
        [$catalog, $tasks, $materials] = $this->mockServices();
        $urlImports = Mockery::mock(McpUrlImportService::class);
        app()->instance(McpUrlImportService::class, $urlImports);
        $urlImports->shouldNotReceive('commit');

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.url_import.commit', ['job_id' => 41]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.error.code', 'validation_failed');
    }

    public function test_article_create_checks_all_associated_records_against_the_tenant(): void
    {
        $this->enableMcp();
        config(['geoflow.mcp_default_tenant' => 'team-a']);
        [$catalog, $tasks, $materials] = $this->mockServices();
        $articles = Mockery::mock(ArticleGeoFlowService::class);
        app()->instance(ArticleGeoFlowService::class, $articles);

        $tasks->shouldReceive('ensureTaskInScope')->once()->with(5, 'team-a');
        $materials->shouldReceive('show')->once()->with('categories', 7, 'team-a')->andReturn(['item' => ['id' => 7]]);
        $materials->shouldReceive('show')->once()->with('authors', 8, 'team-a')->andReturn(['item' => ['id' => 8]]);
        $articles->shouldReceive('createArticle')
            ->once()
            ->with(Mockery::on(static fn (array $input): bool => $input['task_id'] === 5 && $input['category_id'] === 7 && $input['author_id'] === 8), null)
            ->andReturn(['id' => 13, 'title' => 'Scoped article']);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', $this->toolCall('geoflow.articles.create', [
                'title' => 'Scoped article',
                'content' => 'Content',
                'category_id' => 7,
                'author_id' => 8,
                'task_id' => 5,
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.id', 13);
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

        Http::fake([
            '*oauth/userinfo' => Http::response(['sub' => 'user-1', 'selected_team_id' => 'team-a', 'scope' => 'tasks:read'], 200),
        ]);

        [$catalog, $tasks] = $this->mockServices();
        $tasks->shouldReceive('ensureTaskInScope')->once()->with(5, 'team-a');
        $tasks->shouldReceive('getTask')->once()->with(5)->andReturn(['id' => 5, 'name' => 'T']);

        $this->withHeader('Authorization', 'Bearer '.$this->fakeSsoAccessToken())
            ->postJson('/mcp', $this->toolCall('geoflow.tasks.get', ['task_id' => 5]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.id', 5);
    }

    public function test_sso_token_without_team_is_rejected(): void
    {
        $this->enableMcp('ci-secret');

        Http::fake([
            '*oauth/userinfo' => Http::response(['sub' => 'user-1'], 200),
        ]);

        $this->mockServices();

        $this->withHeader('Authorization', 'Bearer '.$this->fakeSsoAccessToken())
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', -32001);
    }

    public function test_system_token_rejected_when_disabled(): void
    {
        $this->enableMcp('ci-secret');
        config(['geoflow.mcp_allow_system_token' => false]);

        Http::fake([
            '*oauth/userinfo' => Http::response([], 401),
        ]);

        $this->mockServices();

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', -32001);
    }

    public function test_opaque_invalid_token_is_rejected_without_calling_sso(): void
    {
        $this->enableMcp('ci-secret');
        Http::fake();
        $this->mockServices();

        $this->withHeader('Authorization', 'Bearer invalid-opaque-token')
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', -32001);

        Http::assertNothingSent();
    }

    public function test_system_token_requires_tenant_or_explicit_cross_tenant_mode(): void
    {
        config([
            'geoflow.mcp_enabled' => true,
            'geoflow.mcp_token' => 'ci-secret',
            'geoflow.mcp_allow_cross_tenant' => false,
            'geoflow.mcp_default_tenant' => '',
        ]);

        $this->withHeader('Authorization', 'Bearer ci-secret')
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
            ->assertUnauthorized();
    }

    public function test_sso_token_without_required_mcp_scope_cannot_read_catalog(): void
    {
        $this->enableMcp('ci-secret');
        Http::fake([
            '*oauth/userinfo' => Http::response(['sub' => 'user-2', 'selected_team_id' => 'team-a', 'scope' => 'tasks:read'], 200),
        ]);
        $this->mockServices();

        $this->withHeader('Authorization', 'Bearer '.$this->fakeSsoAccessToken())
            ->postJson('/mcp', $this->toolCall('geoflow.catalog', []))
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.structuredContent.error.code', 'tool_error');
    }

    private function fakeSsoAccessToken(): string
    {
        $encode = static fn (array $value): string => rtrim(strtr(base64_encode((string) json_encode($value)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode([
            'iss' => (string) config('sso.issuer'),
            'aud' => (string) config('sso.client_id'),
        ]).'.signature';
    }
}
