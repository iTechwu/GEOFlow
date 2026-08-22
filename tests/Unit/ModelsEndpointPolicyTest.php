<?php

namespace Tests\Unit;

use App\Services\Models\ModelsEndpointPolicy;
use Tests\TestCase;

class ModelsEndpointPolicyTest extends TestCase
{
    public function test_it_allows_only_the_canonical_production_https_origin(): void
    {
        $this->assertTrue(ModelsEndpointPolicy::allows('https://models.dofe.ai/v1'));
        $this->assertTrue(ModelsEndpointPolicy::allows('https://models.dofe.ai:443'));

        foreach ([
            'https://attacker.example.test/v1',
            'https://models.dofe.ai:444/v1',
            'https://user@models.dofe.ai/v1',
            'https://models.dofe.ai/v1?target=attacker',
            'https://models.dofe.ai/v1#attacker',
            'http://models.dofe.ai/v1',
        ] as $baseUrl) {
            $this->assertFalse(ModelsEndpointPolicy::allows($baseUrl), $baseUrl);
        }
    }

    public function test_local_http_requires_the_explicit_switch_and_known_host(): void
    {
        config()->set('geoflow.models_allow_insecure_local', false);
        $this->assertFalse(ModelsEndpointPolicy::allows('http://dofe-models-api-local:3101/v1'));

        config()->set('geoflow.models_allow_insecure_local', true);
        $this->assertFalse(ModelsEndpointPolicy::allows('http://dofe-models-api-local:3101/v1'));

        config()->set('geoflow.outbound_private_targets', ['dofe-models-api-local:3101']);
        $this->assertTrue(ModelsEndpointPolicy::allows('http://dofe-models-api-local:3101/v1'));
        $this->assertFalse(ModelsEndpointPolicy::allows('http://attacker.example.test:3101/v1'));
    }
}
