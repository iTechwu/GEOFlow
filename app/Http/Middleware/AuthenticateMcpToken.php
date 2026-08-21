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
 *    tenantId 为 null，用于运维/CI 引导，不受租户隔离限制。
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

        $context = $this->resolveStaticToken($provided) ?? $this->resolveSsoToken($provided);
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
            return new McpAuthContext(McpAuthContext::SCOPE_WRITE, hash('sha256', $writeToken), null);
        }
        if ($readToken !== '' && hash_equals($readToken, $provided)) {
            return new McpAuthContext(McpAuthContext::SCOPE_READ, hash('sha256', $readToken), null);
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

        try {
            $admin = $this->identities->synchronize($claims);
        } catch (Throwable) {
            return null;
        }

        $teamId = $this->identities->selectedTeamId($claims);
        if ($teamId === null || $teamId === '') {
            // 无法确定租户：拒绝访问，避免无团队上下文的 SSO 令牌获得跨租户可见性。
            return null;
        }

        return new McpAuthContext(McpAuthContext::SCOPE_WRITE, hash('sha256', $provided), $teamId);
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
}
