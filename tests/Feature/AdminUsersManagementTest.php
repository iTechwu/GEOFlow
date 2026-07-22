<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUsersManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_admin_user_management_route_is_not_available(): void
    {
        $this->get('/geo_admin/admin-users')->assertNotFound();
    }
}
