<?php

namespace Tests\Feature;

use Tests\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    public function test_admin_login_redirect_respects_forwarded_prefix_from_trusted_proxy(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');
        config(['trustedproxy.proxies' => '*']);
        config(['app.url' => 'https://geo.example.com']);
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

    public function test_forwarded_host_cannot_override_the_configured_application_host(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');
        config(['trustedproxy.proxies' => '*']);
        config(['app.url' => 'https://geo.example.com']);

        $loginPath = '/'.ltrim((string) app('router')->getRoutes()->getByName('admin.login')?->uri(), '/');

        $this->get($loginPath, [
            'HTTP_HOST' => 'geo.example.com',
            'HTTP_X_FORWARDED_HOST' => 'attacker.example',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '443',
        ])->assertBadRequest();
    }

    public function test_health_endpoint_remains_available_to_container_local_checks(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');
        config(['trustedproxy.proxies' => '*']);
        config(['app.url' => 'https://geo.example.com']);

        $this->get('/up', ['HTTP_HOST' => '127.0.0.1'])
            ->assertOk();
    }
}
