<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_health_evidence', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->timestamp('last_database_backup_at')->nullable();
            $table->string('database_backup_reference', 500)->nullable();
            $table->char('database_backup_sha256', 64)->nullable();
            $table->timestamp('last_object_storage_verification_at')->nullable();
            $table->string('object_storage_reference', 500)->nullable();
            $table->timestamp('last_restore_drill_at')->nullable();
            $table->string('last_restore_drill_result', 16)->nullable();
            $table->string('restore_drill_reference', 500)->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE backup_health_evidence ADD CONSTRAINT backup_health_evidence_singleton_check
CHECK (id = 1);
ALTER TABLE backup_health_evidence ADD CONSTRAINT backup_health_evidence_restore_result_check
CHECK (last_restore_drill_result IS NULL OR last_restore_drill_result IN ('passed', 'failed'));
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_health_evidence');
    }
};
