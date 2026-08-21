<?php

namespace App\Http\Middleware;

use App\Http\McpAuthContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protects the stateless MCP endpoint with deployment-scoped bearer tokens.
 * SSO remains the authentication boundary for the browser and REST API.
 *
 * Token model:
 * - GEOFLOW_MCP_TOKEN (mcp_token)      -> write scope (read + write tools).
 * - GEOFLOW_MCP_READ_TOKEN (optional)  -> read scope (catalog / tasks.list / tasks.get only).
 * When the read token is not configured, the write token is the only accepted credential.
 */
final class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('geoflow.mcp_enabled', false)) {
            abort(404);
        }

        $provided = $this->bearerToken($request);

        $writeToken = trim((string) config('geoflow.mcp_token', ''));
        $readToken = trim((string) config('geoflow.mcp_read_token', ''));

        $context = null;
        if ($writeToken !== '' && $provided !== '' && hash_equals($writeToken, $provided)) {
            $context = new McpAuthContext(McpAuthContext::SCOPE_WRITE, hash('sha256', $writeToken));
        } elseif ($readToken !== '' && $provided !== '' && hash_equals($readToken, $provided)) {
            $context = new McpAuthContext(McpAuthContext::SCOPE_READ, hash('sha256', $readToken));
        }

        if ($context === null) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32001, 'message' => 'Unauthorized'],
                'id' => null,
            ], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        $request->attributes->set('mcp_auth', $context);

        return $next($request);
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
