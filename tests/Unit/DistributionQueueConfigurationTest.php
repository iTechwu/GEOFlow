<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DistributionQueueConfigurationTest extends TestCase
{
    public function test_docker_queue_workers_listen_to_all_application_queues(): void
    {
        $root = dirname(__DIR__, 2);
        $composeFiles = [
            $root.'/docker-compose.yml',
            $root.'/docker-compose.prod.yml',
            $root.'/docker-compose.prebuilt.yml',
        ];

        foreach ($composeFiles as $composeFile) {
            $contents = file_get_contents($composeFile);
            $this->assertIsString($contents);
            $this->assertStringContainsString('--queue=geoflow,distribution,theme-replication,system-updates,default', $contents, basename($composeFile));

            if (basename($composeFile) !== 'docker-compose.yml') {
                $this->assertStringContainsString('redis:system-updates', $contents, basename($composeFile));
            }
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

    public function test_redis_retry_after_exceeds_every_queue_job_timeout(): void
    {
        $root = dirname(__DIR__, 2);
        $queueConfig = file_get_contents($root.'/config/queue.php');
        $this->assertIsString($queueConfig);
        $this->assertSame(1, preg_match("/env\\('REDIS_QUEUE_RETRY_AFTER', (\\d+)\\)/", $queueConfig, $matches));
        $retryAfter = (int) $matches[1];
        $timeouts = [];

        foreach (glob($root.'/app/Jobs/*Job.php') ?: [] as $jobFile) {
            $contents = file_get_contents($jobFile);
            $this->assertIsString($contents);

            if (preg_match('/public int \$timeout = (\d+);/', $contents, $matches) !== 1) {
                continue;
            }

            $timeouts[basename($jobFile)] = (int) $matches[1];
        }

        $this->assertNotEmpty($timeouts);
        foreach ($timeouts as $job => $timeout) {
            $this->assertLessThan($retryAfter, $timeout, $job.' timeout must be lower than Redis retry_after.');
        }

        foreach (['.env.example', '.env.prod.example'] as $envFile) {
            $contents = file_get_contents($root.'/'.$envFile);
            $this->assertIsString($contents);
            $this->assertStringContainsString('REDIS_QUEUE_RETRY_AFTER='.$retryAfter, $contents, $envFile);
        }
    }

    public function test_compose_files_do_not_define_initialization_services(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['docker-compose.yml', 'docker-compose.prod.yml', 'docker-compose.prebuilt.yml'] as $composeFile) {
            $contents = file_get_contents($root.'/'.$composeFile);
            $this->assertIsString($contents);
            $this->assertDoesNotMatchRegularExpression(
                '/^  (?:init|db[-_]?init|migrat(?:e|ion)):/mi',
                $contents,
                $composeFile.' must leave database initialization to the external release workflow.'
            );
            $this->assertStringNotContainsString('geoflow-init', $contents, $composeFile);
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

    public function test_external_release_uses_first_install_command_instead_of_auto_seed(): void
    {
        $root = dirname(__DIR__, 2);
        $compose = file_get_contents($root.'/docker-compose.prod.yml');
        $entrypoint = file_get_contents($root.'/docker/entrypoint.prod.sh');
        $releaseScript = file_get_contents($root.'/deploy-scripts/deploy-prebuilt-release.sh');

        $this->assertIsString($compose);
        $this->assertIsString($entrypoint);
        $this->assertIsString($releaseScript);
        $this->assertStringContainsString('- ./.env.prod', $compose);
        $this->assertStringNotContainsString('AUTO_SEED', $compose);
        $this->assertStringNotContainsString('AUTO_SEED_CLASS:', $compose);
        $this->assertStringNotContainsString('php artisan db:seed', $entrypoint);
        $this->assertStringContainsString('app php artisan geoflow:install', $releaseScript);
    }

    public function test_external_release_preserves_the_operator_migration_gate(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['docker-compose.prod.yml', 'docker-compose.prebuilt.yml'] as $composeFile) {
            $contents = file_get_contents($root.'/'.$composeFile);
            $this->assertIsString($contents);
            $this->assertStringNotContainsString("\n  init:\n", $contents, $composeFile);
        }

        $entrypoint = file_get_contents($root.'/docker/entrypoint.prod.sh');
        $developmentEntrypoint = file_get_contents($root.'/docker/entrypoint.sh');
        $developmentCompose = file_get_contents($root.'/docker-compose.yml');
        $releaseScript = file_get_contents($root.'/deploy-scripts/deploy-prebuilt-release.sh');
        $this->assertIsString($entrypoint);
        $this->assertIsString($developmentEntrypoint);
        $this->assertIsString($developmentCompose);
        $this->assertIsString($releaseScript);
        $this->assertStringContainsString('${AUTO_MIGRATE:-false}', $entrypoint);
        $this->assertStringContainsString('${AUTO_MIGRATE:-false}', $developmentEntrypoint);
        $this->assertStringContainsString('x-runtime-db-guard: &runtime_db_guard', $developmentCompose);
        $this->assertSame(4, substr_count($developmentCompose, 'environment: *runtime_db_guard'));
        $this->assertStringContainsString('GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=true', $releaseScript);
        $this->assertStringContainsString('app php artisan migrate --force --no-interaction', $releaseScript);
    }

    public function test_production_runtime_services_never_run_install_or_migrations_on_startup(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['docker-compose.prod.yml', 'docker-compose.prebuilt.yml'] as $composeFile) {
            $contents = file_get_contents($root.'/'.$composeFile);
            $this->assertIsString($contents);
            $this->assertSame(4, substr_count($contents, 'AUTO_MIGRATE: "false"'), $composeFile);
            $this->assertSame(4, substr_count($contents, 'AUTO_INSTALL_ONCE: "false"'), $composeFile);
        }

        $productionEnv = file_get_contents($root.'/.env.prod.example');
        $this->assertIsString($productionEnv);
        $this->assertStringContainsString('AUTO_MIGRATE=false', $productionEnv);
        $this->assertStringContainsString('AUTO_INSTALL_ONCE=false', $productionEnv);
    }

    public function test_production_environment_file_is_read_only_and_entrypoint_never_rotates_the_app_key(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['docker-compose.prod.yml', 'docker-compose.prebuilt.yml'] as $composeFile) {
            $compose = file_get_contents($root.'/'.$composeFile);
            $this->assertIsString($compose);
            $this->assertSame(
                4,
                substr_count($compose, './.env.prod:/var/www/html/.env:ro'),
                $composeFile.' must mount the rendered environment read-only in every PHP service.'
            );
            $this->assertStringNotContainsString('./.env.prod:/var/www/html/.env\n', $compose, $composeFile);
        }

        $entrypoint = file_get_contents($root.'/docker/entrypoint.prod.sh');
        $this->assertIsString($entrypoint);
        $this->assertStringContainsString('APP_KEY must be provided before the production container starts', $entrypoint);
        $this->assertStringNotContainsString('key:generate', $entrypoint);
    }

    public function test_production_background_services_run_as_an_unprivileged_user(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['docker-compose.prod.yml', 'docker-compose.prebuilt.yml'] as $composeFile) {
            $compose = file_get_contents($root.'/'.$composeFile);
            $this->assertIsString($compose);

            foreach (['queue', 'scheduler', 'reverb'] as $service) {
                $serviceStart = strpos($compose, "\n  {$service}:\n");
                $this->assertNotFalse($serviceStart, $composeFile.' must define '.$service.'.');
                $nextService = preg_match(
                    '/\n  [a-z][a-z0-9_-]*:\n/',
                    $compose,
                    $matches,
                    PREG_OFFSET_CAPTURE,
                    (int) $serviceStart + strlen("\n  {$service}:\n")
                ) === 1 ? $matches[0][1] : false;
                $serviceBlock = substr(
                    $compose,
                    (int) $serviceStart,
                    $nextService === false ? null : $nextService - (int) $serviceStart
                );

                $this->assertStringContainsString('user: "www-data"', $serviceBlock, $composeFile.' '.$service);
                $this->assertStringContainsString('- no-new-privileges:true', $serviceBlock, $composeFile.' '.$service);
                $this->assertStringContainsString("cap_drop:\n      - ALL", $serviceBlock, $composeFile.' '.$service);
            }
        }

        $dockerfile = file_get_contents($root.'/docker/Dockerfile.prod');
        $this->assertIsString($dockerfile);
        $this->assertStringContainsString('ln -s /var/www/html/storage/app/public public/storage', $dockerfile);
        $this->assertStringContainsString('chown -R www-data:www-data storage bootstrap/cache', $dockerfile);
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
        $this->assertStringContainsString('secret="${secret:-$(runtime_env_value INTERNAL_API_SECRET)}"', $healthcheck);
        $this->assertStringContainsString('MODELS_INTERNAL_API_SECRET (or legacy INTERNAL_API_SECRET)', $healthcheck);
        $this->assertStringContainsString('"method":"initialize"', $healthcheck);
        $this->assertStringContainsString('"method":"tools/list"', $healthcheck);
        $this->assertStringContainsString('"name":"geoflow.catalog"', $healthcheck);
        $this->assertStringContainsString('runtime_env_value APP_URL', $healthcheck);
        $this->assertStringContainsString("printf 'Host: %s\\n'", $healthcheck);
        $this->assertStringNotContainsString('Authorization: Bearer $token', $healthcheck);
    }

    public function test_deployment_healthcheck_uses_the_running_container_configuration(): void
    {
        $healthcheck = file_get_contents(dirname(__DIR__, 2).'/deploy-scripts/geoflow-healthcheck.sh');

        $this->assertIsString($healthcheck);
        $this->assertStringContainsString('runtime_env_value()', $healthcheck);
        $this->assertStringContainsString("exec -T app sh -c 'printenv", $healthcheck);
        $this->assertStringContainsString('published_web_port()', $healthcheck);
        $this->assertStringContainsString('port web 80', $healthcheck);
        $this->assertStringContainsString('case "$enabled" in', $healthcheck);
        $this->assertStringNotContainsString('read_env_value()', $healthcheck);
        $this->assertStringNotContainsString('grep "^${key}="', $healthcheck);
    }

    public function test_prebuilt_release_healthcheck_uses_the_prebuilt_compose_runtime(): void
    {
        $root = dirname(__DIR__, 2);
        $prebuilt = file_get_contents($root.'/docker-compose.prebuilt.yml');
        $healthcheck = file_get_contents($root.'/deploy-scripts/geoflow-healthcheck.sh');
        $release = file_get_contents($root.'/deploy-scripts/deploy-prebuilt-release.sh');

        $this->assertIsString($prebuilt);
        $this->assertIsString($healthcheck);
        $this->assertIsString($release);
        $this->assertSame(4, substr_count($prebuilt, 'env_file:'), 'All PHP runtime services must receive the rendered environment.');
        $this->assertStringContainsString('GEOFLOW_COMPOSE_FILE', $healthcheck);
        $this->assertStringContainsString('GEOFLOW_COMPOSE_FILE=docker-compose.prebuilt.yml', $release);
    }

    public function test_production_runtime_services_define_and_enforce_healthchecks(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['docker-compose.prod.yml', 'docker-compose.prebuilt.yml'] as $composeFile) {
            $compose = file_get_contents($root.'/'.$composeFile);

            $this->assertIsString($compose);
            $this->assertStringContainsString("artisan queue:work'", $compose, $composeFile);
            $this->assertStringContainsString('queue:monitor redis:geoflow', $compose, $composeFile);
            $this->assertStringContainsString("artisan schedule:work'", $compose, $composeFile);
            $this->assertStringContainsString('php artisan schedule:list', $compose, $composeFile);
            $this->assertStringContainsString("artisan reverb:start'", $compose, $composeFile);
            $this->assertStringContainsString('curl -sS --max-time 2', $compose, $composeFile);
        }

        $healthcheck = file_get_contents($root.'/deploy-scripts/geoflow-healthcheck.sh');
        $this->assertIsString($healthcheck);
        $this->assertStringContainsString('.State.Health.Status', $healthcheck);
        $this->assertStringContainsString('Service healthy:', $healthcheck);
    }

    public function test_production_app_runs_php_fpm_and_checks_the_live_process(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['docker-compose.prod.yml', 'docker-compose.prebuilt.yml'] as $composeFile) {
            $compose = file_get_contents($root.'/'.$composeFile);

            $this->assertIsString($compose);
            $this->assertStringContainsString('command: ["php-fpm", "-F"]', $compose, $composeFile);
            $this->assertStringContainsString("grep -q 'php-fpm' && php-fpm -t", $compose, $composeFile);
        }

    }

    public function test_production_overlay_replaces_the_development_reverb_port_mapping(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 2).'/docker-compose.prod.yml');

        $this->assertIsString($compose);
        $this->assertStringContainsString("ports: !override\n      - \"127.0.0.1:\${REVERB_EXPOSE_PORT:-18081}:\${REVERB_SERVER_PORT:-18080}\"", $compose);
    }

    public function test_web_container_healthcheck_is_independent_from_laravel_maintenance_mode(): void
    {
        $root = dirname(__DIR__, 2);
        $nginx = file_get_contents($root.'/docker/nginx/default.conf');

        $this->assertIsString($nginx);
        $this->assertStringContainsString('location = /nginx-health', $nginx);
        $this->assertStringContainsString('return 200', $nginx);

        foreach (['docker-compose.prod.yml', 'docker-compose.prebuilt.yml'] as $composeFile) {
            $compose = file_get_contents($root.'/'.$composeFile);

            $this->assertIsString($compose);
            $this->assertStringContainsString('http://127.0.0.1/nginx-health', $compose, $composeFile);
        }
    }

    public function test_production_overlay_does_not_inherit_development_source_mounts_ports_or_dependencies(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 2).'/docker-compose.prod.yml');

        $this->assertIsString($compose);
        $this->assertSame(4, substr_count($compose, 'volumes: !override'), 'Every production PHP service must replace development bind mounts.');
        $this->assertStringContainsString('ports: !reset []', $compose);
        $this->assertSame(4, substr_count($compose, 'depends_on: !reset []'));
    }

    public function test_production_image_build_uses_ci_credentials_and_immutable_tags(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/deploy-scripts/build-and-push-amd64-images.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('--password-stdin', $script);
        $this->assertStringContainsString('REGISTRY_USERNAME', $script);
        $this->assertStringContainsString('REGISTRY_PASSWORD', $script);
        $this->assertStringContainsString('PUSH_LATEST="${PUSH_LATEST:-0}"', $script);
        $this->assertStringContainsString('prepare_docker_config()', $script);
        $this->assertStringContainsString('mktemp -d "${TMPDIR:-/tmp}/geoflow-build-docker-config.XXXXXX"', $script);
        $this->assertStringContainsString('trap cleanup_docker_config EXIT', $script);
        $this->assertStringContainsString('find "$DOCKER_CONFIG" -depth -delete', $script);
        $this->assertStringNotContainsString("--username='majin72'", $script);
    }

    public function test_production_image_verifies_a_pinned_composer_release(): void
    {
        $root = dirname(__DIR__, 2);
        $dockerfile = file_get_contents($root.'/docker/Dockerfile.prod');
        $compose = file_get_contents($root.'/docker-compose.prod.yml');
        $buildScript = file_get_contents($root.'/deploy-scripts/build-and-push-amd64-images.sh');

        $this->assertIsString($dockerfile);
        $this->assertIsString($compose);
        $this->assertIsString($buildScript);
        $this->assertStringContainsString('ARG COMPOSER_VERSION=2.10.2', $dockerfile);
        $this->assertStringContainsString('ARG COMPOSER_SHA256=5ee7125f8a30a34d246cefdc0bc85b8a783b28f2aec968994118512350d28027', $dockerfile);
        $this->assertStringContainsString('getcomposer.org/download/${COMPOSER_VERSION}/composer.phar', $dockerfile);
        $this->assertStringContainsString('curl --http1.1 -fsSL --retry 5 --retry-all-errors --connect-timeout 20 --max-time 90', $dockerfile);
        $this->assertStringContainsString('apt-get -o Acquire::Retries=5 update', $dockerfile);
        $this->assertStringContainsString('sha256sum -c -', $dockerfile);
        $this->assertStringContainsString('COMPOSER_VERSION: ${COMPOSER_VERSION:-2.10.2}', $compose);
        $this->assertStringContainsString('COMPOSER_SHA256: ${COMPOSER_SHA256:-5ee7125f8a30a34d246cefdc0bc85b8a783b28f2aec968994118512350d28027}', $compose);
        $this->assertStringContainsString('--build-arg COMPOSER_VERSION="${COMPOSER_VERSION}"', $buildScript);
        $this->assertStringContainsString('--build-arg COMPOSER_SHA256="${COMPOSER_SHA256}"', $buildScript);
        $this->assertStringContainsString('--build-arg COMPOSER_PACKAGIST_MIRROR="${COMPOSER_PACKAGIST_MIRROR}"', $buildScript);
        $this->assertStringContainsString('RUN composer --version', $dockerfile);
        $this->assertStringNotContainsString('ADD https://getcomposer.org', $dockerfile);
        $this->assertStringNotContainsString('getcomposer.org/download/latest-stable', $dockerfile);
    }

    public function test_production_build_sources_use_consistent_enterprise_registry_defaults(): void
    {
        $root = dirname(__DIR__, 2);
        $composerBase = 'uhub.service.ucloud.cn/techwu/php:8.4-cli-bookworm';
        $nginxBase = 'uhub.service.ucloud.cn/techwu/nginx:1.31.1-alpine';
        $files = [
            'docker/Dockerfile.prod',
            'docker/nginx/Dockerfile.prod',
            'docker-compose.prod.yml',
            '.env.prod.example',
            'deploy-scripts/build-and-push-amd64-images.sh',
            'deploy-scripts/pull-images-once-via-tunnel.sh',
            'deploy-scripts/sync-images-from-local.sh',
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($root.'/'.$file);
            $this->assertIsString($contents);
            $this->assertStringNotContainsString('composer:2.10.2', $contents, $file);
            $this->assertStringNotContainsString('nginx:1.27-alpine', $contents, $file);
        }

        foreach (['docker/Dockerfile.prod', 'docker-compose.prod.yml', '.env.prod.example', 'deploy-scripts/build-and-push-amd64-images.sh', 'deploy-scripts/pull-images-once-via-tunnel.sh', 'deploy-scripts/sync-images-from-local.sh'] as $file) {
            $contents = file_get_contents($root.'/'.$file);
            $this->assertIsString($contents);
            $this->assertStringContainsString($composerBase, $contents, $file);
        }

        foreach (['docker/nginx/Dockerfile.prod', 'docker-compose.prod.yml', '.env.prod.example', 'deploy-scripts/build-and-push-amd64-images.sh', 'deploy-scripts/pull-images-once-via-tunnel.sh', 'deploy-scripts/sync-images-from-local.sh'] as $file) {
            $contents = file_get_contents($root.'/'.$file);
            $this->assertIsString($contents);
            $this->assertStringContainsString($nginxBase, $contents, $file);
        }

        foreach (['docker/Dockerfile.prod', 'docker-compose.prod.yml', '.env.prod.example', 'deploy-scripts/build-and-push-amd64-images.sh'] as $file) {
            $contents = file_get_contents($root.'/'.$file);
            $this->assertIsString($contents);
            $this->assertStringContainsString('https://mirrors.aliyun.com/composer', $contents, $file);
        }
    }

    public function test_production_image_verifies_the_pinned_pecl_redis_archive(): void
    {
        $root = dirname(__DIR__, 2);
        $dockerfile = file_get_contents($root.'/docker/Dockerfile.prod');
        $compose = file_get_contents($root.'/docker-compose.prod.yml');
        $buildScript = file_get_contents($root.'/deploy-scripts/build-and-push-amd64-images.sh');
        $productionEnv = file_get_contents($root.'/.env.prod.example');
        $sha256 = '0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5';

        foreach ([$dockerfile, $compose, $buildScript, $productionEnv] as $contents) {
            $this->assertIsString($contents);
            $this->assertStringContainsString($sha256, $contents);
        }
        $this->assertStringContainsString('ARG PECL_REDIS_SHA256=', $dockerfile);
        $this->assertStringContainsString('curl --http1.1 -fsSL --retry 5 --retry-all-errors --connect-timeout 20 --max-time 120', $dockerfile);
        $this->assertStringContainsString('echo "${PECL_REDIS_SHA256}  /tmp/redis.tgz" | sha256sum -c -', $dockerfile);
        $this->assertStringContainsString('--build-arg PECL_REDIS_SHA256="${PECL_REDIS_SHA256}"', $buildScript);
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
        $this->assertStringContainsString('app php artisan migrate --force --no-interaction', $script);
        $this->assertStringContainsString('app php artisan geoflow:install --no-interaction', $script);
        $this->assertStringContainsString('remove_legacy_init_container', $script);
        $this->assertStringContainsString('Legacy init container is still running', $script);
        $this->assertStringContainsString('docker rm "$container"', $script);
        $this->assertStringNotContainsString('docker rm -f "$container"', $script);
        $this->assertStringContainsString('up -d --no-deps app queue scheduler reverb web', $script);
        $this->assertStringContainsString('geoflow:managed-images:readiness', $script);
        $this->assertStringContainsString('geoflow:security-audit', $script);
        $this->assertStringContainsString('geoflow-healthcheck.sh', $script);
        $this->assertStringContainsString('GEOFLOW_HEALTHCHECK_MAINTENANCE_SECRET="$MAINTENANCE_SECRET"', $script);
        $this->assertSame(
            2,
            substr_count($script, 'php artisan down --secret="$MAINTENANCE_SECRET" --no-interaction --quiet'),
            'Maintenance bypass secrets must never be printed by Artisan into CI logs.',
        );
        $healthcheckPosition = strpos($script, 'bash deploy-scripts/geoflow-healthcheck.sh');
        $leaveMaintenancePosition = strrpos($script, 'php artisan up --no-interaction');
        $this->assertNotFalse($healthcheckPosition);
        $this->assertNotFalse($leaveMaintenancePosition);
        $this->assertGreaterThan($healthcheckPosition, $leaveMaintenancePosition, 'Maintenance mode must remain active until all release gates pass.');
        $this->assertStringNotContainsString('down -v', $script);
    }

    public function test_prebuilt_release_seals_the_maintenance_bypass_on_every_failed_exit_path(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/deploy-scripts/deploy-prebuilt-release.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('seal_maintenance_mode()', $script);
        $this->assertStringContainsString('trap on_exit EXIT', $script);
        $this->assertStringContainsString("trap 'exit 130' INT", $script);
        $this->assertStringContainsString("trap 'exit 143' TERM", $script);
        $this->assertStringContainsString('app php artisan down --no-interaction --quiet', $script);
        $this->assertStringContainsString('-e AUTO_MIGRATE=false', $script);
        $this->assertStringContainsString('-e AUTO_INSTALL_ONCE=false', $script);
        $this->assertStringContainsString('MAINTENANCE_SECRET=""', $script);
        $this->assertStringNotContainsString("trap 'on_error ", $script);
    }

    public function test_prebuilt_release_validates_compose_resolved_image_references(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/deploy-scripts/deploy-prebuilt-release.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('validate_resolved_images()', $script);
        $this->assertStringContainsString('config --images', $script);
        $this->assertStringContainsString('validate_resolved_images "$RESOLVED_IMAGES"', $script);
        $this->assertStringContainsString('validate_pulled_image_revisions "$RESOLVED_IMAGES"', $script);
        $revisionGatePosition = strpos($script, 'validate_pulled_image_revisions "$RESOLVED_IMAGES"');
        $maintenanceSecretPosition = strpos($script, 'MAINTENANCE_SECRET="$(openssl rand -hex 32)"');
        $this->assertNotFalse($revisionGatePosition);
        $this->assertNotFalse($maintenanceSecretPosition);
        $this->assertLessThan($maintenanceSecretPosition, $revisionGatePosition);
        $this->assertStringNotContainsString('read_env_value()', $script);
        $this->assertStringNotContainsString('grep "^${key}="', $script);
    }

    public function test_mcp_healthcheck_can_use_an_ephemeral_maintenance_cookie(): void
    {
        $healthcheck = file_get_contents(dirname(__DIR__, 2).'/deploy-scripts/geoflow-healthcheck.sh');

        $this->assertIsString($healthcheck);
        $this->assertStringContainsString('GEOFLOW_HEALTHCHECK_MAINTENANCE_SECRET', $healthcheck);
        $this->assertStringContainsString('Cookie: laravel_maintenance=%s', $healthcheck);
        $this->assertStringContainsString('stream_get_contents(STDIN)', $healthcheck);
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

    public function test_production_ingress_ports_are_bound_to_loopback(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['docker-compose.prod.yml', 'docker-compose.prebuilt.yml'] as $composeFile) {
            $compose = file_get_contents($root.'/'.$composeFile);
            $this->assertIsString($compose);
            $this->assertStringContainsString('127.0.0.1:${WEB_PORT:-18080}:80', $compose, $composeFile);
            $this->assertStringContainsString('127.0.0.1:${REVERB_EXPOSE_PORT:-18081}:${REVERB_SERVER_PORT:-18080}', $compose, $composeFile);
        }
    }

    public function test_nginx_passes_the_outer_proxy_client_ip_and_prefix_to_laravel(): void
    {
        $nginx = file_get_contents(dirname(__DIR__, 2).'/docker/nginx/default.conf');

        $this->assertIsString($nginx);
        $this->assertStringContainsString('fastcgi_param HTTP_X_FORWARDED_FOR $http_x_forwarded_for;', $nginx);
        $this->assertStringContainsString('fastcgi_param HTTP_X_FORWARDED_PREFIX $http_x_forwarded_prefix;', $nginx);
    }
}
