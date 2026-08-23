<?php

namespace Tests\Feature;

use App\Http\McpAuthContext;
use App\Services\Mcp\McpSystemService;
use Tests\TestCase;

class McpSystemServiceTest extends TestCase
{
    public function test_status_returns_safe_runtime_and_migration_diagnostics(): void
    {
        config(['geoflow.mcp_enabled' => true]);

        $result = app(McpSystemService::class)->status($this->auth('team-a'));

        $this->assertSame('team-a', $result['tenant_id']);
        $this->assertTrue($result['mcp']['enabled']);
        $this->assertTrue($result['database']['reachable']);
        $this->assertArrayHasKey('pending_count', $result['migrations']);
        $this->assertArrayHasKey('gd', $result['extensions']);
        $this->assertArrayNotHasKey('host', $result['database']);
    }

    private function auth(?string $tenantId): McpAuthContext
    {
        return new McpAuthContext(McpAuthContext::SCOPE_READ, 'hash', $tenantId, null, ['catalog:read']);
    }
}
