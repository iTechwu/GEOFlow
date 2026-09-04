<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('articles')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->string('humanize_status', 32)->nullable()->index();
            $table->unsignedTinyInteger('humanize_score')->nullable();
            $table->string('humanize_classification', 32)->nullable();
            $table->json('humanize_issues')->nullable();
            $table->string('humanize_original_hash', 64)->nullable();
            $table->string('humanize_model', 255)->nullable();
            $table->timestamp('humanized_at')->nullable();
            $table->text('humanize_error')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('articles')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $columns = [
                'humanize_status',
                'humanize_score',
                'humanize_classification',
                'humanize_issues',
                'humanize_original_hash',
                'humanize_model',
                'humanized_at',
                'humanize_error',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('articles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
