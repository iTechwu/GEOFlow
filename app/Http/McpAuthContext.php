<?php

namespace App\Http;

/**
 * MCP 请求的鉴权上下文（由 {@see \App\Http\Middleware\AuthenticateMcpToken} 写入）。
 *
 * MCP 是无状态部署令牌，不属于任何 SSO 管理员，因此与 {@see ApiAuthContext} 分离：
 * 只携带作用域与令牌指纹，不携带 admin 归属。
 */
final class McpAuthContext
{
    public const SCOPE_READ = 'read';

    public const SCOPE_WRITE = 'write';

    /**
     * @param  'read'|'write'  $scope
     * @param  string  $tokenHash  提供令牌的 SHA-256 指纹（绝不落原始令牌）。
     * @param  string  $tenant  调用方租户标识（用于审计隔离，默认 'default'）。
     */
    public function __construct(
        public string $scope,
        public string $tokenHash,
        public string $tenant = 'default'
    ) {}
}
