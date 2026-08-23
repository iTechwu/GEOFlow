<?php

namespace Tests\Feature;

use App\Http\McpAuthContext;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\Task;
use App\Services\Mcp\McpDistributionService;
use App\Services\Mcp\McpToolException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpDistributionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_channels_and_jobs_are_filtered_to_the_authenticated_team(): void
    {
        [$category, $author] = $this->materials();
        $teamATask = Task::query()->create(['name' => 'Team A', 'sso_team_id' => 'team-a', 'status' => 'active']);
        $teamBTask = Task::query()->create(['name' => 'Team B', 'sso_team_id' => 'team-b', 'status' => 'active']);
        $teamAChannel = $this->channel('Team A channel', 'team-a');
        $teamBChannel = $this->channel('Team B channel', 'team-b');
        $teamAArticle = $this->article('A article', 'a-article', $teamATask, $category, $author);
        $teamBArticle = $this->article('B article', 'b-article', $teamBTask, $category, $author);
        $this->job($teamAArticle, $teamAChannel, 'a-key');
        $this->job($teamBArticle, $teamBChannel, 'b-key');

        $service = app(McpDistributionService::class);
        $channels = $service->channels($this->auth('team-a'));
        $jobs = $service->jobs([], $this->auth('team-a'));

        $this->assertSame(['Team A channel'], collect($channels['items'])->pluck('name')->all());
        $this->assertSame(['a-article'], collect($jobs['items'])->pluck('article.slug')->all());
        $this->assertSame('https://team-a-channel.test/article/a-article', $jobs['items'][0]['remote_url']);
        $this->assertSame(1, $jobs['pagination']['total']);
    }

    public function test_health_reads_cached_fields_without_exposing_configuration(): void
    {
        $channel = $this->channel('Team A channel', 'team-a', [
            'last_health_status' => 'ok',
            'last_health_checked_at' => '2026-08-24 10:00:00',
            'endpoint_url' => 'https://example.test/agent',
        ]);

        $result = app(McpDistributionService::class)->health((int) $channel->id, $this->auth('team-a'));

        $this->assertSame('ok', $result['channel']['last_health_status']);
        $this->assertTrue($result['checked']);
        $this->assertArrayNotHasKey('endpoint_url', $result['channel']);
    }

    public function test_distribution_tools_require_a_tenant(): void
    {
        $this->expectException(McpToolException::class);

        app(McpDistributionService::class)->channels($this->auth(null));
    }

    /** @return array{Category,Author} */
    private function materials(): array
    {
        return [
            Category::query()->create(['name' => 'News', 'slug' => 'distribution-news']),
            Author::query()->create(['name' => 'Agent']),
        ];
    }

    private function channel(string $name, string $teamId, array $overrides = []): DistributionChannel
    {
        return DistributionChannel::query()->create(array_merge([
            'name' => $name,
            'domain' => strtolower(str_replace(' ', '-', $name)).'.test',
            'endpoint_url' => 'https://example.test/agent',
            'channel_type' => 'geoflow_agent',
            'status' => DistributionChannel::STATUS_ACTIVE,
            'sso_team_id' => $teamId,
        ], $overrides));
    }

    private function article(string $title, string $slug, Task $task, Category $category, Author $author): Article
    {
        return Article::query()->create([
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $title,
            'content' => $title,
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
    }

    private function job(Article $article, DistributionChannel $channel, string $key): ArticleDistribution
    {
        return ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'idempotency_key' => $key,
            'attempt_count' => 1,
            'remote_url' => 'https://'.$channel->domain.'/article/'.$article->slug.'?token=secret#fragment',
        ]);
    }

    private function auth(?string $tenantId): McpAuthContext
    {
        return new McpAuthContext(McpAuthContext::SCOPE_READ, 'hash', $tenantId, null, ['distribution:read']);
    }
}
