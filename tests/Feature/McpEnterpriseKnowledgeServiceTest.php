<?php

namespace Tests\Feature;

use App\Http\McpAuthContext;
use App\Jobs\GenerateEnterpriseKnowledgeDraftJob;
use App\Models\EnterpriseKnowledgeProject;
use App\Services\GeoFlow\EnterpriseKnowledgeDraftService;
use App\Services\Mcp\McpEnterpriseKnowledgeService;
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
