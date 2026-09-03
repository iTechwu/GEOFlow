<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Http\McpAuthContext;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\Admin\Analytics\AnalyticsFilter;
use App\Services\Admin\Analytics\AnalyticsLogQueryService;
use App\Services\Admin\Analytics\AnalyticsOverviewService;
use App\Services\Mcp\McpAnalyticsService;
use App\Services\Mcp\McpToolException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class McpAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_forwards_the_authenticated_tenant_and_redacts_failure_details(): void
    {
        $overview = Mockery::mock(AnalyticsOverviewService::class);
        $logs = Mockery::mock(AnalyticsLogQueryService::class);
        $overview->shouldReceive('taskHealth')->once()->with(Mockery::on(static fn ($filter): bool => $filter->tenantId === 'team-a'))->andReturn([
            'recent_failures' => [(object) ['id' => 9, 'task_name' => 'Task A', 'error_message' => 'provider secret']],
        ]);
        $overview->shouldReceive('kpis')->once()->andReturn(['articles' => 1]);
        $overview->shouldReceive('publicationTrend')->once()->andReturn([]);
        $overview->shouldReceive('taskTrend')->once()->andReturn([]);
        $overview->shouldReceive('contentFunnel')->once()->andReturn([]);
        $overview->shouldReceive('distributionSummary')->once()->andReturn([]);
        $overview->shouldReceive('topContent')->once()->andReturn([]);
        $overview->shouldReceive('categoryDistribution')->once()->andReturn([]);
        $overview->shouldReceive('performanceStats')->once()->andReturn([]);
        $overview->shouldReceive('latestArticles')->once()->andReturn([]);
        $overview->shouldReceive('urlImportHealth')->once()->andReturn([]);
        $logs->shouldReceive('summary')->once()->andReturn([
            'kpis' => ['pv' => 2],
            'top_paths' => [
                ['path' => '/article/a?secret=1', 'views' => 3],
                ['path' => '/admin/analytics', 'views' => 99],
            ],
        ]);

        $result = (new McpAnalyticsService($overview, $logs, new \App\Services\Admin\Analytics\AnalyticsGoalsService()))->overview([], $this->auth('team-a'));

        $this->assertSame('team-a', $result['tenant_id']);
        $this->assertSame(1, $result['kpis']['articles']);
        $this->assertArrayNotHasKey('ai_calls', $result['kpis']);
        $this->assertSame([['id' => 9, 'task_name' => 'Task A']], $result['task_health']['recent_failures']);
        $this->assertSame([['path' => '/article/a', 'views' => 3]], $result['traffic']['top_paths']);
    }

    public function test_overview_requires_a_tenant(): void
    {
        $overview = Mockery::mock(AnalyticsOverviewService::class);
        $logs = Mockery::mock(AnalyticsLogQueryService::class);
        $service = new McpAnalyticsService($overview, $logs, new \App\Services\Admin\Analytics\AnalyticsGoalsService());

        $this->expectException(McpToolException::class);
        $service->overview([], $this->auth(null));
    }

    public function test_overview_rejects_a_date_window_longer_than_366_days(): void
    {
        $overview = Mockery::mock(AnalyticsOverviewService::class);
        $logs = Mockery::mock(AnalyticsLogQueryService::class);
        $service = new McpAnalyticsService($overview, $logs, new \App\Services\Admin\Analytics\AnalyticsGoalsService());

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Analytics 查询时间范围不能超过 366 天');
        $service->overview([
            'preset' => 'custom',
            'date_from' => '2025-01-01',
            'date_to' => '2026-05-01',
        ], $this->auth('team-a'));
    }

    public function test_analytics_overview_queries_follow_the_task_tenant(): void
    {
        $category = Category::query()->create(['name' => 'Analytics', 'slug' => 'analytics']);
        $author = Author::query()->create(['name' => 'Agent']);
        $teamATask = Task::query()->create(['name' => 'Team A', 'sso_team_id' => 'team-a', 'status' => 'active']);
        $teamBTask = Task::query()->create(['name' => 'Team B', 'sso_team_id' => 'team-b', 'status' => 'active']);
        Article::query()->create([
            'title' => 'A article',
            'slug' => 'a-article',
            'content' => 'A',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $teamATask->id,
            'status' => 'draft',
        ]);
        Article::query()->create([
            'title' => 'B article',
            'slug' => 'b-article',
            'content' => 'B',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $teamBTask->id,
            'status' => 'draft',
        ]);
        TaskRun::query()->create(['task_id' => $teamATask->id, 'status' => 'failed', 'error_message' => 'A failure']);
        TaskRun::query()->create(['task_id' => $teamBTask->id, 'status' => 'failed', 'error_message' => 'B failure']);

        $filter = AnalyticsFilter::fromRequest([], 'team-a');
        $overview = app(AnalyticsOverviewService::class);

        $this->assertSame(1, $overview->kpis($filter, false)['articles']);
        $this->assertSame(1, $overview->kpis($filter, false)['failed_tasks']);
        $this->assertSame(1, $overview->taskHealth($filter)['failed_jobs']);
    }

    private function auth(?string $tenantId): McpAuthContext
    {
        return new McpAuthContext(
            McpAuthContext::SCOPE_READ,
            'hash',
            $tenantId,
            null,
            ['analytics:read'],
        );
    }
}
