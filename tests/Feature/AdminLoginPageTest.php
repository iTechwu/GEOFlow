<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_redirects_to_the_sso_login_flow(): void
    {
        $this->get(route('admin.login'))
            ->assertRedirect(route('sso.login'));
    }

    public function test_local_password_login_route_is_not_available(): void
    {
        $this->post('/geo_admin/login', [
            'username' => 'admin',
            'password' => 'password',
        ])->assertMethodNotAllowed();
    }
}
