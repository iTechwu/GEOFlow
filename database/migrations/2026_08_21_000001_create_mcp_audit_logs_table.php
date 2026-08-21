<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MCP 写工具审计日志：记录 CI Agent 通过 /mcp 触发的任务启停/入队。
     * 只存令牌 SHA-256 指纹，绝不落原始 Bearer Token。
     */
    public function up(): void
    {
        Schema::create('mcp_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->index();
            $table->string('scope', 20);
            $table->string('tool', 100)->index();
            $table->string('target_type', 50)->default('');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->string('outcome', 20)->default('success');
            $table->string('request_id', 64)->default('');
            $table->string('ip_address', 64)->default('');
            $table->text('details')->default('');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_audit_logs');
    }
};
