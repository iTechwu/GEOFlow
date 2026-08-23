<?php

namespace App\Http;

/**
 * MCP 请求的鉴权上下文（由 {@see \App\Http\Middleware\AuthenticateMcpToken} 写入）。
 *
 * 两种凭证来源：
 * - 部署令牌（GEOFLOW_MCP_TOKEN / GEOFLOW_MCP_READ_TOKEN）：系统级、跨租户，
 *   tenantId 为 null，用于运维/CI 引导；审计时记录为 system。
 * - SSO 访问令牌：复用 REST API 的 SSO 鉴权，tenantId 取 SSO 的 selected_team_id，
 *   实现“以 SSO 为准”的同实例多租户隔离。
 */
final class McpAuthContext
{
    public const SCOPE_READ = 'read';

    public const SCOPE_WRITE = 'write';

    /**
     * @param  'read'|'write'  $scope
     * @param  string  $tokenHash  提供令牌的 SHA-256 指纹（绝不落原始令牌）。
     * @param  string|null  $tenantId  SSO selected_team_id；null 表示系统/跨租户令牌。
     * @param  int|null  $auditAdminId  用于文章风险/审核审计的管理员 ID。
     * @param  list<string>  $scopes  MCP 工具能力；`*` 表示全部能力。
     */
    public function __construct(
        public string $scope,
        public string $tokenHash,
        public ?string $tenantId = null,
        public ?int $auditAdminId = null,
        public array $scopes = [],
    ) {}

    public function allows(string $requiredScope): bool
    {
        return in_array('*', $this->scopes, true) || in_array($requiredScope, $this->scopes, true);
    }
}
