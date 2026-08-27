<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_uploads', function (Blueprint $table): void {
            $table->unique(
                ['id', 'family_space_id'],
                'media_uploads_id_family_space_unique',
            );
        });

        Schema::create('face_analysis_runs', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('media_upload_id', 26);
            $table->char('canonical_sha256', 64);
            $table->string('contract_version', 20);
            $table->string('provider', 80);
            $table->string('model_identifier', 160);
            $table->char('model_weight_checksum', 64);
            $table->char('config_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign(['media_upload_id', 'family_space_id'], 'face_analysis_runs_upload_family_foreign')
                ->references(['id', 'family_space_id'])
                ->on('media_uploads')
                ->cascadeOnDelete();
            $table->unique(['id', 'family_space_id'], 'face_analysis_runs_id_family_unique');
            $table->unique([
                'family_space_id',
                'media_upload_id',
                'canonical_sha256',
                'provider',
                'model_identifier',
                'model_weight_checksum',
                'config_hash',
            ], 'face_analysis_runs_identity_unique');
            $table->index(['family_space_id', 'status']);
        });

        Schema::create('face_analysis_attempts', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('face_analysis_run_id', 26);
            $table->string('expected_result_object_key', 512)->unique();
            $table->string('status', 20)->default('dispatched');
            $table->string('failure_category', 50)->nullable();
            $table->string('failure_detail', 512)->nullable();
            $table->timestamp('dispatched_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign(
                ['face_analysis_run_id', 'family_space_id'],
                'face_analysis_attempts_run_family_foreign',
            )->references(['id', 'family_space_id'])
                ->on('face_analysis_runs')
                ->cascadeOnDelete();
            $table->index(['family_space_id', 'status', 'dispatched_at'], 'face_analysis_attempts_stale_index');
        });

        Schema::create('face_observations', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('face_analysis_run_id', 26);
            $table->unsignedInteger('face_index');
            $table->double('bounds_x');
            $table->double('bounds_y');
            $table->double('bounds_width');
            $table->double('bounds_height');
            $table->json('landmarks');
            $table->string('landmark_scheme', 40);
            $table->double('detection_confidence');
            $table->binary('embedding');
            $table->unsignedInteger('embedding_dimension');
            $table->string('embedding_dtype', 20);
            $table->json('quality_signals')->nullable();
            $table->json('provider_diagnostics')->nullable();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign(
                ['face_analysis_run_id', 'family_space_id'],
                'face_observations_run_family_foreign',
            )->references(['id', 'family_space_id'])
                ->on('face_analysis_runs')
                ->cascadeOnDelete();
            $table->unique(['face_analysis_run_id', 'face_index'], 'face_observations_run_index_unique');
            $table->index(['family_space_id', 'face_analysis_run_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE face_analysis_runs ADD CONSTRAINT face_analysis_runs_status_check CHECK (status IN ('pending', 'processing', 'succeeded', 'failed'))");
        DB::statement("ALTER TABLE face_analysis_runs ADD CONSTRAINT face_analysis_runs_checksum_check CHECK (canonical_sha256 ~ '^[a-f0-9]{64}$' AND model_weight_checksum ~ '^[a-f0-9]{64}$' AND config_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE face_analysis_attempts ADD CONSTRAINT face_analysis_attempts_status_check CHECK (status IN ('dispatched', 'succeeded', 'failed', 'superseded'))");
        DB::statement("ALTER TABLE face_analysis_attempts ADD CONSTRAINT face_analysis_attempts_failure_check CHECK (failure_category IS NULL OR failure_category IN ('checksum_mismatch', 'canonical_unavailable', 'decode_error', 'inference_error', 'timeout', 'result_checksum_mismatch', 'result_artifact_invalid', 'result_artifact_oversized', 'attempt_timed_out'))");
        DB::statement('ALTER TABLE face_observations ADD CONSTRAINT face_observations_geometry_check CHECK (face_index >= 0 AND bounds_x >= 0 AND bounds_y >= 0 AND bounds_width > 0 AND bounds_height > 0 AND detection_confidence >= 0 AND detection_confidence <= 1 AND embedding_dimension > 0)');
        DB::statement("ALTER TABLE face_observations ADD CONSTRAINT face_observations_dtype_check CHECK (embedding_dtype IN ('float32'))");

        foreach (['face_analysis_runs', 'face_analysis_attempts', 'face_observations'] as $table) {
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY; ALTER TABLE {$table} FORCE ROW LEVEL SECURITY; CREATE POLICY {$table}_tenant_isolation ON {$table} USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id());");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('face_observations');
        Schema::dropIfExists('face_analysis_attempts');
        Schema::dropIfExists('face_analysis_runs');

        Schema::table('media_uploads', function (Blueprint $table): void {
            $table->dropUnique('media_uploads_id_family_space_unique');
        });
    }
};
