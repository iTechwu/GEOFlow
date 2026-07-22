<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Services\Sso\SsoIdentityService;
use App\Services\Sso\SsoOidcClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SsoOidcHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_server_side_discovery_uses_internal_sso_address_but_authorize_url_stays_public(): void
    {
        config()->set([
            'sso.issuer' => 'https://sso-public.example/api',
            'sso.api_url' => 'https://sso-public.example/api',
            'sso.internal_api_url' => 'http://sso-internal.example/api',
            'sso.discovery_url' => 'https://sso-public.example/api/.well-known/openid-configuration',
            'sso.client_id' => 'geo-test',
        ]);
        Http::fake([
            'http://sso-internal.example/api/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://sso-public.example/api',
                'authorization_endpoint' => 'https://sso-public.example/api/oauth/authorize',
                'token_endpoint' => 'https://sso-public.example/api/oauth/token',
                'userinfo_endpoint' => 'https://sso-public.example/api/oauth/userinfo',
                'jwks_uri' => 'https://sso-public.example/api/.well-known/jwks.json',
            ]),
        ]);

        $response = $this->get('/auth/login');

        $response->assertRedirect();
        $this->assertStringStartsWith('https://sso-public.example/api/oauth/authorize?', $response->headers->get('Location'));
        Http::assertSent(fn ($request): bool => $request->url() === 'http://sso-internal.example/api/.well-known/openid-configuration');
    }

    public function test_expired_state_is_consumed_without_token_exchange(): void
    {
        Http::fake();

        $this->withSession([
            'sso.oidc' => [
                'state' => 'expired-state',
                'nonce' => 'nonce',
                'verifier' => 'verifier',
                'issuedAt' => now()->subMinutes(11)->timestamp,
            ],
        ])->get(route('sso.callback', ['state' => 'expired-state', 'code' => 'authorization-code']))
            ->assertRedirect(route('admin.login'));

        Http::assertNothingSent();
    }

    public function test_userinfo_cache_uses_token_hash_and_only_caches_successful_response(): void
    {
        Http::fake([
            'http://sso-internal.example/api/oauth/userinfo' => Http::response(['sub' => 'sso-user']),
        ]);
        config()->set([
            'sso.internal_api_url' => 'http://sso-internal.example/api',
            'sso.token_cache_seconds' => 15,
        ]);

        $client = app(SsoOidcClient::class);
        $this->assertSame('sso-user', $client->userInfoClaims('access-token')['sub']);
        $this->assertSame('sso-user', $client->userInfoClaims('access-token')['sub']);

        Http::assertSentCount(1);
    }

    public function test_sso_identity_uses_selected_team_id_for_ixicai_context(): void
    {
        $admin = app(SsoIdentityService::class)->synchronize([
            'sub' => 'team-context-user',
            'email' => 'team-context@example.com',
            'team_id' => 'sso-team-id',
            'scope' => 'catalog:read',
        ]);

        $this->assertInstanceOf(Admin::class, $admin);
        $this->assertSame('sso-team-id', $admin->sso_claims['selected_team_id']);
        $this->assertSame(['catalog:read'], $admin->sso_claims['scopes']);
    }
}
