<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Task;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_tasks_filters_by_sso_team_id(): void
    {
        Task::query()->create(['name' => 'team-a-task', 'sso_team_id' => 'team-a', 'status' => 'active']);
        Task::query()->create(['name' => 'team-b-task', 'sso_team_id' => 'team-b', 'status' => 'active']);
        Task::query()->create(['name' => 'orphan-task', 'sso_team_id' => null, 'status' => 'active']);

        $result = app(TaskMonitoringQueryService::class)->listTasksPaginated(1, 20, ['sso_team_id' => 'team-a']);

        $names = collect($result['items'])->pluck('name')->all();
        $this->assertSame(['team-a-task'], $names);
    }

    public function test_ensure_task_in_scope_allows_matching_team(): void
    {
        $task = Task::query()->create(['name' => 'own', 'sso_team_id' => 'team-a', 'status' => 'active']);

        app(TaskLifecycleService::class)->ensureTaskInScope((int) $task->id, 'team-a');

        $this->addToAssertionCount(1);
    }

    public function test_ensure_task_in_scope_denies_other_team(): void
    {
        $task = Task::query()->create(['name' => 'other', 'sso_team_id' => 'team-b', 'status' => 'active']);

        try {
            app(TaskLifecycleService::class)->ensureTaskInScope((int) $task->id, 'team-a');
            $this->fail('Expected ApiException was not thrown');
        } catch (ApiException $exception) {
            $this->assertSame('task_not_found', $exception->getErrorCode());
        }
    }

    public function test_ensure_task_in_scope_allows_system_tenant(): void
    {
        $task = Task::query()->create(['name' => 'any', 'sso_team_id' => 'team-b', 'status' => 'active']);

        app(TaskLifecycleService::class)->ensureTaskInScope((int) $task->id, null);
        app(TaskLifecycleService::class)->ensureTaskInScope((int) $task->id, '');

        $this->addToAssertionCount(2);
    }
}
