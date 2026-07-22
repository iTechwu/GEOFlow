<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Sso\SsoOidcClient;
use App\Support\AdminActivityLogger;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * geo.dofe 后台会话由 SSO OIDC 建立；本地账号密码登录已停用。
 */
class AdminAuthController extends Controller
{
    public function showLoginForm(Request $request): RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        return redirect()->route('sso.login');
    }

    public function logout(Request $request, SsoOidcClient $oidc): RedirectResponse
    {
        /** @var Admin|null $admin */
        $admin = Auth::guard('admin')->user();
        if ($admin instanceof Admin) {
            AdminActivityLogger::logFromRequest($request, $admin, 'auth:logout', [
                'username' => (string) $admin->username,
            ]);
        }

        $idToken = $request->session()->get('sso.id_token');
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->away($oidc->logoutUrl(is_string($idToken) ? $idToken : null));
    }

    public function switchLocale(Request $request, string $locale): RedirectResponse
    {
        if (! AdminWeb::isSupportedLocale($locale)) {
            $locale = 'zh_CN';
        }
        $request->session()->put('locale', $locale);
        app()->setLocale($locale);

        return redirect()->back();
    }

}
