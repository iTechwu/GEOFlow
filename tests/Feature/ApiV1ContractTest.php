<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use App\Services\GeoFlow\TaskRealtimeBroadcastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

/**
 * API v1 契约：鉴权、scope、登录与统一信封（SQLite 测试库依赖 {@see 2026_04_18_120002_sqlite_geoflow_minimal_for_testing}）。
 */
class ApiV1ContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'https://sso.ixicai.cn/api/oauth/userinfo' => Http::response([
                'sub' => 'contract-sso-user',
                'email' => 'contract-sso@example.com',
                'name' => 'Contract SSO User',
                'scopes' => ['*'],
            ]),
        ]);
    }

    private function createActiveAdmin(string $username = 'api_test_admin', string $password = 'secret-123'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => $password,
            'email' => 't@example.com',
            'display_name' => 'API Test',
            'role' => 'admin',
            'status' => 'active',
            'sso_sub' => 'contract-sso-user',
            'sso_claims' => [],
        ]);
    }

    /** @param list<string> $scopes
     *  @return array{plain: string}
     */
    private function createBearerToken(Admin $admin, array $scopes): array
    {
        $plain = 'sso-contract-token';

        return ['plain' => $plain];
    }

    public function test_every_api_v1_route_uses_the_shared_rate_limiter(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'api/v1/'));

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $this->assertContains(
                'throttle:api',
                $route->gatherMiddleware(),
                $route->methods()[0].' /'.$route->uri().' must use throttle:api',
            );
        }
    }

    public function test_api_rate_limiter_partitions_requests_without_exposing_bearer_tokens(): void
    {
        $limiter = RateLimiter::limiter('api');
        $this->assertNotNull($limiter);

        $first = $limiter(Request::create('/api/v1/catalog', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer first-sensitive-token',
            'REMOTE_ADDR' => '192.0.2.10',
        ]));
        $second = $limiter(Request::create('/api/v1/catalog', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer second-sensitive-token',
            'REMOTE_ADDR' => '192.0.2.10',
        ]));

        $this->assertSame([120, 300], array_column($first, 'maxAttempts'));
        $this->assertNotSame($first[0]->key, $second[0]->key);
        $this->assertSame($first[1]->key, $second[1]->key);
        $this->assertStringNotContainsString('first-sensitive-token', $first[0]->key);
    }

    public function test_catalog_requires_bearer_token(): void
    {
        $this->getJson('/api/v1/catalog')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_local_api_login_route_is_not_available(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertNotFound();
    }

    public function test_sso_bearer_is_used_for_api_access(): void
    {
        $this->createActiveAdmin('u1');
        $this->withHeader('Authorization', 'Bearer sso-access-token')
            ->getJson('/api/v1/catalog')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_sso_bearer_has_api_access_without_a_local_token(): void
    {
        $admin = $this->createActiveAdmin('u3', 'p');
        $bearer = $this->createBearerToken($admin, ['catalog:read']);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/catalog')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_catalog_success_envelope_with_catalog_read_scope(): void
    {
        $admin = $this->createActiveAdmin('u4', 'p');
        $bearer = $this->createBearerToken($admin, ['catalog:read']);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/catalog')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'models',
                    'prompts',
                    'keyword_libraries',
                    'title_libraries',
                    'image_libraries',
                    'knowledge_bases',
                    'authors',
                    'categories',
                ],
                'meta' => ['request_id', 'timestamp'],
            ]);
    }

    public function test_sso_bearer_can_access_materials_without_a_local_token_scope(): void
    {
        $admin = $this->createActiveAdmin('u5', 'p');
        $bearer = $this->createBearerToken($admin, ['materials:read']);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/materials')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_keyword_library_material_crud_and_items(): void
    {
        $admin = $this->createActiveAdmin('u6', 'p');
        $bearer = $this->createBearerToken($admin, ['materials:read', 'materials:write']);

        $create = $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson('/api/v1/materials/keyword-libraries', [
                'name' => 'API Keywords',
                'description' => 'Created from API',
            ]);

        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'keyword-libraries')
            ->assertJsonPath('data.item.name', 'API Keywords');

        $libraryId = (int) $create->json('data.item.id');

        $item = $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson("/api/v1/materials/keyword-libraries/{$libraryId}/items", [
                'keyword' => 'geo automation',
            ]);

        $item->assertCreated()
            ->assertJsonPath('data.parent_id', $libraryId)
            ->assertJsonPath('data.item.keyword', 'geo automation');

        $this->assertDatabaseHas('keyword_libraries', ['id' => $libraryId, 'keyword_count' => 1]);
        $this->assertDatabaseHas('keywords', ['library_id' => $libraryId, 'keyword' => 'geo automation']);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/materials/keyword-libraries')
            ->assertOk()
            ->assertJsonPath('data.type', 'keyword-libraries')
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_delete_material_items_refreshes_counts(): void
    {
        $admin = $this->createActiveAdmin('u7', 'p');
        $bearer = $this->createBearerToken($admin, ['materials:read', 'materials:write']);
        $library = KeywordLibrary::query()->create([
            'name' => 'Delete Items',
            'description' => '',
            'keyword_count' => 1,
        ]);
        $keyword = Keyword::query()->create([
            'library_id' => $library->id,
            'keyword' => 'delete me',
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->deleteJson("/api/v1/materials/keyword-libraries/{$library->id}/items", [
                'ids' => [$keyword->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 1);

        $this->assertDatabaseMissing('keywords', ['id' => $keyword->id]);
        $this->assertDatabaseHas('keyword_libraries', ['id' => $library->id, 'keyword_count' => 0]);
    }

    public function test_task_delete_api_removes_task(): void
    {
        $admin = $this->createActiveAdmin('u8', 'p');
        $bearer = $this->createBearerToken($admin, ['tasks:write']);
        $task = Task::query()->create([
            'name' => 'API delete task',
            'status' => 'paused',
            'sso_owner_admin_id' => $admin->id,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->deleteJson("/api/v1/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.id', $task->id);

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    public function test_task_create_accepts_omitted_optional_material_fields(): void
    {
        $admin = $this->createActiveAdmin('u9', 'p');
        $bearer = $this->createBearerToken($admin, ['tasks:write']);
        $model = AiModel::query()->create([
            'name' => 'Task Create Model',
            'model_id' => 'task-create-model',
            'model_type' => 'chat',
            'status' => 'active',
        ]);
        $prompt = Prompt::query()->create([
            'name' => 'Task Create Prompt',
            'type' => 'content',
            'content' => 'Write an article.',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'Task Create Titles',
            'description' => '',
            'title_count' => 0,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson('/api/v1/tasks', [
                'name' => 'API create task with optional fields omitted',
                'title_library_id' => $titleLibrary->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $model->id,
                'status' => 'paused',
                'category_mode' => 'smart',
                'draft_limit' => 1,
                'article_limit' => 1,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'API create task with optional fields omitted')
            ->assertJsonPath('data.image_library_id', null)
            ->assertJsonPath('data.author_id', null)
            ->assertJsonPath('data.knowledge_base_id', null)
            ->assertJsonPath('data.fixed_category_id', null);

        $this->assertDatabaseHas('tasks', [
            'id' => $response->json('data.id'),
            'image_library_id' => null,
            'author_id' => null,
            'knowledge_base_id' => null,
            'fixed_category_id' => null,
        ]);
    }

    public function test_task_create_prefers_knowledge_base_ids_over_legacy_knowledge_base_id(): void
    {
        $admin = $this->createActiveAdmin('u10', 'p');
        $bearer = $this->createBearerToken($admin, ['tasks:write']);
        $model = AiModel::query()->create([
            'name' => 'Task Create Model With Knowledge',
            'model_id' => 'task-create-model-with-knowledge',
            'model_type' => 'chat',
            'status' => 'active',
        ]);
        $prompt = Prompt::query()->create([
            'name' => 'Task Create Prompt With Knowledge',
            'type' => 'content',
            'content' => 'Write an article.',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'Task Create Titles With Knowledge',
            'description' => '',
            'title_count' => 0,
        ]);
        $legacyKnowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Legacy Knowledge',
            'description' => '',
            'content' => 'Legacy content',
            'file_type' => 'markdown',
            'character_count' => 14,
            'word_count' => 14,
        ]);
        $firstKnowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Primary Knowledge',
            'description' => '',
            'content' => 'Primary content',
            'file_type' => 'markdown',
            'character_count' => 15,
            'word_count' => 15,
        ]);
        $secondKnowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Secondary Knowledge',
            'description' => '',
            'content' => 'Secondary content',
            'file_type' => 'markdown',
            'character_count' => 17,
            'word_count' => 17,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson('/api/v1/tasks', [
                'name' => 'API create task with multiple knowledge bases',
                'title_library_id' => $titleLibrary->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $model->id,
                'status' => 'paused',
                'category_mode' => 'smart',
                'draft_limit' => 1,
                'article_limit' => 1,
                'knowledge_base_id' => (int) $legacyKnowledgeBase->id,
                'knowledge_base_ids' => [
                    (int) $firstKnowledgeBase->id,
                    (int) $secondKnowledgeBase->id,
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.knowledge_base_id', (int) $firstKnowledgeBase->id)
            ->assertJsonPath('data.knowledge_base_ids.0', (int) $firstKnowledgeBase->id)
            ->assertJsonPath('data.knowledge_base_ids.1', (int) $secondKnowledgeBase->id)
            ->assertJsonCount(2, 'data.knowledge_base_ids');

        $taskId = (int) $response->json('data.id');
        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'knowledge_base_id' => (int) $firstKnowledgeBase->id,
        ]);
        $this->assertDatabaseHas('task_knowledge_bases', [
            'task_id' => $taskId,
            'knowledge_base_id' => (int) $firstKnowledgeBase->id,
            'sort_order' => 0,
        ]);
        $this->assertDatabaseHas('task_knowledge_bases', [
            'task_id' => $taskId,
            'knowledge_base_id' => (int) $secondKnowledgeBase->id,
            'sort_order' => 1,
        ]);
        $this->assertDatabaseMissing('task_knowledge_bases', [
            'task_id' => $taskId,
            'knowledge_base_id' => (int) $legacyKnowledgeBase->id,
        ]);
    }

    public function test_task_lifecycle_failure_after_inner_commit_preserves_outer_transaction_ownership(): void
    {
        $task = Task::query()->create([
            'name' => 'Outer transaction owner',
            'status' => 'paused',
        ]);
        $monitoring = Mockery::mock(TaskMonitoringQueryService::class);
        $monitoring->shouldReceive('getTaskMonitoringDetail')
            ->once()
            ->andThrow(new \RuntimeException('post-inner-read-failure'));
        $realtime = Mockery::mock(TaskRealtimeBroadcastService::class);
        $realtime->shouldReceive('broadcastOverview')->never();
        $service = new TaskLifecycleService(
            app(JobQueueService::class),
            $monitoring,
            $realtime,
            app(\App\Services\Sso\SsoIdentityService::class),
        );

        $baselineTransactionLevel = DB::transactionLevel();
        DB::beginTransaction();
        try {
            $service->updateTask((int) $task->id, ['name' => 'Updated inside outer transaction']);
            $this->fail('The monitoring failure should escape the lifecycle service.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('post-inner-read-failure', $exception->getMessage());
        }

        $this->assertSame($baselineTransactionLevel + 1, DB::transactionLevel());
        DB::rollBack();
        $this->assertSame('Outer transaction owner', $task->fresh()->name);
    }

    public function test_material_api_cannot_delete_knowledge_base_referenced_by_task_pivot(): void
    {
        $admin = $this->createActiveAdmin('u11', 'p');
        $bearer = $this->createBearerToken($admin, ['materials:write']);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'API Referenced Knowledge',
            'description' => '',
            'content' => 'Referenced content',
            'file_type' => 'markdown',
            'character_count' => 18,
            'word_count' => 18,
        ]);
        $task = Task::query()->create([
            'name' => 'API task uses knowledge',
            'status' => 'paused',
            'knowledge_base_id' => null,
        ]);
        $task->knowledgeBases()->attach((int) $knowledgeBase->id, ['sort_order' => 0]);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->deleteJson('/api/v1/materials/knowledge-bases/'.(int) $knowledgeBase->id)
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'material_in_use')
            ->assertJsonPath('error.details.task_count', 1);

        $this->assertDatabaseHas('knowledge_bases', [
            'id' => (int) $knowledgeBase->id,
        ]);
    }
}
