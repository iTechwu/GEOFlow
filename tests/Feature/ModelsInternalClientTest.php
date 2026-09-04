<?php

namespace Tests\Feature;

use App\Contracts\Outbound\OutboundTransport;
use App\Services\Models\ModelsInternalClient;
use App\Services\Outbound\OutboundRequestFailedException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ModelsInternalClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('geoflow.models_internal_base_url', 'https://models.dofe.ai');
        config()->set('geoflow.models_internal_api_secret', 'test-secret');
        config()->set('geoflow.models_service_name', 'geoflow');
        Http::preventStrayRequests();
    }

    public function test_list_models_sends_hmac_authorization_and_service_name_header(): void
    {
        Http::fake(['*' => Http::response(['list' => [], 'total' => 0], 200)]);

        $result = ModelsInternalClient::listModels();

        $this->assertSame(['list' => [], 'total' => 0], $result);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://models.dofe.ai/internal/models') {
                return false;
            }

            $headers = array_change_key_case($request->headers(), CASE_LOWER);
            $authorization = $headers['authorization'][0] ?? '';
            $serviceName = $headers['x-service-name'][0] ?? '';

            if ($serviceName !== 'geoflow' || ! str_starts_with($authorization, 'Bearer ')) {
                return false;
            }

            $parts = explode(':', substr($authorization, 7));
            if (count($parts) !== 3) {
                return false;
            }

            [$timestamp, $signature, $boundService] = $parts;
            if ($boundService !== 'geoflow') {
                return false;
            }

            return hash_equals(hash_hmac('sha256', $timestamp.':geoflow', 'test-secret'), $signature);
        });
    }

    public function test_client_throws_when_not_configured(): void
    {
        config()->set('geoflow.models_internal_api_secret', '');

        $this->expectException(RuntimeException::class);

        ModelsInternalClient::listModels();
    }

    public function test_client_rejects_http_before_sending_the_hmac_token(): void
    {
        config()->set('geoflow.models_internal_base_url', 'http://models.example.test');
        Http::fake(['*' => Http::response(['list' => [], 'total' => 0])]);

        $this->expectExceptionMessage('MODELS_INTERNAL_BASE_URL');

        try {
            ModelsInternalClient::listModels();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_client_rejects_an_unapproved_https_host_before_sending_the_hmac_token(): void
    {
        config()->set('geoflow.models_internal_base_url', 'https://attacker.example.test');
        Http::fake(['*' => Http::response(['list' => [], 'total' => 0])]);

        try {
            ModelsInternalClient::listModels();
            $this->fail('Expected the models internal client to reject an unapproved HTTPS host.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('MODELS_INTERNAL_BASE_URL', $exception->getMessage());
            $this->assertStringContainsString('ixicai.cn', $exception->getMessage());
            $this->assertStringNotContainsString('test-secret', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_client_allows_explicit_local_http_with_the_private_target_allowlist(): void
    {
        config()->set('geoflow.models_internal_base_url', 'http://127.0.0.1:3101');
        config()->set('geoflow.models_allow_insecure_local', true);
        config()->set('geoflow.outbound_private_targets', ['127.0.0.1:3101']);
        Http::fake([
            'http://127.0.0.1:3101/internal/models' => Http::response(['list' => [], 'total' => 0]),
        ]);

        $this->assertSame(['list' => [], 'total' => 0], ModelsInternalClient::listModels());
        Http::assertSentCount(1);
    }

    public function test_internal_check_command_does_not_print_upstream_error_bodies(): void
    {
        Http::fake([
            'https://models.dofe.ai/internal/models' => Http::response(['error' => 'secret-provider-detail'], 500),
        ]);

        $exitCode = Artisan::call('geoflow:models-internal-check', ['--no-interaction' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('HTTP 500', $output);
        $this->assertStringNotContainsString('secret-provider-detail', $output);
        $this->assertStringNotContainsString('test-secret', $output);
        $this->assertStringNotContainsString('models.dofe.ai', $output);
    }

    public function test_internal_check_command_logs_unknown_transport_failures_without_sensitive_details(): void
    {
        $transport = Mockery::mock(OutboundTransport::class);
        $transport->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('secret-provider-token at https://models.dofe.ai/internal/models'));
        app()->instance(OutboundTransport::class, $transport);
        Log::spy();

        $exitCode = Artisan::call('geoflow:models-internal-check', ['--no-interaction' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringNotContainsString('secret-provider-token', $output);
        $this->assertStringNotContainsString('models.dofe.ai', $output);
        Log::shouldHaveReceived('error')
            ->with('geoflow.models_internal_transport_failed', ['exception_class' => OutboundRequestFailedException::class])
            ->once();
    }

    public function test_client_rejects_an_invalid_success_response(): void
    {
        Http::fake([
            'https://models.dofe.ai/internal/models' => Http::response(['unexpected' => true]),
        ]);

        $this->expectExceptionMessage('返回格式无效');

        ModelsInternalClient::listModels();
    }

    public function test_list_models_unwraps_the_current_models_success_envelope(): void
    {
        Http::fake([
            'https://models.dofe.ai/internal/models' => Http::response([
                'code' => 200,
                'msg' => 'success',
                'data' => ['list' => [], 'total' => 0, 'page' => 1, 'limit' => 20],
            ]),
        ]);

        $this->assertSame(
            ['list' => [], 'total' => 0, 'page' => 1, 'limit' => 20],
            ModelsInternalClient::listModels(),
        );
    }

    public function test_get_model_unwraps_the_current_models_success_envelope(): void
    {
        Http::fake([
            'https://models.dofe.ai/internal/models/model-1' => Http::response([
                'code' => 200,
                'msg' => 'success',
                'data' => [
                    'model' => ['id' => 'model-1'],
                    'supportedProtocols' => ['openai'],
                    'isAvailable' => true,
                    'codexReady' => true,
                ],
            ]),
        ]);

        $this->assertSame(
            [
                'model' => ['id' => 'model-1'],
                'supportedProtocols' => ['openai'],
                'isAvailable' => true,
                'codexReady' => true,
            ],
            ModelsInternalClient::getModel('model-1'),
        );
    }

    public function test_client_rejects_a_failed_business_envelope_returned_with_http_200(): void
    {
        Http::fake([
            'https://models.dofe.ai/internal/models' => Http::response([
                'code' => 503,
                'msg' => 'secret-provider-detail',
                'data' => null,
            ]),
        ]);

        $this->expectExceptionMessage('返回格式无效');

        ModelsInternalClient::listModels();
    }

    public function test_client_retries_a_transient_server_failure(): void
    {
        Http::fakeSequence()
            ->push(['error' => 'temporarily unavailable'], 503)
            ->push(['list' => [], 'total' => 0], 200);

        ModelsInternalClient::listModels();

        Http::assertSentCount(2);
    }

    public function test_client_does_not_retry_a_deterministic_client_failure(): void
    {
        Http::fake([
            'https://models.dofe.ai/internal/models' => Http::response(['error' => 'invalid signature'], 400),
        ]);

        try {
            ModelsInternalClient::listModels();
            $this->fail('Expected the models internal client to reject HTTP 400.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 400', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }
}
