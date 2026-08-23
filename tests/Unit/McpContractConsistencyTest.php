<?php

namespace Tests\Unit;

use App\Http\Controllers\McpController;
use App\Http\McpAuthContext;
use App\Services\Mcp\McpToolException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class McpContractConsistencyTest extends TestCase
{
    public function test_enterprise_knowledge_publish_uses_materials_write_scope(): void
    {
        $reflection = new ReflectionClass(McpController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $authorizeMethod = $reflection->getMethod('authorizeTool');

        $authorizeMethod->invoke($controller, new McpAuthContext(McpAuthContext::SCOPE_WRITE, 'test', 'team-a', null, ['materials:write']), 'geoflow.enterprise_knowledge.publish');

        $this->expectException(McpToolException::class);
        $authorizeMethod->invoke($controller, new McpAuthContext(McpAuthContext::SCOPE_WRITE, 'test', 'team-a', null, ['articles:publish']), 'geoflow.enterprise_knowledge.publish');
    }

    public function test_every_advertised_tool_has_a_unique_object_schema_and_authorization_mapping(): void
    {
        $reflection = new ReflectionClass(McpController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $toolsMethod = $reflection->getMethod('tools');
        $authorizeMethod = $reflection->getMethod('authorizeTool');
        $tools = $toolsMethod->invoke($controller);
        $names = [];
        $auth = new McpAuthContext(McpAuthContext::SCOPE_READ, 'test', 'team-a', null, []);

        foreach ($tools as $tool) {
            $name = (string) ($tool['name'] ?? '');
            $this->assertNotSame('', $name);
            $this->assertNotContains($name, $names, 'Duplicate MCP tool name: '.$name);
            $names[] = $name;
            $schema = $tool['inputSchema'] ?? null;
            $this->assertIsArray($schema, $name);
            $this->assertSame('object', $schema['type'] ?? null, $name);
            $this->assertFalse($schema['additionalProperties'] ?? true, $name);

            try {
                $authorizeMethod->invoke($controller, $auth, $name);
                $this->fail('Tool has no authorization mapping: '.$name);
            } catch (McpToolException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertCount(count($tools), $names);
        $this->assertGreaterThanOrEqual(30, count($tools));
    }
}
