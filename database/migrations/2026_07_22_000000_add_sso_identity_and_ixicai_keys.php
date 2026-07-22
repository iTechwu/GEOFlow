<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table): void {
            if (! Schema::hasColumn('admins', 'sso_sub')) {
                $table->string('sso_sub', 191)->nullable()->unique();
            }
            if (! Schema::hasColumn('admins', 'sso_claims')) {
                $table->json('sso_claims')->nullable();
            }
            if (! Schema::hasColumn('admins', 'sso_last_synced_at')) {
                $table->timestamp('sso_last_synced_at')->nullable();
            }
        });

        Schema::create('ixicai_api_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->unique()->constrained('admins')->cascadeOnDelete();
            $table->string('external_key_id', 191)->nullable()->index();
            $table->text('encrypted_key');
            $table->char('key_hash', 64)->unique();
            $table->string('key_prefix', 32);
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('provisioned_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::table('tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('tasks', 'sso_owner_admin_id')) {
                $table->foreignId('sso_owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('tasks', 'sso_owner_admin_id')) {
                $table->dropConstrainedForeignId('sso_owner_admin_id');
            }
        });
        Schema::dropIfExists('ixicai_api_keys');
        Schema::table('admins', function (Blueprint $table): void {
            $columns = array_filter(['sso_sub', 'sso_claims', 'sso_last_synced_at'], fn (string $column): bool => Schema::hasColumn('admins', $column));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
