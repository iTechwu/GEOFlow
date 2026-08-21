<?php

namespace Tests\Unit;

use App\Support\ModelsInternalAuth;
use PHPUnit\Framework\TestCase;

class ModelsInternalAuthTest extends TestCase
{
    public function test_two_part_token_matches_models_guard_contract(): void
    {
        $token = ModelsInternalAuth::token('test-secret', null, 1234567890);

        // 与 models internal-auth.guard.ts 的 createHmac('sha256', secret).update(timestamp).digest('hex') 一致。
        $this->assertSame(
            '1234567890:6930a2e75a647ecc2b4741aac18d754f7e63b458c1cea4969e209704a8ea324e',
            $token,
        );
    }

    public function test_three_part_token_binds_service_name(): void
    {
        $token = ModelsInternalAuth::token('test-secret', 'geoflow', 1234567890);

        // 三段令牌的签名覆盖 "<timestamp>:<serviceName>"。
        $this->assertSame(
            '1234567890:0d369dd16054f11476562e4ac00c08ea8a5a142ccdf5e96579100007b7913b64:geoflow',
            $token,
        );
    }

    public function test_headers_include_authorization_and_service_name(): void
    {
        $headers = ModelsInternalAuth::headers('test-secret', 'geoflow');

        $this->assertStringStartsWith('Bearer ', $headers['Authorization']);
        $this->assertSame('geoflow', $headers['x-service-name']);
    }

    public function test_headers_without_service_name_omit_x_service_name(): void
    {
        $headers = ModelsInternalAuth::headers('test-secret');

        $this->assertStringStartsWith('Bearer ', $headers['Authorization']);
        $this->assertArrayNotHasKey('x-service-name', $headers);
    }
}
