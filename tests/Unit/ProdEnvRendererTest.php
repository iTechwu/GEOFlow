<?php

namespace Tests\Unit;

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ProdEnvRendererTest extends TestCase
{
    public function test_renderer_preserves_secret_metacharacters_for_laravel_dotenv(): void
    {
        $values = $this->requiredEnvironment();
        $values['DB_PASSWORD'] = 'hash# dollar$ space backslash\\ double"';

        [$process, $directory] = $this->render($values);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $parsed = Dotenv::createArrayBacked($directory, '.env.prod')->load();
        $this->assertSame($values['DB_PASSWORD'], $parsed['DB_PASSWORD']);
        $this->assertSame($values['MODELS_API_KEY'], $parsed['MODELS_API_KEY']);
        $this->assertSame(0600, fileperms($directory.'/.env.prod') & 0777);
    }

    public function test_renderer_rejects_single_quotes_without_printing_the_value(): void
    {
        $values = $this->requiredEnvironment();
        $values['DB_PASSWORD'] = "private'password";

        [$process] = $this->render($values);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('DB_PASSWORD', $process->getErrorOutput());
        $this->assertStringNotContainsString($values['DB_PASSWORD'], $process->getErrorOutput());
    }

    public function test_renderer_allows_read_only_mcp_deployment_without_system_token(): void
    {
        $values = $this->requiredEnvironment();
        $values['GEOFLOW_MCP_ENABLED'] = 'true';
        $values['GEOFLOW_MCP_ALLOW_SYSTEM_TOKEN'] = 'false';
        $values['GEOFLOW_MCP_READ_TOKEN'] = 'read-only-token';

        [$process, $directory] = $this->render($values);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $parsed = Dotenv::createArrayBacked($directory, '.env.prod')->load();
        $this->assertSame('false', $parsed['GEOFLOW_MCP_ALLOW_SYSTEM_TOKEN']);
        $this->assertSame('read-only-token', $parsed['GEOFLOW_MCP_READ_TOKEN']);
    }

    public function test_renderer_rejects_enabled_mcp_without_any_usable_token(): void
    {
        $values = $this->requiredEnvironment();
        $values['GEOFLOW_MCP_ENABLED'] = 'true';
        $values['GEOFLOW_MCP_ALLOW_SYSTEM_TOKEN'] = 'false';

        [$process] = $this->render($values);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('GEOFLOW_MCP_READ_TOKEN', $process->getErrorOutput());
    }

    /** @param array<string, string> $environment */
    private function render(array $environment): array
    {
        $directory = sys_get_temp_dir().'/geoflow-env-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        touch($directory.'/template.env');

        $environment['GEOFLOW_ENV_TEMPLATE'] = $directory.'/template.env';
        $process = new Process([
            'bash',
            dirname(__DIR__, 2).'/deploy-scripts/render-prod-env.sh',
            $directory.'/.env.prod',
        ], null, $environment);
        $process->run();

        return [$process, $directory];
    }

    /** @return array<string, string> */
    private function requiredEnvironment(): array
    {
        return [
            'APP_KEY' => 'base64:test-key',
            'APP_URL' => 'https://geo.example.test',
            'DB_HOST' => 'postgres.shared',
            'DB_DATABASE' => 'geoflow',
            'DB_USERNAME' => 'geoflow',
            'DB_PASSWORD' => 'database-secret',
            'REDIS_HOST' => 'redis.shared',
            'REDIS_PASSWORD' => 'redis-secret',
            'MODELS_BASE_URL' => 'https://models.dofe.ai/v1',
            'MODELS_API_KEY' => 'models# key$ value',
            'MODELS_CHAT_SMOKE_MODEL' => 'chat-model',
            'MODELS_EMBEDDING_SMOKE_MODEL' => 'embedding-model',
            'MODELS_INTERNAL_BASE_URL' => 'https://models.dofe.ai',
            'MODELS_INTERNAL_API_SECRET' => 'internal-secret',
            'SSO_API_URL' => 'https://sso.example.test/api',
            'SSO_ISSUER' => 'https://sso.example.test/api',
            'SSO_CLIENT_ID' => 'geoflow',
            'SSO_CLIENT_SECRET' => 'sso-secret',
            'SSO_REDIRECT_URI' => 'https://geo.example.test/auth/callback',
            'REVERB_APP_SECRET' => 'reverb-secret',
            'GEOFLOW_APP_IMAGE' => 'registry.example/geoflow-app:abc123',
            'GEOFLOW_WEB_IMAGE' => 'registry.example/geoflow-web:abc123',
            'DOCKER_COMMON_NETWORK_NAME' => 'common_network',
            'GEOFLOW_MCP_ENABLED' => 'false',
        ];
    }
}
