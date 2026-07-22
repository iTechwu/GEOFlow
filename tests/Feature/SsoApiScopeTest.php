<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SsoApiScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function ($request) {
            if (str_contains(implode(' ', $request->header('Authorization')), 'unscoped-sso-token')) {
                return Http::response([
                    'sub' => 'unscoped-sso-user',
                    'email' => 'unscoped-sso@example.com',
                    'name' => 'Unscoped SSO User',
                ]);
            }
            if (str_contains(implode(' ', $request->header('Authorization')), 'super-admin-sso-token')) {
                return Http::response([
                    'sub' => 'super-admin-sso-user',
                    'email' => 'super-admin-sso@example.com',
                    'name' => 'Super Admin SSO User',
                    'role' => 'super_admin',
                ]);
            }

            return Http::response([
                'sub' => 'scoped-sso-user',
                'email' => 'scoped-sso@example.com',
                'name' => 'Scoped SSO User',
                'scope' => 'catalog:read',
            ]);
        });
    }

    public function test_sso_scope_claim_limits_api_access(): void
    {
        $this->withHeader('Authorization', 'Bearer scoped-sso-token')
            ->getJson('/api/v1/catalog')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer scoped-sso-token')
            ->getJson('/api/v1/materials')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden')
            ->assertJsonPath('error.details.required_scope', 'materials:read');
    }

    public function test_sso_token_without_api_scope_is_denied_by_default(): void
    {
        $this->withHeader('Authorization', 'Bearer unscoped-sso-token')
            ->getJson('/api/v1/catalog')
            ->assertForbidden()
            ->assertJsonPath('error.details.required_scope', 'catalog:read');
    }

    public function test_singular_sso_super_admin_role_grants_api_access(): void
    {
        $this->withHeader('Authorization', 'Bearer super-admin-sso-token')
            ->getJson('/api/v1/catalog')
            ->assertOk();
    }
}
