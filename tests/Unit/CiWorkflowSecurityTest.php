<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CiWorkflowSecurityTest extends TestCase
{
    public function test_every_third_party_action_is_pinned_to_a_full_commit(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/ci.yml');
        $this->assertIsString($workflow);
        preg_match_all('/^\s*uses:\s*([^\s#]+)(?:\s+#.*)?$/m', $workflow, $matches);

        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $action) {
            $this->assertMatchesRegularExpression(
                '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+@[a-f0-9]{40}$/',
                $action,
                $action,
            );
        }
    }

    public function test_checkout_does_not_persist_the_github_token_for_repository_scripts(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/ci.yml');
        $this->assertIsString($workflow);

        $checkoutCount = preg_match_all('/uses:\s*actions\/checkout@[a-f0-9]{40}/', $workflow);
        $disabledPersistenceCount = substr_count($workflow, 'persist-credentials: false');

        $this->assertSame(3, $checkoutCount);
        $this->assertSame($checkoutCount, $disabledPersistenceCount);
    }

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

    public function test_self_hosted_deployment_uses_and_cleans_an_ephemeral_docker_config(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/ci.yml');
        $this->assertIsString($workflow);

        $start = strpos($workflow, '  deploy-production:');
        $this->assertNotFalse($start);
        $deploymentJob = substr($workflow, $start);

        $this->assertStringContainsString('mktemp -d "${RUNNER_TEMP}/geoflow-docker-config.XXXXXX"', $deploymentJob);
        $this->assertStringContainsString('echo "DOCKER_CONFIG=${docker_config}" >> "$GITHUB_ENV"', $deploymentJob);
        $this->assertStringContainsString('chmod 700 "$docker_config"', $deploymentJob);
        $this->assertStringContainsString('if: always()', $deploymentJob);
        $this->assertStringContainsString('docker logout "$REGISTRY"', $deploymentJob);
        $this->assertStringContainsString('find "$DOCKER_CONFIG" -depth -delete', $deploymentJob);
        $this->assertStringContainsString('"$RUNNER_TEMP"/geoflow-docker-config.*', $deploymentJob);
    }
}
