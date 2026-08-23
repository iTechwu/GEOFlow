<?php

namespace Tests\Feature;

use App\Http\McpAuthContext;
use App\Services\Mcp\McpFrontendService;
use App\Support\Site\SiteThemeCatalog;
use Tests\TestCase;

class McpFrontendServiceTest extends TestCase
{
    public function test_capabilities_returns_the_theme_and_homepage_contract_without_write_operations(): void
    {
        $result = app(McpFrontendService::class)->capabilities($this->auth('team-a'));

        $this->assertSame('team-a', $result['tenant_id']);
        $this->assertContains('hero', $result['homepage']['module_types']);
        $this->assertContains('content_portal', $result['homepage']['presets']);
        $this->assertSame(SiteThemeCatalog::class, get_class(app(SiteThemeCatalog::class)));
        $this->assertIsArray($result['themes']);
    }

    private function auth(?string $tenantId): McpAuthContext
    {
        return new McpAuthContext(McpAuthContext::SCOPE_READ, 'hash', $tenantId, null, ['catalog:read']);
    }
}
