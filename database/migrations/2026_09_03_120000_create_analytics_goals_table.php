<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * analytics_goals：内容生产目标（docs/0903/dashboard/06 提案的最小实现）。
 *
 * - 目标必须归属租户（sso_team_id 必填）；category_id 为空表示"租户全局"档，
 *   避免任何跨租户可见的全局目标。
 * - month 为 'YYYY-MM' 字符串；metric 目前支持 published（views 为预留枚举值，
 *   读取侧已实现，写入侧如无业务需要不开放）。
 * - 唯一键含可空 category_id：MySQL 允许 NULL 重复，应用层按四元组 upsert 保证
 *   幂等；索引用于月度读取扫描。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('analytics_goals')) {
            return;
        }

        Schema::create('analytics_goals', function (Blueprint $table) {
            $table->id();
            $table->string('sso_team_id', 120)->index()->comment('目标归属租户；目标不做跨租户全局');
            $table->unsignedBigInteger('category_id')->nullable()->index()->comment('空 = 租户全局档');
            $table->string('month', 7)->comment('YYYY-MM');
            $table->string('metric', 20)->default('published')->comment('published|views');
            $table->unsignedInteger('target')->comment('目标值');
            $table->timestamps();

            $table->index(['sso_team_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_goals');
    }
};
