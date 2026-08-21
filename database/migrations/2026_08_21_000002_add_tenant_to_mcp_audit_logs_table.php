<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_audit_logs', function (Blueprint $table) {
            $table->string('tenant', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('mcp_audit_logs', function (Blueprint $table) {
            $table->dropIndex(['tenant']);
            $table->dropColumn('tenant');
        });
    }
};
