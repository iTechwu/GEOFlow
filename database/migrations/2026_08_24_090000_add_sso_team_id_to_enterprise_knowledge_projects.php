<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enterprise_knowledge_projects') || Schema::hasColumn('enterprise_knowledge_projects', 'sso_team_id')) {
            return;
        }

        Schema::table('enterprise_knowledge_projects', function (Blueprint $table): void {
            $table->string('sso_team_id', 128)->nullable()->index()->after('created_by_admin_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('enterprise_knowledge_projects') || ! Schema::hasColumn('enterprise_knowledge_projects', 'sso_team_id')) {
            return;
        }

        Schema::table('enterprise_knowledge_projects', function (Blueprint $table): void {
            $table->dropIndex(['sso_team_id']);
            $table->dropColumn('sso_team_id');
        });
    }
};
