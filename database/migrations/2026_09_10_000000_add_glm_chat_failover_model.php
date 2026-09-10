<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_models')) {
            return;
        }

        // smart_failover 需要至少一个备用 chat 模型：主模型空正文/坏 JSON 时按
        // failover_priority 自动切换。运行时凭据由统一网关覆盖注入，此处不落密钥。
        $exists = DB::table('ai_models')->where('model_id', 'glm-5.3-flash')->exists();
        if ($exists) {
            return;
        }

        DB::table('ai_models')->insert([
            'name' => 'GLM 5.3 Flash via Models',
            'version' => '',
            'api_key' => '',
            'model_id' => 'glm-5.3-flash',
            'model_type' => 'chat',
            'api_url' => 'https://ixicai.cn/api/v1',
            'failover_priority' => 10,
            'daily_limit' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_models')) {
            return;
        }

        DB::table('ai_models')
            ->where('model_id', 'glm-5.3-flash')
            ->where('name', 'GLM 5.3 Flash via Models')
            ->delete();
    }
};
