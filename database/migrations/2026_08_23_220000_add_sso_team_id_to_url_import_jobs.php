<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('url_import_jobs') || Schema::hasColumn('url_import_jobs', 'sso_team_id')) {
            return;
        }

        Schema::table('url_import_jobs', function (Blueprint $table): void {
            $table->string('sso_team_id', 128)->nullable()->index()->after('created_by');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('url_import_jobs') && Schema::hasColumn('url_import_jobs', 'sso_team_id')) {
            Schema::table('url_import_jobs', function (Blueprint $table): void {
                $table->dropIndex(['sso_team_id']);
                $table->dropColumn('sso_team_id');
            });
        }
    }
};
