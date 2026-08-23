<?php

namespace App\Services\Mcp;

use App\Exceptions\ApiException;
use App\Http\McpAuthContext;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only distribution telemetry for tenant-owned channels.
 *
 * Remote publishing, retry, pause/activate, secret rotation and package
 * downloads intentionally remain outside MCP because they have external
 * side effects or expose sensitive credentials.
 */
class McpDistributionService
{
    public function channels(McpAuthContext $auth): array
    {
        $tenantId = $this->tenantId($auth);
        $items = DistributionChannel::query()
            ->where('sso_team_id', $tenantId)
            ->orderByDesc('id')
            ->get([
                'id', 'name', 'domain', 'channel_type', 'status',
                'last_health_status', 'last_health_checked_at',
            ])
            ->map(fn (DistributionChannel $channel): array => $this->channel($channel))
            ->all();

        return ['tenant_id' => $tenantId, 'items' => $items];
    }

    /** @param array<string,mixed> $input */
    public function jobs(array $input, McpAuthContext $auth): array
    {
        $tenantId = $this->tenantId($auth);
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($input['per_page'] ?? 20)));
        $query = ArticleDistribution::query()
            ->whereHas('article.task', static fn (Builder $query) => $query->where('sso_team_id', $tenantId))
            ->whereHas('channel', static fn (Builder $query) => $query->where('sso_team_id', $tenantId))
            ->with([
                'article:id,title,slug',
                'channel:id,name,domain,channel_type',
            ]);

        if (isset($input['status']) && trim((string) $input['status']) !== '') {
            $query->where('status', trim((string) $input['status']));
        }

        $total = (clone $query)->count();
        $items = $query->orderByDesc('id')->forPage($page, $perPage)->get([
            'id', 'article_id', 'distribution_channel_id', 'action', 'status',
            'remote_id', 'remote_url', 'attempt_count', 'next_retry_at',
            'last_attempt_at', 'created_at', 'updated_at',
        ])->map(fn (ArticleDistribution $job): array => $this->job($job))->all();

        return [
            'tenant_id' => $tenantId,
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function health(int $channelId, McpAuthContext $auth): array
    {
        $tenantId = $this->tenantId($auth);
        $channel = DistributionChannel::query()
            ->whereKey($channelId)
            ->where('sso_team_id', $tenantId)
            ->first();
        if (! $channel instanceof DistributionChannel) {
            throw new ApiException('distribution_channel_not_found', '分发渠道不存在', 404);
        }

        return [
            'tenant_id' => $tenantId,
            'channel' => $this->channel($channel),
            'checked' => $channel->last_health_checked_at !== null,
        ];
    }

    private function tenantId(McpAuthContext $auth): string
    {
        $tenantId = trim((string) $auth->tenantId);
        if ($tenantId === '') {
            throw new McpToolException('分发 MCP 工具必须绑定明确的租户');
        }

        return $tenantId;
    }

    /** @return array<string,mixed> */
    private function channel(DistributionChannel $channel): array
    {
        return [
            'id' => (int) $channel->id,
            'name' => (string) $channel->name,
            'domain' => (string) $channel->domain,
            'channel_type' => (string) $channel->channel_type,
            'status' => (string) $channel->status,
            'last_health_status' => $channel->last_health_status,
            'last_health_checked_at' => $channel->last_health_checked_at?->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string,mixed> */
    private function job(ArticleDistribution $job): array
    {
        return [
            'id' => (int) $job->id,
            'article' => $job->article ? [
                'id' => (int) $job->article->id,
                'title' => (string) $job->article->title,
                'slug' => (string) $job->article->slug,
            ] : null,
            'channel' => $job->channel ? [
                'id' => (int) $job->channel->id,
                'name' => (string) $job->channel->name,
                'domain' => (string) $job->channel->domain,
                'channel_type' => (string) $job->channel->channel_type,
            ] : null,
            'action' => (string) $job->action,
            'status' => (string) $job->status,
            'remote_id' => $job->remote_id,
            'remote_url' => $this->safeRemoteUrl($job->remote_url),
            'attempt_count' => (int) $job->attempt_count,
            'next_retry_at' => $job->next_retry_at?->format('Y-m-d H:i:s'),
            'last_attempt_at' => $job->last_attempt_at?->format('Y-m-d H:i:s'),
            'created_at' => $job->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $job->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function safeRemoteUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $safe = $scheme.'://'.$host;
        if (isset($parts['port'])) {
            $safe .= ':'.(int) $parts['port'];
        }
        $safe .= (string) ($parts['path'] ?? '');

        return mb_substr($safe, 0, 500);
    }
}
