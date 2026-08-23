<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class NodeRuntimeContractTest extends TestCase
{
    public function test_ci_compose_and_production_builds_use_node_24(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [
            '.github/workflows/ci.yml',
            '.env.example',
            'docker-compose.yml',
            'docker/Dockerfile.prod',
            'docker/nginx/Dockerfile.prod',
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($root.'/'.$file);
            $this->assertIsString($contents, $file);
            $this->assertStringNotContainsString('node:25', $contents, $file);
        }

        $workflow = file_get_contents($root.'/.github/workflows/ci.yml');
        $this->assertStringContainsString("node-version: '24'", $workflow);

        foreach (array_slice($files, 1) as $file) {
            $contents = file_get_contents($root.'/'.$file);
            $this->assertStringContainsString('node:24-bookworm-slim', $contents, $file);
        }
    }
}
