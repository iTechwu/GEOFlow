<?php

namespace Tests\Feature;

use App\Models\AnalyticsGoal;
use App\Models\Category;
use App\Models\Task;
use App\Services\Mcp\McpAnalyticsService;
use Illuminate\Support\Str;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class McpAnalyticsGoalsTest extends TestCase
{
    use RefreshDatabase;

    private McpAnalyticsService $service;

    private function auth(string $tenantId): \App\Http\McpAuthContext
    {
        return new \App\Http\McpAuthContext(
            \App\Http\McpAuthContext::SCOPE_READ,
            'hash-'.$tenantId,
            $tenantId,
            null,
            ['analytics:read'],
        );
    }

    private function seedTenant(string $teamId): array
    {
        $category = Category::create([
            'name' => '测试分类',
            'slug' => 'cat-'.Str::random(6),
            'sso_team_id' => $teamId,
        ]);
        $task = Task::create([
            'sso_team_id' => $teamId,
            'name' => '任务-'.$teamId,
            'status' => 'completed',
            'created_count' => 0,
            'published_count' => 0,
        ]);
        $article = \App\Models\Article::create([
            'task_id' => $task->id,
            'category_id' => $category->id,
            'title' => '已发布文章',
            'slug' => 'slug-'.Str::random(6),
            'status' => 'published',
            'published_at' => now()->startOfMonth()->addDays(2),
        ]);

        return [$category, $task, $article];
    }

    public function test_returns_attainment_for_tenant_global_goal(): void
    {
        $teamId = 'team-'.Str::random(6);
        [$category, $task, $article] = $this->seedTenant($teamId);
        $month = now()->format('Y-m');
        AnalyticsGoal::create([
            'sso_team_id' => $teamId,
            'category_id' => null,
            'month' => $month,
            'metric' => 'published',
            'target' => 4,
        ]);

        $result = app(McpAnalyticsService::class)->goals(['month' => $month], $this->auth($teamId));

        $this->assertSame($month, $result['month']);
        $this->assertCount(1, $result['goals']);
        $goal = $result['goals'][0];
        $this->assertSame('global', $goal['scope']);
        $this->assertSame('published', $goal['metric']);
        $this->assertSame(4, $goal['target']);
        $this->assertSame(1, $goal['actual']);
        $this->assertSame(25, $goal['attainment_pct']);
        $this->assertIsInt($goal['pace_pct']);
    }

    public function test_scopes_goals_to_the_calling_tenant(): void
    {
        $teamA = 'team-a-'.Str::random(6);
        $teamB = 'team-b-'.Str::random(6);
        $this->seedTenant($teamA);
        $month = now()->format('Y-m');
        AnalyticsGoal::create([
            'sso_team_id' => $teamA,
            'category_id' => null,
            'month' => $month,
            'metric' => 'published',
            'target' => 10,
        ]);

        $result = app(McpAnalyticsService::class)->goals(['month' => $month], $this->auth($teamB));

        $this->assertSame([], $result['goals']);
    }

    public function test_category_goal_only_counts_articles_in_that_category(): void
    {
        $teamId = 'team-'.Str::random(6);
        [$category] = $this->seedTenant($teamId);
        $month = now()->format('Y-m');
        AnalyticsGoal::create([
            'sso_team_id' => $teamId,
            'category_id' => $category->id,
            'month' => $month,
            'metric' => 'published',
            'target' => 2,
        ]);

        $result = app(McpAnalyticsService::class)->goals(['month' => $month], $this->auth($teamId));

        $goal = $result['goals'][0];
        $this->assertSame('category', $goal['scope']);
        $this->assertSame($category->id, $goal['category_id']);
        $this->assertSame(1, $goal['actual']);
    }

    public function test_month_without_goals_returns_empty_list(): void
    {
        $teamId = 'team-'.Str::random(6);
        $this->seedTenant($teamId);

        $result = app(McpAnalyticsService::class)->goals(['month' => now()->format('Y-m')], $this->auth($teamId));

        $this->assertSame([], $result['goals']);
    }

    public function test_invalid_month_is_rejected(): void
    {
        $teamId = 'team-'.Str::random(6);
        $this->expectException(\InvalidArgumentException::class);
        app(McpAnalyticsService::class)->goals(['month' => '2026-13'], $this->auth($teamId));
    }
}
