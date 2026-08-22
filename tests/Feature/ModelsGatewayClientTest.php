<?php

namespace Tests\Feature;

use App\Services\Models\ModelsGatewayClient;
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
}
