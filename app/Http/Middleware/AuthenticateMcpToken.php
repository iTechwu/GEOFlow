<?php

namespace App\Http\Middleware;

use App\Http\McpAuthContext;
use App\Services\Sso\SsoIdentityService;
use App\Services\Sso\SsoOidcClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Protects the stateless MCP endpoint. Two credential models are accepted:
 *
 * 1. Deployment bearer tokens (system / cross-tenant):
 *    - GEOFLOW_MCP_TOKEN      -> write scope.
 *    - GEOFLOW_MCP_READ_TOKEN -> read scope (optional).
 *    tenantId 默认为空（跨租户），也可通过 GEOFLOW_MCP_DEFAULT_TENANT 固定到单一 team。
 *
 * 2. SSO access token (tenant-scoped, "以 SSO 为准"):
 *    - Resolved through {@see SsoOidcClient::userInfoClaims()} and synced via
 *      {@see SsoIdentityService}, mirroring the REST API auth boundary.
 *    - tenantId 取 SSO 的 selected_team_id；缺失团队上下文时拒绝访问（无法确定租户）。
 */
final class AuthenticateMcpToken
{
    public function __construct(
        private readonly SsoOidcClient $oidc,
        private readonly SsoIdentityService $identities
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('geoflow.mcp_enabled', false)) {
            abort(404);
        }

        $provided = $this->bearerToken($request);
        if ($provided === '') {
            return $this->unauthorized();
        }

        $context = null;
        if ((bool) config('geoflow.mcp_allow_system_token', true)) {
            $context = $this->resolveStaticToken($provided);
        }
        if ($context === null) {
            $context = $this->resolveSsoToken($provided);
        }

        if ($context === null) {
            return $this->unauthorized();
        }

        $request->attributes->set('mcp_auth', $context);

        return $next($request);
    }

    private function resolveStaticToken(string $provided): ?McpAuthContext
    {
        $writeToken = trim((string) config('geoflow.mcp_token', ''));
        $readToken = trim((string) config('geoflow.mcp_read_token', ''));

        if ($writeToken !== '' && hash_equals($writeToken, $provided)) {
            $auditAdminId = (int) config('geoflow.mcp_audit_admin_id', 0);
            $defaultTenant = trim((string) config('geoflow.mcp_default_tenant', ''));
            if ($defaultTenant === '' && ! (bool) config('geoflow.mcp_allow_cross_tenant', false)) {
                return null;
            }

            return new McpAuthContext(
                McpAuthContext::SCOPE_WRITE,
                hash('sha256', $writeToken),
                $defaultTenant !== '' ? $defaultTenant : null,
                $auditAdminId > 0 ? $auditAdminId : null,
                ['*'],
            );
        }
        if ($readToken !== '' && hash_equals($readToken, $provided)) {
            $defaultTenant = trim((string) config('geoflow.mcp_default_tenant', ''));
            if ($defaultTenant === '' && ! (bool) config('geoflow.mcp_allow_cross_tenant', false)) {
                return null;
            }

            return new McpAuthContext(
                McpAuthContext::SCOPE_READ,
                hash('sha256', $readToken),
                $defaultTenant !== '' ? $defaultTenant : null,
                null,
                ['catalog:read', 'tasks:read', 'articles:read', 'materials:read', 'jobs:read', 'analytics:read', 'distribution:read'],
            );
        }

        return null;
    }

    private function resolveSsoToken(string $provided): ?McpAuthContext
    {
        try {
            $claims = $this->oidc->userInfoClaims($provided);
        } catch (Throwable) {
            return null;
        }

        $teamId = $this->identities->selectedTeamId($claims);
        if ($teamId === null || $teamId === '') {
            // 无法确定租户：拒绝访问，避免无团队上下文的 SSO 令牌获得跨租户可见性。
            return null;
        }

        try {
            $admin = $this->identities->synchronize($claims);
        } catch (Throwable) {
            return null;
        }

        return new McpAuthContext(
            McpAuthContext::SCOPE_WRITE,
            hash('sha256', $provided),
            $teamId,
            (int) $admin->getKey(),
            $this->scopesFromClaims($claims),
        );
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32001, 'message' => 'Unauthorized'],
            'id' => null,
        ], 401, ['WWW-Authenticate' => 'Bearer']);
    }

    private function bearerToken(Request $request): string
    {
        $authorization = (string) $request->header('Authorization', '');
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) !== 1) {
            return '';
        }

        return trim((string) $matches[1]);
    }

    /** @param array<string,mixed> $claims
     * @return list<string>
     */
    private function scopesFromClaims(array $claims): array
    {
        $roles = $claims['roles'] ?? $claims['role'] ?? [];
        $roles = is_string($roles) ? preg_split('/\s+/', trim($roles)) : (array) $roles;
        $roles = array_map(static fn (mixed $role): string => trim(strtolower((string) $role)), $roles);
        if (in_array('super_admin', $roles, true) || in_array('superadmin', $roles, true) || ($claims['isAdmin'] ?? false) === true) {
            return ['*'];
        }

        $raw = $claims['scopes'] ?? $claims['scope'] ?? $claims['permissions'] ?? [];
        $scopes = is_string($raw) ? preg_split('/\s+/', trim($raw)) : (array) $raw;

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $scope): string => trim((string) $scope), $scopes),
            static fn (string $scope): bool => $scope !== '',
        )));
    }
}
