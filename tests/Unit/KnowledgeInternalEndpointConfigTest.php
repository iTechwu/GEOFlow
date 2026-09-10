<?php

namespace Tests\Unit;

use App\Services\Knowledge\KnowledgeEndpointPolicy;
use Tests\TestCase;

final class KnowledgeInternalEndpointConfigTest extends TestCase
{
    public function test_internal_knowledge_api_is_allow_listed_when_explicitly_configured(): void
    {
        config()->set('geoflow.outbound_private_targets', ['dofe-knowledge-api:3110']);

        $this->assertTrue(KnowledgeEndpointPolicy::allows('http://dofe-knowledge-api:3110'));
    }

    public function test_knowledge_read_mode_is_primary_by_default(): void
    {
        $this->assertSame('primary', config('geoflow.knowledge_read_mode'));
    }
}
