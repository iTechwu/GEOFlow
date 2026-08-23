<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('distribution_channels') || Schema::hasColumn('distribution_channels', 'sso_team_id')) {
            return;
        }

        Schema::table('distribution_channels', function (Blueprint $table): void {
            $table->string('sso_team_id', 120)->nullable()->index()->after('created_by_admin_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('distribution_channels') && Schema::hasColumn('distribution_channels', 'sso_team_id')) {
            Schema::table('distribution_channels', function (Blueprint $table): void {
                $table->dropIndex(['sso_team_id']);
                $table->dropColumn('sso_team_id');
            });
        }
    }
};
