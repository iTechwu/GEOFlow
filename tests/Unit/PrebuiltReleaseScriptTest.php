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

    public function test_pulled_release_images_must_share_a_non_empty_oci_revision(): void
    {
        $images = implode("\n", [
            'registry.example/geoflow-app-prod@sha256:'.str_repeat('a', 64),
            'registry.example/geoflow-web-prod@sha256:'.str_repeat('b', 64),
        ]);

        $matching = $this->validateRevisions($images, 'abc123', 'abc123');
        $this->assertTrue($matching->isSuccessful(), $matching->getErrorOutput());

        $mismatched = $this->validateRevisions($images, 'abc123', 'def456');
        $this->assertFalse($mismatched->isSuccessful());
        $this->assertStringContainsString('same OCI revision', $mismatched->getErrorOutput());

        $missing = $this->validateRevisions($images, '', 'abc123');
        $this->assertFalse($missing->isSuccessful());
        $this->assertStringContainsString('OCI revision label', $missing->getErrorOutput());
    }

    public function test_both_production_images_are_built_with_the_oci_revision_label(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/deploy-scripts/build-and-push-amd64-images.sh');

        $this->assertIsString($script);
        $this->assertSame(2, substr_count($script, '--label "org.opencontainers.image.revision=${VERSION}"'));
    }

    public function test_stopped_legacy_init_container_is_removed(): void
    {
        $result = $this->removeLegacyInitContainer('false');

        $this->assertTrue($result->isSuccessful(), $result->getErrorOutput());
        $this->assertStringContainsString('removed:geoflow-init-prod', $result->getErrorOutput());
    }

    public function test_running_legacy_init_container_blocks_release_without_removal(): void
    {
        $result = $this->removeLegacyInitContainer('true');

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('Legacy init container is still running', $result->getErrorOutput());
        $this->assertStringNotContainsString('removed:', $result->getErrorOutput());
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

    private function validateRevisions(string $images, string $appRevision, string $webRevision): Process
    {
        $script = dirname(__DIR__, 2).'/deploy-scripts/deploy-prebuilt-release.sh';
        $process = new Process([
            'bash',
            '-c',
            <<<'BASH'
source "$1"
app_revision="$3"
web_revision="$4"
image_revision() {
  case "$1" in
    *geoflow-app-prod*) printf '%s\n' "$app_revision" ;;
    *geoflow-web-prod*) printf '%s\n' "$web_revision" ;;
    *) return 1 ;;
  esac
}
validate_pulled_image_revisions "$2"
BASH,
            'prebuilt-release-test',
            $script,
            $images,
            $appRevision,
            $webRevision,
        ]);
        $process->run();

        return $process;
    }

    private function removeLegacyInitContainer(string $running): Process
    {
        $script = dirname(__DIR__, 2).'/deploy-scripts/deploy-prebuilt-release.sh';
        $process = new Process([
            'bash',
            '-c',
            <<<'BASH'
source "$1"
docker() {
  if [ "$1" = "inspect" ] && [ "${2:-}" = "--format" ]; then
    printf '%s\n' "$LEGACY_RUNNING"
    return 0
  fi
  if [ "$1" = "inspect" ]; then
    return 0
  fi
  if [ "$1" = "rm" ]; then
    printf 'removed:%s\n' "$2" >&2
    return 0
  fi
  return 1
}
remove_legacy_init_container
BASH,
            'prebuilt-release-test',
            $script,
        ], null, ['LEGACY_RUNNING' => $running]);
        $process->run();

        return $process;
    }
}
