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

            // A missing SSO team must not prevent the administrator from
            // establishing a GeoFlow session. Key provisioning can retry on
            // a later sign-in after the user selects a team in SSO.
            try {
                $keys->ensure($admin, $result['access_token']);
            } catch (Throwable $exception) {
                report($exception);
            }

            Auth::guard('admin')->login($admin, false);
            $request->session()->regenerate();
            $request->session()->put('sso.sub', $admin->sso_sub);
            $request->session()->put('sso.access_token', $result['access_token']);
            $request->session()->put('sso.id_token', $result['id_token']);
            $request->session()->put('sso.expires_at', now()->addSeconds($result['expires_in'])->timestamp);
            return redirect()->intended(route('admin.dashboard'));
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('site.home')->withErrors(['sso' => 'SSO sign-in could not be completed.']);
        }
    }
}
