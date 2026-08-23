<?php

namespace Tests\Feature;

use App\Http\McpAuthContext;
use App\Jobs\GenerateEnterpriseKnowledgeDraftJob;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\KnowledgeBase;
use App\Services\GeoFlow\EnterpriseKnowledgeDraftService;
use App\Services\Mcp\McpEnterpriseKnowledgeService;
use App\Services\Mcp\McpToolException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class McpEnterpriseKnowledgeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_owns_project_and_source_by_tenant_and_queues_generation(): void
    {
        Queue::fake();
        $service = new McpEnterpriseKnowledgeService(app(EnterpriseKnowledgeDraftService::class));

        $result = $service->create([
            'name' => 'Company profile',
            'description' => 'Business',
            'content' => 'Source material',
        ], $this->auth('team-a'));

        $project = EnterpriseKnowledgeProject::query()->findOrFail($result['id']);
        $this->assertSame('team-a', $project->sso_team_id);
        $this->assertSame(1, $project->sources()->count());
        Queue::assertPushed(GenerateEnterpriseKnowledgeDraftJob::class, static fn (GenerateEnterpriseKnowledgeDraftJob $job): bool => $job->projectId === $project->id);
    }

    public function test_status_cannot_read_another_tenant_project(): void
    {
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => 'Team B',
            'status' => 'reviewing',
            'sso_team_id' => 'team-b',
        ]);
        $service = new McpEnterpriseKnowledgeService(app(EnterpriseKnowledgeDraftService::class));

        $this->expectExceptionMessage('企业知识项目不存在');
        $service->status((int) $project->id, $this->auth('team-a'));
    }

    public function test_validate_and_autosave_persist_reviewable_tenant_draft(): void
    {
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => 'Tenant draft',
            'status' => 'published',
            'draft_content' => '# Old draft',
            'sso_team_id' => 'team-a',
        ]);
        $service = new McpEnterpriseKnowledgeService(app(EnterpriseKnowledgeDraftService::class));

        $validation = $service->validate([
            'project_id' => (int) $project->id,
            'content' => '# 企业介绍'."\n".'新的企业知识草稿',
        ], $this->auth('team-a'));
        $this->assertGreaterThan(0, $validation['validation_count']);

        $saved = $service->autosave([
            'project_id' => (int) $project->id,
            'content' => '# 企业介绍'."\n".'已审核的企业知识草稿',
        ], $this->auth('team-a'));

        $this->assertTrue($saved['saved']);
        $project->refresh();
        $this->assertSame('reviewing', $project->status);
        $this->assertSame('# 企业介绍'."\n".'已审核的企业知识草稿', $project->draft_content);
        $this->assertNotEmpty($project->validationItems());
    }

    public function test_publish_requires_explicit_confirmation(): void
    {
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => 'Confirmation gate',
            'status' => 'reviewing',
            'draft_content' => '# 企业介绍'."\n".'待发布内容',
            'sso_team_id' => 'team-a',
        ]);
        $service = new McpEnterpriseKnowledgeService(app(EnterpriseKnowledgeDraftService::class));

        $this->expectException(McpToolException::class);
        $service->publish((int) $project->id, 'YES', $this->auth('team-a'));
    }

    public function test_publish_creates_tenant_owned_knowledge_base_chunks_and_revision(): void
    {
        $content = '# 企业介绍'."\n".'星河智能提供 GEO 内容工程服务。'."\n\n".'## 产品能力'."\n".'知识治理与内容生成。';
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => 'Publish tenant knowledge',
            'description' => 'MCP publish regression',
            'status' => 'reviewing',
            'draft_content' => $content,
            'sso_team_id' => 'team-a',
        ]);
        $service = new McpEnterpriseKnowledgeService(app(EnterpriseKnowledgeDraftService::class));

        $result = $service->publish((int) $project->id, 'PUBLISH', $this->auth('team-a'));

        $this->assertSame('published', $result['status']);
        $this->assertGreaterThan(0, $result['chunk_count']);
        $project->refresh();
        $knowledgeBase = KnowledgeBase::query()->findOrFail($result['knowledge_base_id']);
        $this->assertSame('team-a', $knowledgeBase->sso_team_id);
        $this->assertSame($content, $knowledgeBase->content);
        $this->assertGreaterThan(0, $knowledgeBase->chunks()->count());
        $this->assertDatabaseHas('enterprise_knowledge_revisions', [
            'enterprise_knowledge_project_id' => (int) $project->id,
            'source' => 'publish',
        ]);
        $this->assertSame((int) $knowledgeBase->id, (int) $project->published_knowledge_base_id);
    }

    public function test_repeated_publish_returns_existing_knowledge_base_without_duplicate_revision(): void
    {
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => 'Idempotent knowledge',
            'status' => 'reviewing',
            'draft_content' => '# 企业介绍\n可重复发布测试',
            'sso_team_id' => 'team-a',
        ]);
        $service = new McpEnterpriseKnowledgeService(app(EnterpriseKnowledgeDraftService::class));

        $first = $service->publish((int) $project->id, 'PUBLISH', $this->auth('team-a'));
        $second = $service->publish((int) $project->id, 'PUBLISH', $this->auth('team-a'));

        $this->assertSame($first['knowledge_base_id'], $second['knowledge_base_id']);
        $this->assertSame(1, $project->fresh()->revisions()->count());
    }

    private function auth(?string $tenantId): McpAuthContext
    {
        return new McpAuthContext(
            McpAuthContext::SCOPE_WRITE,
            'hash',
            $tenantId,
            null,
            ['materials:read', 'materials:write'],
        );
    }
}
