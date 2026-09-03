<?php

namespace App\Services\Admin\Analytics;

use App\Models\AnalyticsGoal;
use App\Models\Article;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 目标达成率读取侧（docs/0903/dashboard/06 提案）。
 *
 * 达成口径与 AnalyticsOverviewService 的 published 完全一致：
 * status=published 且 published_at 落在目标月份，按租户（tasks.sso_team_id）
 * 与可选分类过滤。views 走 view_logs join（同 AnalyticsLogQueryService::baseLocalQuery）。
 * pace 按自然日线性：已过天数 / 当月总天数。
 */
class AnalyticsGoalsService
{
    public function goalsForMonth(?string $tenantId, string $month): array
    {
        $this->assertMonth($month);

        $start = Carbon::createFromFormat('Y-m', $month, config('app.timezone', 'UTC'))->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $goals = AnalyticsGoal::query()
            ->where('month', $month)
            ->when($tenantId !== null, fn ($query) => $query->where('sso_team_id', $tenantId))
            ->orderBy('category_id')
            ->get();

        $categoryNames = $this->categoryNames($goals);

        $rows = $goals->map(function (AnalyticsGoal $goal) use ($tenantId, $start, $end): array {
            $actual = $goal->metric === 'views'
                ? $this->viewsActual($tenantId, $goal->category_id, $start, $end)
                : $this->publishedActual($tenantId, $goal->category_id, $start, $end);

            return [
                'goal_id' => $goal->id,
                'scope' => $goal->category_id === null ? 'global' : 'category',
                'category_id' => $goal->category_id,
                'category_name' => $goal->category_id === null ? null : ($categoryNames[$goal->category_id] ?? null),
                'month' => $goal->month,
                'metric' => $goal->metric,
                'target' => $goal->target,
                'actual' => $actual,
                'attainment_pct' => $goal->target > 0 ? (int) floor($actual / $goal->target * 100) : null,
                'pace_pct' => $this->pacePct($start),
            ];
        })->all();

        return ['month' => $month, 'goals' => $rows];
    }

    /** @param \Illuminate\Support\Collection<int, AnalyticsGoal> $goals */
    private function categoryNames($goals): array
    {
        $ids = $goals->pluck('category_id')->filter()->unique()->values();
        if ($ids->isEmpty() || ! Schema::hasTable('categories')) {
            return [];
        }

        return DB::table('categories')->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    private function publishedActual(?string $tenantId, ?int $categoryId, Carbon $start, Carbon $end): int
    {
        $query = Article::query()->whereNull('articles.deleted_at')
            ->where('articles.status', 'published')
            ->whereBetween('articles.published_at', [$start, $end]);

        if ($tenantId !== null) {
            $query->whereHas('task', fn ($taskQuery) => $taskQuery->where('sso_team_id', $tenantId));
        }
        if ($categoryId !== null) {
            $query->where('articles.category_id', $categoryId);
        }

        return (int) $query->count();
    }

    private function viewsActual(?string $tenantId, ?int $categoryId, Carbon $start, Carbon $end): int
    {
        if (! Schema::hasTable('view_logs')) {
            return 0;
        }

        $query = DB::table('view_logs')
            ->leftJoin('articles as a', 'view_logs.article_id', '=', 'a.id')
            ->leftJoin('tasks as t', 'a.task_id', '=', 't.id')
            ->whereBetween('view_logs.created_at', [$start, $end])
            ->whereNotNull('view_logs.article_id');

        if ($tenantId !== null) {
            $query->where('t.sso_team_id', $tenantId);
        }
        if ($categoryId !== null) {
            $query->where('a.category_id', $categoryId);
        }
        if (Schema::hasColumn('view_logs', 'method')) {
            $query->where('view_logs.method', 'GET');
        }

        return (int) $query->count();
    }

    private function pacePct(Carbon $monthStart): int
    {
        $now = Carbon::now($monthStart->timezone);
        if ($now->lt($monthStart)) {
            return 0;
        }

        $daysInMonth = $monthStart->daysInMonth;
        $elapsed = min($now->day, $daysInMonth);

        return (int) floor($elapsed / $daysInMonth * 100);
    }

    private function assertMonth(string $month): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new \InvalidArgumentException('month must be YYYY-MM');
        }
    }
}
