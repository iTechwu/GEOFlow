<?php

namespace Tests\Feature;

use App\Services\Models\ModelsInternalClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModelsInternalClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('geoflow.models_internal_base_url', 'https://models.dofe.ai');
        config()->set('geoflow.models_internal_api_secret', 'test-secret');
        config()->set('geoflow.models_service_name', 'geoflow');
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

        $this->expectException(\RuntimeException::class);

        ModelsInternalClient::listModels();
    }
}
