<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DistributionQueueConfigurationTest extends TestCase
{
    public function test_docker_queue_workers_listen_to_distribution_queue(): void
    {
        $root = dirname(__DIR__, 2);
        $composeFiles = [
            $root.'/docker-compose.yml',
            $root.'/docker-compose.prod.yml',
        ];

        foreach ($composeFiles as $composeFile) {
            $contents = file_get_contents($composeFile);
            $this->assertIsString($contents);
            $this->assertStringContainsString('--queue=geoflow,distribution,theme-replication,default', $contents, basename($composeFile));
        }
    }

    public function test_horizon_supervisor_listens_to_distribution_queue(): void
    {
        $horizon = require dirname(__DIR__, 2).'/config/horizon.php';

        $this->assertSame(
            ['geoflow', 'distribution'],
            $horizon['defaults']['supervisor-1']['queue'] ?? null
        );
    }

    public function test_compose_init_services_scope_the_fresh_install_confirmation(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['docker-compose.yml', 'docker-compose.prod.yml', 'docker-compose.prebuilt.yml'] as $composeFile) {
            $contents = file_get_contents($root.'/'.$composeFile);
            $this->assertIsString($contents);
            $this->assertStringContainsString(
                'GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED: "true"',
                $contents,
                $composeFile.' must scope fresh-install intent to its one-shot init service.'
            );
        }
    }

    public function test_documented_production_compose_commands_use_env_file(): void
    {
        $root = dirname(__DIR__, 2);
        $docs = array_merge(
            [$root.'/README.md', $root.'/docs/deployment/DEPLOYMENT.md'],
            glob($root.'/docs/readme/README_*.md') ?: []
        );

        foreach ($docs as $doc) {
            $contents = file_get_contents($doc);
            $this->assertIsString($contents);

            foreach (preg_split('/\R/', $contents) ?: [] as $lineNumber => $line) {
                if (! str_contains($line, 'docker compose') || ! str_contains($line, 'docker-compose.prod.yml')) {
                    continue;
                }

                $this->assertStringContainsString(
                    '--env-file .env.prod',
                    $line,
                    sprintf('%s:%d production compose command must load .env.prod', basename($doc), $lineNumber + 1)
                );
            }
        }
    }

    public function test_production_init_uses_first_install_command_instead_of_auto_seed(): void
    {
        $root = dirname(__DIR__, 2);
        $compose = file_get_contents($root.'/docker-compose.prod.yml');
        $entrypoint = file_get_contents($root.'/docker/entrypoint.prod.sh');

        $this->assertIsString($compose);
        $this->assertIsString($entrypoint);
        $this->assertStringContainsString('- ./.env.prod', $compose);
        $this->assertStringNotContainsString('AUTO_SEED', $compose);
        $this->assertStringNotContainsString('AUTO_SEED_CLASS:', $compose);
        $this->assertStringNotContainsString('php artisan db:seed', $entrypoint);
        $this->assertStringContainsString('php artisan geoflow:install', $entrypoint);
    }

    public function test_production_init_services_preserve_the_operator_migration_gate(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['docker-compose.prod.yml', 'docker-compose.prebuilt.yml'] as $composeFile) {
            $contents = file_get_contents($root.'/'.$composeFile);
            $this->assertIsString($contents);
            $initStart = strpos($contents, "\n  init:\n");
            $appStart = strpos($contents, "\n  app:\n", $initStart === false ? 0 : $initStart);
            $this->assertNotFalse($initStart, $composeFile.' must define an init service.');
            $this->assertNotFalse($appStart, $composeFile.' must define an app service after init.');
            $initBlock = substr($contents, (int) $initStart, (int) $appStart - (int) $initStart);

            $this->assertStringNotContainsString(
                'AUTO_MIGRATE: "true"',
                $initBlock,
                $composeFile.' must not override the operator-controlled migration gate.'
            );
        }

        $entrypoint = file_get_contents($root.'/docker/entrypoint.prod.sh');
        $this->assertIsString($entrypoint);
        $this->assertStringContainsString('${AUTO_MIGRATE:-false}', $entrypoint);
    }

    public function test_deployment_healthcheck_rejects_pending_migrations(): void
    {
        $healthcheck = file_get_contents(dirname(__DIR__, 2).'/deploy-scripts/geoflow-healthcheck.sh');

        $this->assertIsString($healthcheck);
        $this->assertStringContainsString(
            'php artisan migrate:status --pending=1 --no-interaction',
            $healthcheck
        );
        $this->assertStringContainsString(
            'fail "Laravel cannot read migration status or still has pending migrations.',
            $healthcheck
        );
    }

    public function test_deployment_healthcheck_is_a_strict_models_and_mcp_release_gate(): void
    {
        $healthcheck = file_get_contents(dirname(__DIR__, 2).'/deploy-scripts/geoflow-healthcheck.sh');

        $this->assertIsString($healthcheck);
        $this->assertStringContainsString('fail "Required services did not become ready:', $healthcheck);
        $this->assertStringContainsString('fail "HTTP health endpoint failed:', $healthcheck);
        $this->assertStringContainsString('php artisan geoflow:models-internal-check --no-interaction', $healthcheck);
        $this->assertStringContainsString('php artisan geoflow:models-gateway-check --no-interaction', $healthcheck);
        $this->assertStringContainsString('GEOFLOW_HEALTHCHECK_REQUIRE_MODELS_INTERNAL:-1', $healthcheck);
        $this->assertStringContainsString('"method":"initialize"', $healthcheck);
        $this->assertStringContainsString('"method":"tools/list"', $healthcheck);
        $this->assertStringContainsString('"name":"geoflow.catalog"', $healthcheck);
        $this->assertStringNotContainsString('Authorization: Bearer $token', $healthcheck);
    }

    public function test_production_image_build_uses_ci_credentials_and_immutable_tags(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/deploy-scripts/build-and-push-amd64-images.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('--password-stdin', $script);
        $this->assertStringContainsString('REGISTRY_USERNAME', $script);
        $this->assertStringContainsString('REGISTRY_PASSWORD', $script);
        $this->assertStringContainsString('PUSH_LATEST="${PUSH_LATEST:-0}"', $script);
        $this->assertStringNotContainsString("--username='majin72'", $script);
    }

    public function test_production_image_uses_the_composer_binary_from_its_supplied_image(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2).'/docker/Dockerfile.prod');

        $this->assertIsString($dockerfile);
        $this->assertStringContainsString('RUN composer --version', $dockerfile);
        $this->assertStringNotContainsString('getcomposer.org/download/latest-stable', $dockerfile);
    }

    public function test_prebuilt_release_script_enforces_stopped_and_drained_upgrade_gates(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/deploy-scripts/deploy-prebuilt-release.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('fail "Release images must use immutable', $script);
        $this->assertStringContainsString('php artisan down --no-interaction', $script);
        $this->assertStringContainsString('stop -t "$DRAIN_TIMEOUT" web queue scheduler reverb', $script);
        $this->assertStringContainsString('GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=true', $script);
        $this->assertStringContainsString('GEOFLOW_MANAGED_IMAGE_DELETION_ENABLED=false', $script);
        $this->assertStringContainsString('GEOFLOW_RELEASE_MODE:-upgrade', $script);
        $this->assertStringContainsString('fresh|upgrade', $script);
        $this->assertStringContainsString('GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true', $script);
        $this->assertStringContainsString('run --rm --no-deps', $script);
        $this->assertStringContainsString('up -d --no-deps app queue scheduler reverb web', $script);
        $this->assertStringContainsString('geoflow:managed-images:readiness', $script);
        $this->assertStringContainsString('geoflow:security-audit', $script);
        $this->assertStringContainsString('geoflow-healthcheck.sh', $script);
        $this->assertStringNotContainsString('down -v', $script);
    }

    public function test_ci_environment_renderer_requires_external_services_models_and_sso(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/deploy-scripts/render-prod-env.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('chmod 600 "$WORK_FILE"', $script);
        $this->assertStringContainsString('DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD', $script);
        $this->assertStringContainsString('REDIS_HOST REDIS_PASSWORD', $script);
        $this->assertStringContainsString('MODELS_BASE_URL MODELS_API_KEY', $script);
        $this->assertStringContainsString('MODELS_INTERNAL_BASE_URL MODELS_INTERNAL_API_SECRET', $script);
        $this->assertStringContainsString('SSO_API_URL SSO_ISSUER SSO_CLIENT_ID SSO_CLIENT_SECRET SSO_REDIRECT_URI', $script);
        $this->assertStringContainsString('GEOFLOW_APP_IMAGE GEOFLOW_WEB_IMAGE', $script);
        $this->assertStringContainsString('WORK_FILE="$(mktemp', $script);
        $this->assertStringContainsString('mv "$WORK_FILE" "$TARGET"', $script);
        $this->assertStringNotContainsString('echo "$value"', $script);
    }

    public function test_deployment_paths_preserve_local_models_gateway_security_controls(): void
    {
        $root = dirname(__DIR__, 2);
        $keys = [
            'GEOFLOW_MODELS_ALLOW_INSECURE_LOCAL',
            'GEOFLOW_OUTBOUND_PRIVATE_TARGETS',
        ];
        $files = [
            '.env.prod.example',
            '.github/workflows/ci.yml',
            'deploy-scripts/render-prod-env.sh',
            'deploy-scripts/geoflow-docker-deploy.sh',
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($root.'/'.$file);
            $this->assertIsString($contents);

            foreach ($keys as $key) {
                $this->assertStringContainsString($key, $contents, $file.' must preserve '.$key);
            }
        }
    }

    public function test_interactive_deployment_removes_redis_url_that_overrides_external_host(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/deploy-scripts/geoflow-docker-deploy.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('unset_env_value .env.prod REDIS_URL', $script);
        $this->assertStringContainsString('set_env_value .env.prod REDIS_HOST "$redis_host"', $script);
    }

    public function test_production_image_makes_application_sources_readable_by_php_fpm(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2).'/docker/Dockerfile.prod');

        $this->assertIsString($dockerfile);
        $this->assertStringContainsString('find app config database resources routes -type d -exec chmod 755 {} +', $dockerfile);
        $this->assertStringContainsString('find app config database resources routes -type f -exec chmod 644 {} +', $dockerfile);
    }

    public function test_nginx_preserves_external_host_port_for_laravel_asset_urls(): void
    {
        $nginx = file_get_contents(dirname(__DIR__, 2).'/docker/nginx/default.conf');

        $this->assertIsString($nginx);
        $this->assertStringContainsString('~:(?<geoflow_host_port>[0-9]+)$ $geoflow_host_port;', $nginx);
        $this->assertStringContainsString('"" $http_host;', $nginx);
        $this->assertStringContainsString('"" $geoflow_request_port;', $nginx);
        $this->assertStringContainsString('proxy_set_header X-Forwarded-Host $geoflow_forwarded_host;', $nginx);
        $this->assertStringContainsString('proxy_set_header X-Forwarded-Port $geoflow_forwarded_port;', $nginx);
    }
}
