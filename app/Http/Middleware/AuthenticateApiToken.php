<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Services\Sso\SsoIdentityService;
use Closure;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function __construct(private readonly HttpFactory $http, private readonly SsoIdentityService $identities) {}

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

        $response = $this->http->acceptJson()->withToken($tokenValue)->connectTimeout(5)->timeout(10)->retry(2, 200, throw: false)
            ->get(rtrim((string) config('sso.api_url'), '/').'/oauth/userinfo');
        $claims = $response->json();
        if (! $response->successful() || ! is_array($claims) || ! is_string($claims['sub'] ?? null)) {
            throw new ApiException('unauthorized', 'SSO token is invalid or expired.', 401);
        }
        $admin = $this->identities->synchronize($claims);
        $request->attributes->set('api_auth', new ApiAuthContext([
            'id' => 'sso:'.$admin->sso_sub,
            'scopes' => ['*'],
            'status' => 'active',
        ], (int) $admin->id));

        return $next($request);
    }
}
