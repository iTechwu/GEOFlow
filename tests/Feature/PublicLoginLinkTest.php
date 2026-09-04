<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicLoginLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_home_exposes_the_sso_login_entry(): void
    {
        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('href="'.route('sso.login').'"', false)
            ->assertSee(__('front.nav.login'));
    }
}
