<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class KnowledgeCheckCommandTest extends TestCase
{
    public function test_command_rejects_incomplete_configuration_without_printing_secrets(): void
    {
        config()->set([
            'geoflow.knowledge_api_url' => '',
            'geoflow.knowledge_sso_client_secret' => 'must-not-be-printed',
            'geoflow.knowledge_space_ids' => [],
        ]);

        $exitCode = Artisan::call('geoflow:knowledge-check', ['--no-interaction' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('deployment configuration is incomplete', $output);
        $this->assertStringNotContainsString('must-not-be-printed', $output);
    }
}
