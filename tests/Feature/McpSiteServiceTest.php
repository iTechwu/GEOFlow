<?php

namespace Tests\Feature;

use App\Http\McpAuthContext;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Services\Mcp\McpSiteService;
use App\Services\Mcp\McpToolException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpSiteServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_only_returns_published_articles_from_the_authenticated_team(): void
    {
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Agent']);
        $teamATask = Task::query()->create(['name' => 'Team A', 'sso_team_id' => 'team-a', 'status' => 'active']);
        $teamBTask = Task::query()->create(['name' => 'Team B', 'sso_team_id' => 'team-b', 'status' => 'active']);
        $this->article('Visible article', 'visible-article', $teamATask, $category, $author);
        $this->article('Hidden article', 'hidden-article', $teamBTask, $category, $author);
        $this->article('Draft article', 'draft-article', $teamATask, $category, $author, 'draft');

        $result = app(McpSiteService::class)->search(['search' => 'article'], $this->auth('team-a'));

        $this->assertSame('team-a', $result['tenant_id']);
        $this->assertSame(['visible-article'], collect($result['items'])->pluck('slug')->all());
        $this->assertSame(1, $result['pagination']['total']);
    }

    public function test_article_read_does_not_increment_view_count_and_redacts_long_content(): void
    {
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Agent']);
        $task = Task::query()->create(['name' => 'Team A', 'sso_team_id' => 'team-a', 'status' => 'active']);
        $article = $this->article('Long article', 'long-article', $task, $category, $author, 'published', 7, str_repeat('x', 50010));

        $result = app(McpSiteService::class)->article(['slug' => 'long-article'], $this->auth('team-a'));

        $this->assertSame(7, $article->fresh()->view_count);
        $this->assertTrue($result['article']['content_truncated']);
        $this->assertSame(50000, mb_strlen($result['article']['content']));
    }

    public function test_archive_filters_published_articles_by_year_and_month(): void
    {
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Agent']);
        $task = Task::query()->create(['name' => 'Team A', 'sso_team_id' => 'team-a', 'status' => 'active']);
        $this->article('May article', 'may-article', $task, $category, $author, 'published', 0, 'May', '2026-05-20 10:00:00');
        $this->article('June article', 'june-article', $task, $category, $author, 'published', 0, 'June', '2026-06-20 10:00:00');

        $result = app(McpSiteService::class)->archive(['year' => 2026, 'month' => 5], $this->auth('team-a'));

        $this->assertSame(['may-article'], collect($result['items'])->pluck('slug')->all());
        $this->assertSame(1, $result['pagination']['total']);
    }

    public function test_site_tools_require_a_tenant(): void
    {
        $this->expectException(McpToolException::class);

        app(McpSiteService::class)->search([], $this->auth(null));
    }

    private function article(string $title, string $slug, Task $task, Category $category, Author $author, string $status = 'published', int $views = 0, string $content = 'Content', string $publishedAt = '2026-05-20 10:00:00'): Article
    {
        return Article::query()->create([
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $content,
            'content' => $content,
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => $status,
            'review_status' => 'approved',
            'view_count' => $views,
            'published_at' => $status === 'published' ? $publishedAt : null,
        ]);
    }

    private function auth(?string $tenantId): McpAuthContext
    {
        return new McpAuthContext(McpAuthContext::SCOPE_READ, 'hash', $tenantId, null, ['articles:read']);
    }
}
