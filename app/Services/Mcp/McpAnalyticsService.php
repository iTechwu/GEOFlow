<?php

namespace App\Services\Mcp;

use App\Exceptions\ApiException;
use App\Http\McpAuthContext;
use App\Services\Admin\Analytics\AnalyticsFilter;
use App\Services\Admin\Analytics\AnalyticsLogQueryService;
use App\Services\Admin\Analytics\AnalyticsOverviewService;

class McpAnalyticsService
{
    public function __construct(
        private readonly AnalyticsOverviewService $overview,
        private readonly AnalyticsLogQueryService $logs,
    ) {}

    /** @param array<string,mixed> $input */
    public function overview(array $input, McpAuthContext $auth): array
    {
        $tenantId = trim((string) $auth->tenantId);
        if ($tenantId === '') {
            throw new McpToolException('Analytics MCP 工具必须绑定明确的租户');
        }

        $filter = AnalyticsFilter::fromRequest($input, $tenantId);
        if ($filter->dateFrom->diffInDays($filter->dateTo) > 90) {
            throw new ApiException('analytics_range_too_large', 'Analytics 查询时间范围不能超过 90 天', 422, [
                'max_days' => 90,
            ]);
        }

        $taskHealth = $this->overview->taskHealth($filter);
        $taskHealth['recent_failures'] = array_map(static function (mixed $failure): array {
            $row = is_object($failure) ? get_object_vars($failure) : (is_array($failure) ? $failure : []);
            unset($row['error_message']);

            return $row;
        }, $taskHealth['recent_failures'] ?? []);

        $traffic = $this->logs->summary($filter);
        $traffic['top_paths'] = array_values(array_filter(array_map(
            static function (mixed $row): ?array {
                $path = is_object($row) ? (string) ($row->path ?? '') : (is_array($row) ? (string) ($row['path'] ?? '') : '');
                $path = parse_url($path, PHP_URL_PATH);
                if (! is_string($path) || ! preg_match('#^/(?:article|category|archive)(?:/|$)#', $path)) {
                    return null;
                }

                $data = is_object($row) ? get_object_vars($row) : (is_array($row) ? $row : []);
                $data['path'] = $path;

                return $data;
            },
            is_array($traffic['top_paths'] ?? null) ? $traffic['top_paths'] : [],
        )));

        $kpis = $this->overview->kpis($filter, true);
        unset($kpis['ai_calls']);

        return [
            'tenant_id' => $tenantId,
            'filter' => $filter->toArray(),
            'kpis' => $kpis,
            'publication_trend' => $this->overview->publicationTrend($filter),
            'task_trend' => $this->overview->taskTrend($filter),
            'content_funnel' => $this->overview->contentFunnel($filter),
            'distribution_summary' => $this->overview->distributionSummary($filter),
            'top_content' => $this->overview->topContent($filter, 10),
            'category_distribution' => $this->overview->categoryDistribution($filter),
            'performance' => $this->overview->performanceStats($filter),
            'latest_articles' => $this->overview->latestArticles($filter, 10),
            'task_health' => $taskHealth,
            'url_import_health' => $this->overview->urlImportHealth($filter),
            'traffic' => $traffic,
        ];
    }
}
