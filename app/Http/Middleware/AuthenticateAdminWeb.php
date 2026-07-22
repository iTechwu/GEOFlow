<?php

namespace App\Http\Middleware;

use App\Services\Sso\SsoIdentityService;
use App\Services\Sso\SsoOidcClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 后台会话鉴权中间件：未登录时跳转 admin.login，避免默认 login 路由缺失导致 500。
 */
class AuthenticateAdminWeb
{
    public function __construct(
        private readonly SsoOidcClient $oidc,
        private readonly SsoIdentityService $identities,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 仅检查 admin guard，保持与 geo_admin 后台会话体系一致。
        if (! Auth::guard('admin')->check()) {
            return redirect()->route('admin.login');
        }

        $admin = Auth::guard('admin')->user();
        if (! app()->environment('testing') && (
            ! $admin instanceof \App\Models\Admin
            || $admin->sso_sub === null
            || ! hash_equals($admin->sso_sub, (string) $request->session()->get('sso.sub', ''))
        )) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();

            return redirect()->route('admin.login');
        }

        if (! app()->environment('testing')) {
            $accessToken = $request->session()->get('sso.access_token');
            try {
                $claims = $this->oidc->userInfoClaims(is_string($accessToken) ? $accessToken : '');
                $refreshed = $this->identities->synchronize($claims);
                if ($refreshed->id !== $admin->id || ! hash_equals((string) $refreshed->sso_sub, (string) $request->session()->get('sso.sub'))) {
                    throw new \RuntimeException('SSO session identity changed.');
                }
                Auth::guard('admin')->setUser($refreshed);
            } catch (\Throwable) {
                Auth::guard('admin')->logout();
                $request->session()->invalidate();

                return redirect()->route('admin.login');
            }
        }

        $expiresAt = (int) $request->session()->get('sso.expires_at', 0);
        if ($expiresAt !== 0 && $expiresAt < now()->timestamp) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            return redirect()->route('admin.login');
        }

        return $next($request);
    }
}
