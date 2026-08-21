<?php

namespace Tests\Feature;

use Tests\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    public function test_admin_login_redirect_respects_forwarded_prefix_from_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => '*']);
        config(['session.driver' => 'array']);

        $loginPath = '/'.ltrim((string) app('router')->getRoutes()->getByName('admin.login')?->uri(), '/');
        $expectedSsoLoginUrl = 'https://geo.example.com/docs/auth/login';

        $this->get($loginPath, [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'geo.example.com',
            'HTTP_X_FORWARDED_PREFIX' => '/docs',
        ])
            ->assertRedirect($expectedSsoLoginUrl);
    }
}
