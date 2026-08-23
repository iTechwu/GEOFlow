<?php

namespace Tests\Feature;

use App\Http\McpAuthContext;
use App\Models\UrlImportJob;
use App\Services\GeoFlow\UrlImportProcessingService;
use App\Services\Mcp\McpUrlImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpUrlImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_is_tenant_scoped_and_preview_is_redacted_to_a_bounded_size(): void
    {
        $service = new McpUrlImportService(app(UrlImportProcessingService::class));
        $job = UrlImportJob::query()->create([
            'url' => 'https://example.com',
            'normalized_url' => 'https://example.com',
            'source_domain' => 'example.com',
            'status' => 'completed',
            'current_step' => 'preview',
            'progress_percent' => 100,
            'options_json' => '{}',
            'result_json' => json_encode([
                'page' => ['title' => 'Example'],
                'analysis' => [
                    'summary' => 'Summary',
                    'keywords' => ['one'],
                    'titles' => ['Title'],
                    'knowledge_markdown' => str_repeat('knowledge ', 3000),
                ],
                'import' => ['status' => 'preview'],
            ], JSON_UNESCAPED_UNICODE),
            'sso_team_id' => 'team-a',
        ]);

        $result = $service->status((int) $job->id, new McpAuthContext(
            McpAuthContext::SCOPE_READ,
            'hash',
            'team-a',
            null,
            ['materials:read'],
        ));

        $this->assertSame('Example', $result['preview']['page_title']);
        $this->assertLessThanOrEqual(12003, strlen($result['preview']['knowledge_markdown']));
        $this->assertSame('preview', $result['preview']['import']['status']);
    }

    public function test_status_cannot_read_another_tenant_job(): void
    {
        $service = new McpUrlImportService(app(UrlImportProcessingService::class));
        $job = UrlImportJob::query()->create([
            'url' => 'https://example.com',
            'normalized_url' => 'https://example.com',
            'source_domain' => 'example.com',
            'status' => 'queued',
            'sso_team_id' => 'team-b',
        ]);

        $this->expectExceptionCode(0);
        $this->expectExceptionMessage('URL 导入任务不存在');
        $service->status((int) $job->id, new McpAuthContext(
            McpAuthContext::SCOPE_READ,
            'hash',
            'team-a',
            null,
            ['materials:read'],
        ));
    }
}
