<?php

namespace App\Services\Mcp;

use App\Exceptions\ApiException;
use App\Http\McpAuthContext;
use App\Jobs\GenerateEnterpriseKnowledgeDraftJob;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\EnterpriseKnowledgeRevision;
use App\Models\KnowledgeBase;
use App\Services\GeoFlow\EnterpriseKnowledgeDraftService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class McpEnterpriseKnowledgeService
{
    public function __construct(private readonly EnterpriseKnowledgeDraftService $draftService) {}

    /** @param array<string,mixed> $input */
    public function create(array $input, McpAuthContext $auth): array
    {
        $teamId = $this->requiredTenant($auth);
        $name = trim((string) ($input['name'] ?? ''));
        $content = trim((string) ($input['content'] ?? ''));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 120) {
            throw new ApiException('enterprise_knowledge_name_invalid', '企业知识项目名称不能为空且不能超过 120 个字符', 422);
        }
        if ($content === '' || mb_strlen($content, 'UTF-8') > 200000) {
            throw new ApiException('enterprise_knowledge_content_invalid', '企业知识源文本不能为空且不能超过 200000 个字符', 422);
        }

        $project = DB::transaction(function () use ($name, $content, $input, $auth, $teamId): EnterpriseKnowledgeProject {
            $project = EnterpriseKnowledgeProject::query()->create([
                'name' => $name,
                'description' => trim((string) ($input['description'] ?? '')),
                'status' => 'queued',
                'structured_json' => json_encode(['draft_generation' => ['step' => 'queued', 'progress' => 0]], JSON_UNESCAPED_UNICODE),
                'created_by_admin_id' => $auth->auditAdminId,
                'sso_team_id' => $teamId,
            ]);
            $project->sources()->create([
                'original_name' => 'mcp-text-source.md',
                'file_type' => 'markdown',
                'content' => $content,
                'character_count' => mb_strlen($content, 'UTF-8'),
                'sort_order' => 0,
            ]);

            return $project;
        });

        GenerateEnterpriseKnowledgeDraftJob::dispatch((int) $project->id, $auth->auditAdminId)->onQueue('geoflow');

        return $this->serialize($project->refresh());
    }

    public function status(int $projectId, McpAuthContext $auth): array
    {
        return $this->serialize($this->find($projectId, $this->requiredTenant($auth)));
    }

    /** @param array<string,mixed> $input */
    public function list(array $input, McpAuthContext $auth): array
    {
        $teamId = $this->requiredTenant($auth);
        $search = trim((string) ($input['search'] ?? ''));
        $status = trim((string) ($input['status'] ?? ''));
        $limit = min(100, max(1, (int) ($input['limit'] ?? 50)));
        if (mb_strlen($search, 'UTF-8') > 120) {
            throw new ApiException('enterprise_knowledge_search_invalid', '企业知识搜索词不能超过 120 个字符', 422);
        }
        if ($status !== '' && ! in_array($status, ['draft', 'queued', 'processing', 'reviewing', 'published', 'failed'], true)) {
            throw new ApiException('enterprise_knowledge_status_invalid', '企业知识状态筛选无效', 422);
        }

        $projects = EnterpriseKnowledgeProject::query()
            ->where('sso_team_id', $teamId)
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest('updated_at')
            ->latest('id')
            ->limit($limit)
            ->get();

        return [
            'tenant_id' => $teamId,
            'items' => $projects->map(static fn (EnterpriseKnowledgeProject $project): array => [
                'id' => (int) $project->id,
                'name' => (string) $project->name,
                'description' => (string) $project->description,
                'status' => (string) $project->status,
                'published_knowledge_base_id' => $project->published_knowledge_base_id !== null ? (int) $project->published_knowledge_base_id : null,
                'updated_at' => optional($project->updated_at)->toIso8601String(),
            ])->values()->all(),
            'count' => $projects->count(),
        ];
    }

    /** @param array<string,mixed> $input */
    public function validate(array $input, McpAuthContext $auth): array
    {
        $project = $this->find((int) ($input['project_id'] ?? 0), $this->requiredTenant($auth));
        $this->assertGenerationComplete($project);
        $content = trim((string) ($input['content'] ?? $project->draft_content ?? ''));
        if ($content === '') {
            throw new ApiException('enterprise_knowledge_content_required', '企业知识草稿内容不能为空', 422);
        }
        $items = $this->draftService->validateDraft($content);
        $project->update([
            'draft_content' => $content,
            'validation_json' => json_encode($items, JSON_UNESCAPED_UNICODE),
        ]);

        return ['project_id' => (int) $project->id, 'validation_count' => count($items), 'validation_items' => $items];
    }

    /** @param array<string,mixed> $input */
    public function autosave(array $input, McpAuthContext $auth): array
    {
        $project = $this->find((int) ($input['project_id'] ?? 0), $this->requiredTenant($auth));
        $this->assertGenerationComplete($project);
        $content = trim((string) ($input['content'] ?? ''));
        if ($content === '' || mb_strlen($content, 'UTF-8') > 200000) {
            throw new ApiException('enterprise_knowledge_content_invalid', '企业知识草稿不能为空且不能超过 200000 个字符', 422);
        }
        $items = $this->draftService->validateDraft($content);
        $project->update([
            'draft_content' => $content,
            'validation_json' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'status' => $project->status === 'published' ? 'reviewing' : (string) $project->status,
        ]);

        return ['project_id' => (int) $project->id, 'saved' => true, 'validation_count' => count($items), 'validation_items' => $items];
    }

    public function publish(int $projectId, string $confirmation, McpAuthContext $auth): array
    {
        if ($confirmation !== 'PUBLISH') {
            throw new McpToolException('发布企业知识必须提供 confirmation=PUBLISH');
        }
        $teamId = $this->requiredTenant($auth);

        return DB::transaction(function () use ($projectId, $teamId, $auth): array {
            $project = EnterpriseKnowledgeProject::query()
                ->whereKey($projectId)
                ->where('sso_team_id', $teamId)
                ->lockForUpdate()
                ->first();
            if (! $project) {
                throw new ApiException('enterprise_knowledge_not_found', '企业知识项目不存在', 404);
            }
            $this->assertGenerationComplete($project);

            if ((string) $project->status === 'published' && $project->published_knowledge_base_id !== null) {
                $knowledgeBase = $project->publishedKnowledgeBase()->first();
                if ($knowledgeBase) {
                    return [
                        'project_id' => (int) $project->id,
                        'status' => 'published',
                        'knowledge_base_id' => (int) $knowledgeBase->id,
                        'chunk_count' => (int) $knowledgeBase->chunks()->count(),
                        'chunk_error' => null,
                    ];
                }
            }

            $content = trim((string) $project->draft_content);
            if ($content === '') {
                throw new ApiException('enterprise_knowledge_content_required', '企业知识草稿内容不能为空', 422);
            }

            $result = $this->draftService->publishToKnowledgeBase($project, $content);
            $knowledgeBase = $result['knowledge_base'];
            $project->update([
                'status' => 'published',
                'published_knowledge_base_id' => (int) $knowledgeBase->id,
                'validation_json' => json_encode($this->draftService->validateDraft($content), JSON_UNESCAPED_UNICODE),
                'error_message' => $result['chunk_error'],
            ]);
            EnterpriseKnowledgeRevision::query()->create([
                'enterprise_knowledge_project_id' => (int) $project->id,
                'content' => $content,
                'source' => 'publish',
                'summary' => 'MCP 发布',
                'created_by_admin_id' => $auth->auditAdminId,
                'content_hash' => hash('sha256', $content),
            ]);

            return [
                'project_id' => (int) $project->id,
                'status' => 'published',
                'knowledge_base_id' => (int) $knowledgeBase->id,
                'chunk_count' => (int) $result['chunk_count'],
                'chunk_error' => $result['chunk_error'] !== null ? '知识库已发布，但分块同步失败' : null,
            ];
        });
    }

    public function delete(int $projectId, string $confirmation, McpAuthContext $auth): array
    {
        if ($confirmation !== 'DELETE') {
            throw new McpToolException('删除企业知识必须提供 confirmation=DELETE');
        }
        $teamId = $this->requiredTenant($auth);

        $result = DB::transaction(function () use ($projectId, $teamId): array {
            $project = EnterpriseKnowledgeProject::query()
                ->whereKey($projectId)
                ->where('sso_team_id', $teamId)
                ->lockForUpdate()
                ->first();
            if (! $project) {
                throw new ApiException('enterprise_knowledge_not_found', '企业知识项目不存在', 404);
            }

            $knowledgeBaseId = $project->published_knowledge_base_id !== null
                ? (int) $project->published_knowledge_base_id
                : null;
            $knowledgeBase = $knowledgeBaseId !== null
                ? KnowledgeBase::query()->whereKey($knowledgeBaseId)->where('sso_team_id', $teamId)->lockForUpdate()->first()
                : null;
            if ($knowledgeBase && ($knowledgeBase->tasks()->exists() || $knowledgeBase->linkedTasks()->exists())) {
                throw new ApiException('enterprise_knowledge_in_use', '企业知识已被任务引用，不能删除', 409);
            }

            $project->delete();
            $knowledgeBase?->delete();

            return [
                'project_id' => $projectId,
                'knowledge_base_id' => $knowledgeBaseId,
                'deleted' => true,
            ];
        });

        Storage::disk('public')->deleteDirectory('uploads/enterprise-knowledge/'.$projectId);

        return $result;
    }

    private function find(int $projectId, string $teamId): EnterpriseKnowledgeProject
    {
        $project = EnterpriseKnowledgeProject::query()
            ->whereKey($projectId)
            ->where('sso_team_id', $teamId)
            ->first();
        if (! $project) {
            throw new ApiException('enterprise_knowledge_not_found', '企业知识项目不存在', 404);
        }

        return $project;
    }

    private function requiredTenant(McpAuthContext $auth): string
    {
        $teamId = trim((string) $auth->tenantId);
        if ($teamId === '') {
            throw new McpToolException('企业知识 MCP 工具必须绑定明确的租户');
        }

        return $teamId;
    }

    private function assertGenerationComplete(EnterpriseKnowledgeProject $project): void
    {
        if (in_array((string) $project->status, ['queued', 'processing'], true)) {
            throw new ApiException(
                'enterprise_knowledge_generation_in_progress',
                '企业知识草稿仍在生成，请等待状态变为 reviewing 后再操作',
                409,
            );
        }
    }

    /** @return array<string,mixed> */
    private function serialize(EnterpriseKnowledgeProject $project): array
    {
        $progress = $project->draftGenerationProgress();

        return [
            'id' => (int) $project->id,
            'name' => (string) $project->name,
            'description' => (string) $project->description,
            'status' => (string) $project->status,
            'progress' => [
                'step' => (string) ($progress['step'] ?? $project->status),
                'percent' => (int) ($progress['progress'] ?? 0),
                'updated_at' => (string) ($progress['updated_at'] ?? optional($project->updated_at)->toIso8601String()),
            ],
            'sources_count' => (int) $project->sources()->count(),
            'revisions_count' => (int) $project->revisions()->count(),
            'published_knowledge_base_id' => $project->published_knowledge_base_id !== null ? (int) $project->published_knowledge_base_id : null,
            'draft_preview' => Str::limit(trim((string) $project->draft_content), 20000, '...'),
            'has_validation_warnings' => $project->validationItems() !== [],
            'failed' => (string) $project->status === 'failed',
        ];
    }
}
