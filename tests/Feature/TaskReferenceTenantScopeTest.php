<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Prompt;
use App\Models\Task;
use App\Services\GeoFlow\TaskLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskReferenceTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_task_rejects_cross_tenant_prompt(): void
    {
        $task = Task::query()->create(['name' => 't', 'sso_team_id' => 'team-a', 'status' => 'active']);
        $prompt = Prompt::query()->create(['name' => 'team-b-prompt', 'type' => 'content', 'content' => 'x', 'sso_team_id' => 'team-b']);

        try {
            app(TaskLifecycleService::class)->updateTask((int) $task->id, ['prompt_id' => (int) $prompt->id]);
            $this->fail('Expected ApiException was not thrown');
        } catch (ApiException $exception) {
            $this->assertSame('validation_failed', $exception->getErrorCode());
        }
    }

    public function test_update_task_allows_same_team_prompt(): void
    {
        $task = Task::query()->create(['name' => 't', 'sso_team_id' => 'team-a', 'status' => 'active']);
        $prompt = Prompt::query()->create(['name' => 'team-a-prompt', 'type' => 'content', 'content' => 'x', 'sso_team_id' => 'team-a']);

        $result = app(TaskLifecycleService::class)->updateTask((int) $task->id, ['prompt_id' => (int) $prompt->id]);

        $this->assertSame((int) $prompt->id, (int) $result['prompt_id']);
    }

    public function test_update_task_without_team_is_not_scope_limited(): void
    {
        $task = Task::query()->create(['name' => 't', 'sso_team_id' => null, 'status' => 'active']);
        $prompt = Prompt::query()->create(['name' => 'team-b-prompt', 'type' => 'content', 'content' => 'x', 'sso_team_id' => 'team-b']);

        $result = app(TaskLifecycleService::class)->updateTask((int) $task->id, ['prompt_id' => (int) $prompt->id]);

        $this->assertSame((int) $prompt->id, (int) $result['prompt_id']);
    }
}
