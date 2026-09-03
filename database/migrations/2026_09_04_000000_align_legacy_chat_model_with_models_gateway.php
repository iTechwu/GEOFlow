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

        DB::table('ai_models')
            ->where('model_id', 'deepseek-chat')
            ->update([
                'name' => 'DeepSeek V4 Flash via Models',
                'model_id' => 'deepseek-v4-flash',
                'api_url' => 'https://ixicai.cn/api/v1',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_models')) {
            return;
        }

        DB::table('ai_models')
            ->where('model_id', 'deepseek-v4-flash')
            ->where('name', 'DeepSeek V4 Flash via Models')
            ->update([
                'name' => 'DeepSeek Chat unified gateway',
                'model_id' => 'deepseek-chat',
                'api_url' => 'https://ixicai.cn/api/v1',
                'updated_at' => now(),
            ]);
    }
};
