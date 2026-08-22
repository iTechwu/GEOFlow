<?php

namespace App\Jobs;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\DistributionRetryPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessArticleDistributionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(private readonly int $distributionId) {}

    public function handle(DistributionOrchestrator $orchestrator, DistributionRetryPolicy $retryPolicy): void
    {
        $distribution = ArticleDistribution::query()->whereKey($this->distributionId)->first();
        if (! $distribution) {
            return;
        }

        try {
            if (! $orchestrator->process($distribution)) {
                return;
            }
        } catch (Throwable $e) {
            $distribution = ArticleDistribution::query()->whereKey($this->distributionId)->first();
            if (! $distribution) {
                return;
            }
            $distribution->loadMissing(['article.task.distributionChannels', 'channel']);
            $attemptCount = (int) $distribution->attempt_count;
            $maxAttempts = (int) ($distribution->article?->task?->distributionChannels
                ?->firstWhere('id', (int) $distribution->distribution_channel_id)
                ?->pivot?->max_attempts ?? 3);
            $shouldRetry = $retryPolicy->shouldRetry($e, $attemptCount, $maxAttempts);
            $retryAt = $shouldRetry ? $retryPolicy->retryAt($attemptCount) : null;
            if ((string) $distribution->channel?->status !== DistributionChannel::STATUS_ACTIVE) {
                $shouldRetry = false;
                $retryAt = null;
            }

            $distribution->forceFill([
                'status' => $shouldRetry ? 'queued' : 'failed',
                'last_error_message' => mb_substr($e->getMessage(), 0, 1000),
                'last_attempt_at' => now(),
                'next_retry_at' => $retryAt,
            ])->save();

            $orchestrator->log(
                $shouldRetry ? 'warning' : 'error',
                '文章分发失败：'.$e->getMessage(),
                $distribution->distribution_channel_id,
                $distribution->id,
                $distribution->article_id,
                ['event' => $shouldRetry ? 'distribution.retry_scheduled' : 'distribution.failed']
            );

            if ($shouldRetry) {
                self::dispatch((int) $distribution->id)
                    ->onQueue('distribution')
                    ->delay($retryAt);
            }
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        try {
            $message = trim((string) $exception?->getMessage());
            $errorMessage = mb_substr(
                '队列中断: '.($message !== '' ? $message : '队列任务异常退出'),
                0,
                1000,
            );

            $updated = ArticleDistribution::query()
                ->whereKey($this->distributionId)
                ->where('status', 'sending')
                ->update([
                    'status' => 'failed',
                    'last_error_message' => $errorMessage,
                    'last_attempt_at' => now(),
                    'next_retry_at' => null,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                return;
            }

            $distribution = ArticleDistribution::query()->whereKey($this->distributionId)->first();
            if (! $distribution) {
                return;
            }

            app(DistributionOrchestrator::class)->log(
                'error',
                '文章分发队列中断：'.$errorMessage,
                (int) $distribution->distribution_channel_id,
                (int) $distribution->id,
                (int) $distribution->article_id,
                ['event' => 'distribution.failed'],
            );
        } catch (Throwable) {
            // 避免失败回调自身再抛错导致队列 Worker 重复记录异常。
        }
    }
}
