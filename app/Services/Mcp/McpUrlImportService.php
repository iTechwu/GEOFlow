<?php

namespace App\Services\Mcp;

use App\Exceptions\ApiException;
use App\Http\McpAuthContext;
use App\Models\UrlImportJob;
use App\Services\GeoFlow\UrlImportProcessingService;
use Illuminate\Support\Str;

class McpUrlImportService
{
    public function __construct(private readonly UrlImportProcessingService $processor) {}

    /** @param array<string,mixed> $input */
    public function create(array $input, McpAuthContext $auth): array
    {
        $teamId = $this->requiredTenant($auth);
        $normalized = $this->processor->normalizeInputUrl((string) ($input['url'] ?? ''));
        $activeJobs = UrlImportJob::query()
            ->where('sso_team_id', $teamId)
            ->whereIn('status', ['queued', 'running'])
            ->count();
        if ($activeJobs >= (int) config('geoflow.mcp_url_import_max_active', 3)) {
            throw new ApiException('url_import_rate_limited', '当前租户 URL 导入任务已达到并发上限', 429, [
                'max_active' => (int) config('geoflow.mcp_url_import_max_active', 3),
            ]);
        }
        $outputs = array_values(array_unique(array_filter(
            is_array($input['outputs'] ?? null) ? $input['outputs'] : ['knowledge', 'keywords', 'titles'],
            static fn ($output): bool => is_string($output) && in_array($output, ['knowledge', 'keywords', 'titles'], true),
        )));
        if ($outputs === []) {
            $outputs = ['knowledge', 'keywords', 'titles'];
        }

        $job = UrlImportJob::query()->create([
            'url' => (string) ($input['url'] ?? ''),
            'normalized_url' => $normalized['url'],
            'source_domain' => $normalized['host'],
            'page_title' => (string) ($input['project_name'] ?? ''),
            'status' => 'queued',
            'current_step' => 'queued',
            'progress_percent' => 0,
            'options_json' => json_encode([
                'project_name' => (string) ($input['project_name'] ?? ''),
                'source_label' => (string) ($input['source_label'] ?? ''),
                'content_language' => (string) ($input['content_language'] ?? ''),
                'notes' => (string) ($input['notes'] ?? ''),
                'outputs' => $outputs,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'result_json' => '',
            'error_message' => '',
            'created_by' => $auth->auditAdminId !== null ? (string) $auth->auditAdminId : 'mcp',
            'sso_team_id' => $teamId,
        ]);

        return $this->serialize($job);
    }

    public function run(int $jobId, McpAuthContext $auth): array
    {
        $job = $this->find($jobId, $this->requiredTenant($auth));
        if (! in_array((string) $job->status, ['completed', 'imported'], true)) {
            $job = $this->processor->process($job);
        }

        return $this->serialize($job);
    }

    public function status(int $jobId, McpAuthContext $auth): array
    {
        return $this->serialize($this->find($jobId, $this->requiredTenant($auth)));
    }

    public function commit(int $jobId, McpAuthContext $auth): array
    {
        $job = $this->find($jobId, $this->requiredTenant($auth));
        $summary = $this->processor->commit($job);

        return [
            'job_id' => $jobId,
            'status' => 'imported',
            'summary' => $summary,
        ];
    }

    private function find(int $jobId, string $teamId): UrlImportJob
    {
        $job = UrlImportJob::query()
            ->whereKey($jobId)
            ->where('sso_team_id', $teamId)
            ->first();
        if (! $job) {
            throw new ApiException('url_import_not_found', 'URL 导入任务不存在', 404);
        }

        return $job;
    }

    private function requiredTenant(McpAuthContext $auth): string
    {
        $teamId = trim((string) $auth->tenantId);
        if ($teamId === '') {
            throw new McpToolException('URL 导入必须绑定明确的 MCP 租户');
        }

        return $teamId;
    }

    /** @return array<string,mixed> */
    private function serialize(UrlImportJob $job): array
    {
        $result = json_decode((string) $job->result_json, true);
        $result = is_array($result) ? $result : [];
        $analysis = is_array($result['analysis'] ?? null) ? $result['analysis'] : [];
        $page = is_array($result['page'] ?? null) ? $result['page'] : [];
        $knowledge = trim((string) ($analysis['knowledge_markdown'] ?? ''));

        return [
            'id' => (int) $job->id,
            'status' => (string) $job->status,
            'current_step' => (string) $job->current_step,
            'progress_percent' => (int) $job->progress_percent,
            'url' => (string) $job->url,
            'source_domain' => (string) $job->source_domain,
            'page_title' => (string) $job->page_title,
            'error_message' => (string) $job->error_message,
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'preview' => [
                'page_title' => (string) ($page['title'] ?? $job->page_title),
                'library_name' => (string) ($analysis['library_name'] ?? ''),
                'summary' => (string) ($analysis['summary'] ?? ''),
                'keywords' => $this->stringList($analysis['keywords'] ?? []),
                'titles' => $this->stringList($analysis['titles'] ?? []),
                'knowledge_markdown' => Str::limit($knowledge, 12000, '...'),
                'import' => is_array($result['import'] ?? null) ? $result['import'] : ['status' => 'preview'],
            ],
        ];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($item): string => trim((string) $item),
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }
}
