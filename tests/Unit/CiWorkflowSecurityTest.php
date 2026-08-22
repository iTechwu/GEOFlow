<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CiWorkflowSecurityTest extends TestCase
{
    public function test_production_image_builds_are_restricted_to_main(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/ci.yml');
        $this->assertIsString($workflow);

        $start = strpos($workflow, '  build-images:');
        $end = strpos($workflow, '  deploy-production:', $start ?: 0);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $buildJob = substr($workflow, $start, $end - $start);
        $this->assertStringContainsString("github.ref == 'refs/heads/main'", $buildJob);
        $this->assertSame(1, substr_count($buildJob, "github.ref == 'refs/heads/main'"));
        $this->assertMatchesRegularExpression(
            "/github\.ref == 'refs\/heads\/main'\s*&&\s*\(\s*github\.event_name == 'push'\s*\|\|\s*github\.event_name == 'workflow_dispatch'\s*\)/",
            $buildJob,
        );
    }
}
