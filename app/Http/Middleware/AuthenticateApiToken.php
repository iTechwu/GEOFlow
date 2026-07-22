<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Services\Sso\SsoIdentityService;
use App\Services\Sso\SsoOidcClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function __construct(private readonly SsoOidcClient $oidc, private readonly SsoIdentityService $identities) {}

    public function handle(Request $request, Closure $next): Response
    {
        $authorization = $request->header('Authorization');
        if (! is_string($authorization) || $authorization === '') {
            throw new ApiException('unauthorized', '缺少 Authorization 头', 401);
        }

        if (! preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            throw new ApiException('unauthorized', 'Authorization 格式无效', 401);
        }

        $tokenValue = trim($matches[1]);
        if ($tokenValue === '') {
            throw new ApiException('unauthorized', 'Token 不能为空', 401);
        }

        try {
            $claims = $this->oidc->userInfoClaims($tokenValue);
        } catch (\Throwable) {
            throw new ApiException('unauthorized', 'SSO token is invalid or expired.', 401);
        }
        $admin = $this->identities->synchronize($claims);
        $request->attributes->set('api_auth', new ApiAuthContext([
            'id' => 'sso:'.$admin->sso_sub,
            'scopes' => $this->scopesFromClaims($claims),
            'status' => 'active',
        ], (int) $admin->id));

        return $next($request);
    }

    /** @param array<string, mixed> $claims
     *  @return list<string>
     */
    private function scopesFromClaims(array $claims): array
    {
        $rawRoles = $claims['roles'] ?? $claims['role'] ?? [];
        $roles = is_string($rawRoles) ? preg_split('/\s+/', trim($rawRoles)) : (array) $rawRoles;
        $roles = array_map(static fn (mixed $role): string => trim(strtolower((string) $role)), $roles);
        if (in_array('super_admin', $roles, true) || in_array('superadmin', $roles, true)) {
            return ['*'];
        }
        if (($claims['isAdmin'] ?? false) === true) {
            return ['*'];
        }

        $raw = $claims['scopes'] ?? $claims['scope'] ?? $claims['permissions'] ?? [];
        $scopes = is_string($raw) ? preg_split('/\s+/', trim($raw)) : (array) $raw;
        $normalized = array_values(array_unique(array_filter(
            array_map(static fn (mixed $scope): string => trim((string) $scope), $scopes),
            static fn (string $scope): bool => $scope !== ''
        )));

        return $normalized;
    }
}
