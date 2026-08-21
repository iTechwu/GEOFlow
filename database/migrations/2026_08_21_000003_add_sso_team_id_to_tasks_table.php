<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('tasks', 'sso_team_id')) {
                $table->string('sso_team_id', 64)->nullable()->index();
            }
        });

        $this->backfillFromOwnerAdmins();
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('tasks', 'sso_team_id')) {
                $table->dropIndex(['sso_team_id']);
                $table->dropColumn('sso_team_id');
            }
        });
    }

    /**
     * 现有任务按 sso_owner_admin_id 归属管理员在 sso_claims 中记录的 selected_team_id 回填租户。
     *
     * 说明：
     * - 使用 PHP json_decode 解码以兼容 pgsql(json) 与 sqlite(text)；
     * - 提取逻辑与 {@see \App\Services\Sso\SsoIdentityService::selectedTeamId()} 保持一致，
     *   但迁移内独立实现、按迁移时点冻结，避免未来服务签名变化影响历史迁移；
     * - 无 admins 数据（如空库/测试库）时为无操作。
     */
    private function backfillFromOwnerAdmins(): void
    {
        if (! Schema::hasTable('admins') || ! Schema::hasColumn('tasks', 'sso_team_id')) {
            return;
        }

        $admins = DB::table('admins')->whereNotNull('sso_claims')->get(['id', 'sso_claims']);
        foreach ($admins as $admin) {
            $claims = is_string($admin->sso_claims) ? json_decode($admin->sso_claims, true) : $admin->sso_claims;
            if (! is_array($claims)) {
                continue;
            }

            $teamId = null;
            foreach (['team_id', 'selected_team_id', 'current_team_id', 'tenant_id'] as $field) {
                if (is_string($claims[$field] ?? null) && trim($claims[$field]) !== '') {
                    $teamId = trim((string) $claims[$field]);
                    break;
                }
            }
            if ($teamId === null) {
                foreach (['team', 'selected_team', 'current_team', 'tenant'] as $field) {
                    $value = $claims[$field] ?? null;
                    if (is_array($value) && is_string($value['id'] ?? null) && trim($value['id']) !== '') {
                        $teamId = trim($value['id']);
                        break;
                    }
                }
            }
            if ($teamId === null) {
                continue;
            }

            DB::table('tasks')
                ->where('sso_owner_admin_id', (int) $admin->id)
                ->whereNull('sso_team_id')
                ->update(['sso_team_id' => $teamId, 'updated_at' => now()]);
        }
    }
};
