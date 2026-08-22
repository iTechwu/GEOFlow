<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PrebuiltReleaseScriptTest extends TestCase
{
    public function test_resolved_release_images_must_share_one_version_tag(): void
    {
        $matching = $this->validateImages(implode("\n", [
            'registry.example/geoflow-app-prod:abc123',
            'registry.example/geoflow-web-prod:abc123',
        ]));
        $this->assertTrue($matching->isSuccessful(), $matching->getErrorOutput());

        $mismatched = $this->validateImages(implode("\n", [
            'registry.example/geoflow-app-prod:abc123',
            'registry.example/geoflow-web-prod:def456',
        ]));
        $this->assertFalse($mismatched->isSuccessful());
        $this->assertStringContainsString('same version tag', $mismatched->getErrorOutput());
    }

    public function test_resolved_release_images_still_reject_latest(): void
    {
        $result = $this->validateImages(implode("\n", [
            'registry.example/geoflow-app-prod:latest',
            'registry.example/geoflow-web-prod:latest',
        ]));

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('not latest', $result->getErrorOutput());
    }

    private function validateImages(string $images): Process
    {
        $script = dirname(__DIR__, 2).'/deploy-scripts/deploy-prebuilt-release.sh';
        $process = new Process([
            'bash',
            '-c',
            'source "$1"; validate_resolved_images "$2"',
            'prebuilt-release-test',
            $script,
            $images,
        ]);
        $process->run();

        return $process;
    }
}
