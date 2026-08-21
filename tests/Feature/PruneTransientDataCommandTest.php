<?php

namespace Tests\Feature;

use App\Models\ApiIdempotencyKey;
use App\Models\McpAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PruneTransientDataCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-01 00:00:00');
        config()->set('geoflow.mcp_audit_retention_days', 30);
        config()->set('geoflow.idempotency_retention_days', 7);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_prunes_expired_audit_and_idempotency_but_keeps_recent_records(): void
    {
        // 旧数据（2026-06-01，超出保留期）
        $oldAudit = McpAuditLog::query()->create([
            'token_hash' => str_repeat('a', 64),
            'scope' => 'write',
            'tool' => 'geoflow.tasks.start',
            'outcome' => 'success',
        ]);

        $oldCompleted = ApiIdempotencyKey::query()->create([
            'idempotency_key' => 'old-completed',
            'route_key' => 'mcp:tools.call:geoflow.tasks.enqueue',
            'request_hash' => str_repeat('b', 64),
            'response_body' => '{}',
            'response_status' => 200,
            'state' => 'completed',
        ]);

        $oldOrphaned = ApiIdempotencyKey::query()->create([
            'idempotency_key' => 'old-orphaned',
            'route_key' => 'mcp:tools.call:geoflow.tasks.enqueue',
            'request_hash' => str_repeat('c', 64),
            'response_body' => '{}',
            'response_status' => 0,
            'state' => 'in_progress',
            'owner_token' => str_repeat('d', 64),
            'lease_expires_at' => Carbon::now()->addMinutes(5),
        ]);

        // 新数据（2026-08-21，保留期内）
        Carbon::setTestNow('2026-08-21 00:00:00');

        $newAudit = McpAuditLog::query()->create([
            'token_hash' => str_repeat('e', 64),
            'scope' => 'write',
            'tool' => 'geoflow.tasks.stop',
            'outcome' => 'success',
        ]);

        $newCompleted = ApiIdempotencyKey::query()->create([
            'idempotency_key' => 'new-completed',
            'route_key' => 'mcp:tools.call:geoflow.tasks.enqueue',
            'request_hash' => str_repeat('f', 64),
            'response_body' => '{}',
            'response_status' => 200,
            'state' => 'completed',
        ]);

        $newOrphaned = ApiIdempotencyKey::query()->create([
            'idempotency_key' => 'new-orphaned',
            'route_key' => 'mcp:tools.call:geoflow.tasks.enqueue',
            'request_hash' => str_repeat('1', 64),
            'response_body' => '{}',
            'response_status' => 0,
            'state' => 'in_progress',
            'owner_token' => str_repeat('2', 64),
            'lease_expires_at' => Carbon::now()->addMinutes(5),
        ]);

        $this->artisan('geoflow:prune-transient')->assertSuccessful();

        // 旧数据被清理。
        $this->assertDatabaseMissing('mcp_audit_logs', ['id' => $oldAudit->id]);
        $this->assertDatabaseMissing('api_idempotency_keys', ['id' => $oldCompleted->id]);
        $this->assertDatabaseMissing('api_idempotency_keys', ['id' => $oldOrphaned->id]);

        // 新数据保留。
        $this->assertDatabaseHas('mcp_audit_logs', ['id' => $newAudit->id]);
        $this->assertDatabaseHas('api_idempotency_keys', ['id' => $newCompleted->id]);
        $this->assertDatabaseHas('api_idempotency_keys', ['id' => $newOrphaned->id]);
    }

    public function test_tenant_option_scopes_audit_pruning(): void
    {
        // 旧审计记录（2026-06-01），分属两个租户。
        $oldTenantA = McpAuditLog::query()->create([
            'token_hash' => str_repeat('a', 64),
            'scope' => 'write',
            'tool' => 'geoflow.tasks.start',
            'outcome' => 'success',
            'tenant' => 'tenant-a',
        ]);
        $oldTenantB = McpAuditLog::query()->create([
            'token_hash' => str_repeat('b', 64),
            'scope' => 'write',
            'tool' => 'geoflow.tasks.start',
            'outcome' => 'success',
            'tenant' => 'tenant-b',
        ]);

        Carbon::setTestNow('2026-08-21 00:00:00');

        $this->artisan('geoflow:prune-transient', ['--tenant' => 'tenant-a'])->assertSuccessful();

        $this->assertDatabaseMissing('mcp_audit_logs', ['id' => $oldTenantA->id]);
        $this->assertDatabaseHas('mcp_audit_logs', ['id' => $oldTenantB->id]);
    }
}
