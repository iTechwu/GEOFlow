<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 目录内容实体（模型/提示词/素材库/知识库/作者/分类）增加租户列。
     *
     * ai_models 不在此列：模型目录由 models.dofe.ai 统一维护，属共享引用数据，
     * 不按 GEOFlow 租户隔离；其团队归属由 models 侧的 x-team-id 处理。
     */
    private const TABLES = [
        'prompts',
        'title_libraries',
        'keyword_libraries',
        'image_libraries',
        'knowledge_bases',
        'authors',
        'categories',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'sso_team_id')) {
                    $table->string('sso_team_id', 64)->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (Schema::hasColumn($tableName, 'sso_team_id')) {
                    $table->dropIndex(['sso_team_id']);
                    $table->dropColumn('sso_team_id');
                }
            });
        }
    }
};
