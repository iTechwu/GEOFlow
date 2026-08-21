<?php

namespace App\Console\Commands;

use App\Models\ApiIdempotencyKey;
use App\Models\McpAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PruneTransientDataCommand extends Command
{
    protected $signature = 'geoflow:prune-transient
                            {--audit-days= : Override MCP audit retention days}
                            {--idempotency-days= : Override idempotency retention days}';

    protected $description = 'Prune expired MCP audit logs and idempotency keys';

    public function handle(): int
    {
        $now = Carbon::now();

        $auditDays = (int) ($this->option('audit-days') ?: config('geoflow.mcp_audit_retention_days', 30));
        $idempotencyDays = (int) ($this->option('idempotency-days') ?: config('geoflow.idempotency_retention_days', 7));

        $auditCutoff = $now->copy()->subDays(max(1, $auditDays));
        $idempotencyCutoff = $now->copy()->subDays(max(1, $idempotencyDays));

        $auditDeleted = McpAuditLog::query()
            ->where('created_at', '<', $auditCutoff)
            ->delete();

        // 已完成幂等记录：重放窗口早已结束，可安全清理。
        $completedDeleted = ApiIdempotencyKey::query()
            ->where('state', 'completed')
            ->where('updated_at', '<', $idempotencyCutoff)
            ->delete();

        // 进行中的孤儿预留：lease 已过期且远超保留期，可清理。
        $orphanedDeleted = ApiIdempotencyKey::query()
            ->where('state', 'in_progress')
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', $idempotencyCutoff)
            ->delete();

        $this->components->info(sprintf(
            'Pruned transient data: mcp_audit_logs=%d, idempotency completed=%d, idempotency orphaned=%d',
            $auditDeleted,
            $completedDeleted,
            $orphanedDeleted,
        ));

        return self::SUCCESS;
    }
}
