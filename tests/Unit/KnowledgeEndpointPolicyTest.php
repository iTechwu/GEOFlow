<?php

namespace Tests\Unit;

use App\Services\Knowledge\KnowledgeEndpointPolicy;
use Tests\TestCase;

final class KnowledgeEndpointPolicyTest extends TestCase
{
    public function test_ci_internal_knowledge_origin_requires_the_exact_private_target(): void
    {
        config()->set('geoflow.outbound_private_targets', []);
        $this->assertFalse(KnowledgeEndpointPolicy::allows('http://dofe-knowledge-api:3110/api'));

        config()->set('geoflow.outbound_private_targets', ['dofe-knowledge-api:3110']);
        $this->assertTrue(KnowledgeEndpointPolicy::allows('http://dofe-knowledge-api:3110/api'));

        foreach ([
            'http://dofe-knowledge-api:3111/api',
            'http://attacker:3110/api',
            'http://user@dofe-knowledge-api:3110/api',
            'http://dofe-knowledge-api:3110/api?target=attacker',
        ] as $url) {
            $this->assertFalse(KnowledgeEndpointPolicy::allows($url), $url);
        }
    }
}
