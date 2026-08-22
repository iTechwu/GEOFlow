<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DockerBuildContextTest extends TestCase
{
    public function test_production_build_context_excludes_secrets_and_runtime_data(): void
    {
        $dockerignore = file_get_contents(dirname(__DIR__, 2).'/.dockerignore');
        $this->assertIsString($dockerignore);
        $patterns = array_values(array_filter(array_map(
            'trim',
            preg_split('/\R/', $dockerignore) ?: [],
        ), static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')));

        foreach ([
            '.env',
            '.env.*',
            'public/uploads',
            'public/assets/images',
            'public/build',
            'public/hot',
            'public/storage',
            'storage/app/public/*',
            'storage/app/private/*',
            'storage/framework/sessions/*',
            'storage/logs/*',
            '**/.DS_Store',
        ] as $requiredPattern) {
            $this->assertContains($requiredPattern, $patterns, $requiredPattern);
        }
    }
}
