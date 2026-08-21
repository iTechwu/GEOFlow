<?php

namespace Tests\Unit;

use App\Services\Ixicai\IxicaiRuntimeCredentials;
use RuntimeException;
use Tests\TestCase;

class IxicaiRuntimeCredentialsTest extends TestCase
{
    public function test_models_gateway_credentials_do_not_require_an_sso_session(): void
    {
        config([
            'geoflow.models_base_url' => 'https://models.test/v1',
            'geoflow.models_api_key' => 'models-service-key',
            'geoflow.ai_base_url' => '',
            'geoflow.ai_api_key' => '',
        ]);

        $this->assertSame([
            'base_url' => 'https://models.test/v1',
            'api_key' => 'models-service-key',
        ], app(IxicaiRuntimeCredentials::class)->forCurrentUser());
    }

    public function test_legacy_user_credentials_still_require_an_sso_identity(): void
    {
        config([
            'geoflow.models_base_url' => '',
            'geoflow.models_api_key' => '',
            'geoflow.ai_base_url' => '',
            'geoflow.ai_api_key' => '',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('An SSO user session is required');

        app(IxicaiRuntimeCredentials::class)->forAdmin(null);
    }
}
