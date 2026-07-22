<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Ixicai\IxicaiApiKeyProvisioner;
use App\Services\Sso\SsoIdentityService;
use App\Services\Sso\SsoOidcClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class SsoAuthController extends Controller
{
    public function login(Request $request, SsoOidcClient $oidc): RedirectResponse
    {
        try {
            return redirect()->away($oidc->begin($request));
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('site.home')
                ->withErrors(['sso' => 'SSO is currently unavailable. Please try again shortly.']);
        }
    }

    public function callback(Request $request, SsoOidcClient $oidc, SsoIdentityService $identities, IxicaiApiKeyProvisioner $keys): RedirectResponse
    {
        try {
            $result = $oidc->complete($request);
            $admin = $identities->synchronize($result['claims']);
            $keys->ensure($admin, $result['access_token']);
            Auth::guard('admin')->login($admin, false);
            $request->session()->regenerate();
            $request->session()->put('sso.sub', $admin->sso_sub);
            $request->session()->put('sso.expires_at', now()->addSeconds($result['expires_in'])->timestamp);
            return redirect()->intended(route('admin.dashboard'));
        } catch (Throwable $exception) {
            report($exception);
            return redirect()->route('admin.login')->withErrors(['sso' => 'SSO sign-in could not be completed.']);
        }
    }
}
