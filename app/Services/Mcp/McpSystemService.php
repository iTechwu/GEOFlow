<?php

namespace App\Services\Mcp;

use App\Http\McpAuthContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Safe, read-only deployment diagnostics for Codex and Claude Code.
 *
 * This deliberately reports capability flags rather than hosts, credentials,
 * filesystem paths, migration filenames, or raw exception messages.
 */
class McpSystemService
{
    public function status(McpAuthContext $auth): array
    {
        return [
            'tenant_id' => $auth->tenantId,
            'mcp' => [
                'enabled' => (bool) config('geoflow.mcp_enabled', false),
                'scope' => $auth->scope,
                'tenant_mode' => $auth->tenantId !== null && $auth->tenantId !== '' ? 'tenant_scoped' : 'system_scoped',
                'audit_admin_configured' => $auth->auditAdminId !== null && $auth->auditAdminId > 0,
            ],
            'application' => [
                'environment' => (string) app()->environment(),
                'version' => (string) config('app.version', 'unknown'),
                'php_version' => PHP_VERSION,
            ],
            'database' => [
                'driver' => (string) config('database.default', 'unknown'),
                'reachable' => $this->databaseReachable(),
            ],
            'queue' => [
                'connection' => (string) config('queue.default', 'unknown'),
                'driver' => (string) config('queue.connections.'.config('queue.default').'.driver', 'unknown'),
            ],
            'migrations' => $this->migrationStatus(),
            'extensions' => [
                'gd' => extension_loaded('gd'),
                'zip' => class_exists('ZipArchive'),
                'redis' => extension_loaded('redis'),
                'pdo_pgsql' => in_array('pgsql', \PDO::getAvailableDrivers(), true),
            ],
        ];
    }

    private function databaseReachable(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{table_available:bool,ran_count:int,pending_count:int,latest_ran:string|null} */
    private function migrationStatus(): array
    {
        try {
            if (! Schema::hasTable('migrations')) {
                return ['table_available' => false, 'ran_count' => 0, 'pending_count' => 0, 'latest_ran' => null];
            }

            $ran = DB::table('migrations')->pluck('migration')->map(static fn (mixed $migration): string => (string) $migration)->all();
            $files = glob(database_path('migrations/*.php')) ?: [];
            $available = array_map(static fn (string $file): string => pathinfo($file, PATHINFO_FILENAME), $files);
            $pending = array_diff($available, $ran);
            sort($ran);

            return [
                'table_available' => true,
                'ran_count' => count($ran),
                'pending_count' => count($pending),
                'latest_ran' => $ran !== [] ? (string) end($ran) : null,
            ];
        } catch (Throwable) {
            return ['table_available' => false, 'ran_count' => 0, 'pending_count' => 0, 'latest_ran' => null];
        }
    }
}
