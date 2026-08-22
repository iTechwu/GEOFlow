<?php

namespace Tests\Feature;

use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProcessArticleDistributionJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_callback_marks_an_interrupted_sending_distribution_failed_once(): void
    {
        $distribution = $this->distribution('sending');
        $job = new ProcessArticleDistributionJob((int) $distribution->id);

        $job->failed(new RuntimeException('queue execution timed out'));
        $job->failed(new RuntimeException('duplicate callback'));

        $distribution->refresh();
        $this->assertSame('failed', $distribution->status);
        $this->assertSame('队列中断: queue execution timed out', $distribution->last_error_message);
        $this->assertNull($distribution->next_retry_at);
        $this->assertNotNull($distribution->last_attempt_at);

        $logs = DistributionLog::query()
            ->where('article_distribution_id', (int) $distribution->id)
            ->get();
        $this->assertCount(1, $logs);
        $this->assertSame('distribution.failed', $logs->first()?->event);
        $this->assertSame('error', $logs->first()?->level);
    }

    public function test_failed_callback_does_not_overwrite_a_completed_distribution(): void
    {
        $distribution = $this->distribution('synced');

        (new ProcessArticleDistributionJob((int) $distribution->id))
            ->failed(new RuntimeException('late timeout callback'));

        $this->assertSame('synced', $distribution->fresh()?->status);
        $this->assertSame(0, DistributionLog::query()
            ->where('article_distribution_id', (int) $distribution->id)
            ->count());
    }

    private function distribution(string $status): ArticleDistribution
    {
        $category = Category::query()->create([
            'name' => 'Queue callback category',
            'slug' => 'queue-callback-category-'.$status,
        ]);
        $author = Author::query()->create([
            'name' => 'Queue callback author',
            'email' => 'queue-callback-'.$status.'@example.test',
        ]);
        $article = Article::query()->create([
            'title' => 'Queue callback article',
            'slug' => 'queue-callback-article-'.$status,
            'content' => 'body',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'published',
            'review_status' => 'approved',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => 'Queue callback channel',
            'domain' => 'queue-callback-'.$status.'.example.test',
            'endpoint_url' => 'https://queue-callback-'.$status.'.example.test',
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);

        return ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => $status,
            'idempotency_key' => 'queue-callback-'.$status,
            'attempt_count' => 1,
            'next_retry_at' => now()->addMinute(),
            'last_attempt_at' => now()->subMinute(),
        ]);
    }
}
