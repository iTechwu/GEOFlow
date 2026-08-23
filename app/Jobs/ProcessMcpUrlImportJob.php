<?php

namespace App\Jobs;

use App\Models\UrlImportJob;
use App\Services\GeoFlow\UrlImportProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * MCP URL 导入的异步预览处理，避免网络抓取和 AI 分析阻塞 JSON-RPC 请求。
 */
class ProcessMcpUrlImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $urlImportJobId) {}

    public function handle(UrlImportProcessingService $processor): void
    {
        $job = UrlImportJob::query()->find($this->urlImportJobId);
        if (! $job || (string) $job->status !== 'running') {
            return;
        }

        $processor->process($job);
    }

    public function failed(): void
    {
        UrlImportJob::query()
            ->whereKey($this->urlImportJobId)
            ->where('status', 'running')
            ->update([
                'status' => 'failed',
                'current_step' => 'failed',
                'progress_percent' => 100,
                'error_message' => 'URL 导入后台任务失败',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
