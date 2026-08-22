<?php

namespace Tests\Feature;

use App\Services\Models\ModelsGatewayClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModelsGatewayClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('geoflow.models_base_url', 'https://models.dofe.ai/v1');
        config()->set('geoflow.models_api_key', 'public-key');
        config()->set('geoflow.models_chat_smoke_model', 'chat-smoke');
        config()->set('geoflow.models_embedding_smoke_model', 'embedding-smoke');
        Http::preventStrayRequests();
    }

    public function test_check_calls_chat_and_embedding_through_models(): void
    {
        Http::fake([
            'https://models.dofe.ai/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
            'https://models.dofe.ai/v1/embeddings' => Http::response(['data' => [['embedding' => [0.1, 0.2]]]]),
        ]);

        ModelsGatewayClient::check();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://models.dofe.ai/v1/chat/completions'
            && $request['model'] === 'chat-smoke'
            && $request['max_tokens'] === 1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://models.dofe.ai/v1/embeddings'
            && $request['model'] === 'embedding-smoke');
    }

    public function test_check_fails_when_smoke_models_are_not_configured(): void
    {
        config()->set('geoflow.models_embedding_smoke_model', '');

        $this->expectExceptionMessage('MODELS_EMBEDDING_SMOKE_MODEL');
        ModelsGatewayClient::check();
    }

    public function test_check_lists_all_missing_deployment_inputs_without_values(): void
    {
        config()->set('geoflow.models_base_url', '');
        config()->set('geoflow.models_api_key', '');
        config()->set('geoflow.models_chat_smoke_model', '');
        config()->set('geoflow.models_embedding_smoke_model', '');

        try {
            ModelsGatewayClient::check();
            $this->fail('Expected the models gateway check to reject missing configuration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('MODELS_BASE_URL', $exception->getMessage());
            $this->assertStringContainsString('MODELS_API_KEY', $exception->getMessage());
            $this->assertStringContainsString('MODELS_CHAT_SMOKE_MODEL', $exception->getMessage());
            $this->assertStringContainsString('MODELS_EMBEDDING_SMOKE_MODEL', $exception->getMessage());
            $this->assertStringNotContainsString('public-key', $exception->getMessage());
        }
    }

    public function test_check_rejects_an_insecure_gateway_before_sending_the_api_key(): void
    {
        config()->set('geoflow.models_base_url', 'http://models.example.test/v1');
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        try {
            ModelsGatewayClient::check();
            $this->fail('Expected the models gateway check to require HTTPS.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('MODELS_BASE_URL', $exception->getMessage());
            $this->assertStringContainsString('HTTPS', $exception->getMessage());
            $this->assertStringNotContainsString('public-key', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_check_rejects_an_unapproved_https_host_before_sending_the_api_key(): void
    {
        config()->set('geoflow.models_base_url', 'https://attacker.example.test/v1');
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        try {
            ModelsGatewayClient::check();
            $this->fail('Expected the models gateway check to reject an unapproved HTTPS host.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('MODELS_BASE_URL', $exception->getMessage());
            $this->assertStringContainsString('models.dofe.ai', $exception->getMessage());
            $this->assertStringNotContainsString('public-key', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_check_allows_explicit_local_http_smoke_endpoint(): void
    {
        config()->set('geoflow.models_base_url', 'http://dofe-models-api-local:3101/v1');
        config()->set('geoflow.models_allow_insecure_local', true);
        config()->set('geoflow.outbound_private_targets', ['dofe-models-api-local:3101']);
        Http::fake([
            'http://dofe-models-api-local:3101/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'ok']]],
            ]),
            'http://dofe-models-api-local:3101/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.1]]],
            ]),
        ]);

        ModelsGatewayClient::check();

        Http::assertSentCount(2);
    }

    public function test_check_rejects_chat_response_without_non_empty_content(): void
    {
        Http::fake([
            'https://models.dofe.ai/v1/chat/completions' => Http::response(['choices' => [[]]]),
            'https://models.dofe.ai/v1/embeddings' => Http::response(['data' => [['embedding' => [0.1, 0.2]]]]),
        ]);

        $this->expectExceptionMessage('models Chat 探针返回格式无效');

        ModelsGatewayClient::check();
    }

    public function test_check_rejects_embedding_response_with_non_numeric_values(): void
    {
        Http::fake([
            'https://models.dofe.ai/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
            'https://models.dofe.ai/v1/embeddings' => Http::response(['data' => [['embedding' => [0.1, 'not-a-number']]]]),
        ]);

        $this->expectExceptionMessage('models Embedding 探针返回格式无效');

        ModelsGatewayClient::check();
    }

    public function test_gateway_check_command_does_not_print_upstream_error_bodies(): void
    {
        Http::fake([
            'https://models.dofe.ai/v1/chat/completions' => Http::response([
                'error' => 'secret-provider-token',
            ], 400),
        ]);

        $exitCode = Artisan::call('geoflow:models-gateway-check', ['--no-interaction' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('HTTP 400', $output);
        $this->assertStringNotContainsString('secret-provider-token', $output);
        $this->assertStringNotContainsString('public-key', $output);
        $this->assertStringNotContainsString('models.dofe.ai', $output);
        Http::assertSentCount(1);
    }

    public function test_check_retries_a_transient_upstream_failure(): void
    {
        Http::fakeSequence()
            ->push(['error' => 'temporarily unavailable'], 503)
            ->push(['choices' => [['message' => ['content' => 'ok']]]], 200)
            ->push(['data' => [['embedding' => [0.1, 0.2]]]], 200);

        ModelsGatewayClient::check();

        Http::assertSentCount(3);
    }
}
