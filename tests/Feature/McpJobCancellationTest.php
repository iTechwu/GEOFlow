<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Jobs\ProcessGeoFlowTaskJob;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\TaskLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class McpJobCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_job_can_be_cancelled_within_its_tenant(): void
    {
        $task = Task::query()->create(['name' => 'Team A', 'sso_team_id' => 'team-a', 'status' => 'active']);
        $run = TaskRun::query()->create(['task_id' => $task->id, 'status' => 'pending', 'meta' => ['job_type' => 'generate_article']]);

        $result = app(TaskLifecycleService::class)->cancelJob((int) $run->id, 'team-a', 'Agent stopped this job');

        $this->assertSame('cancelled', $result['status']);
        $this->assertSame('cancelled', $run->fresh()->status);
    }

    public function test_completed_job_cannot_be_cancelled(): void
    {
        $task = Task::query()->create(['name' => 'Team A', 'sso_team_id' => 'team-a', 'status' => 'active']);
        $run = TaskRun::query()->create(['task_id' => $task->id, 'status' => 'completed']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('当前 Job 已完成，不能取消');
        app(TaskLifecycleService::class)->cancelJob((int) $run->id, 'team-a');
    }

    public function test_job_cancellation_cannot_cross_tenants(): void
    {
        $task = Task::query()->create(['name' => 'Team B', 'sso_team_id' => 'team-b', 'status' => 'active']);
        $run = TaskRun::query()->create(['task_id' => $task->id, 'status' => 'pending']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Job 不存在');
        app(TaskLifecycleService::class)->cancelJob((int) $run->id, 'team-a');
    }

    public function test_worker_completion_and_failure_cannot_overwrite_cancelled_job(): void
    {
        $task = Task::query()->create(['name' => 'Team A', 'sso_team_id' => 'team-a', 'status' => 'active']);
        $run = TaskRun::query()->create(['task_id' => $task->id, 'status' => 'running']);

        app(TaskLifecycleService::class)->cancelJob((int) $run->id, 'team-a');
        $queue = app(JobQueueService::class);
        $queue->completeJob((int) $run->id, (int) $task->id, null, 100, ['worker' => 'late']);
        $queue->failJob((int) $run->id, (int) $task->id, 'late failure', 100);

        $this->assertSame('cancelled', $run->fresh()->status);
    }

    public function test_models_billing_failure_does_not_retry_and_defers_next_daily_probe(): void
    {
        Queue::fake();
        $task = Task::query()->create([
            'name' => '余额退避任务',
            'status' => 'active',
            'schedule_enabled' => 1,
            'next_run_at' => now(),
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'meta' => ['attempt_count' => 0, 'max_attempts' => 3],
        ]);

        app(JobQueueService::class)->failJob(
            (int) $run->id,
            (int) $task->id,
            'AI 生成失败: HTTP request returned status code 402: Insufficient Balance',
            100,
        );

        $run = $run->fresh();
        $task = $task->fresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame(1, (int) ($run->meta['attempt_count'] ?? 0));
        $this->assertNotNull($task->next_run_at);
        $this->assertTrue($task->next_run_at->greaterThan(now()->addHours(23)));
        Queue::assertNotPushed(ProcessGeoFlowTaskJob::class);
    }

    public function test_mcp_job_summary_does_not_expose_payload_or_internal_error_details(): void
    {
        $task = Task::query()->create(['name' => 'Team A', 'sso_team_id' => 'team-a', 'status' => 'active']);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'failed',
            'error_message' => 'provider https://internal.example.test secret-token',
            'meta' => [
                'job_type' => 'generate_article',
                'payload' => ['prompt' => 'sensitive prompt'],
                'worker_id' => 'worker-host:123',
                'attempt_count' => 2,
                'max_attempts' => 3,
            ],
        ]);

        $result = app(TaskLifecycleService::class)->getJobForMcp((int) $run->id);

        $this->assertSame('任务执行失败', $result['error_message']);
        $this->assertArrayNotHasKey('payload', $result);
        $this->assertArrayNotHasKey('worker_id', $result);
        $this->assertArrayNotHasKey('meta', $result['task_run_summary']);
        $this->assertStringNotContainsString('internal.example.test', json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}
