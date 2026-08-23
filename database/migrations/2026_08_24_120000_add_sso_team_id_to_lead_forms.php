<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lead_forms') || Schema::hasColumn('lead_forms', 'sso_team_id')) {
            return;
        }

        Schema::table('lead_forms', function (Blueprint $table): void {
            $table->string('sso_team_id', 120)->nullable()->index()->after('id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('lead_forms') && Schema::hasColumn('lead_forms', 'sso_team_id')) {
            Schema::table('lead_forms', function (Blueprint $table): void {
                $table->dropIndex(['sso_team_id']);
                $table->dropColumn('sso_team_id');
            });
        }
    }
};
