<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_observation_reviews', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('face_observation_id', 26);
            $table->string('disposition', 40);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign(
                ['face_observation_id', 'family_space_id'],
                'face_observation_reviews_observation_family_foreign',
            )->references(['id', 'family_space_id'])->on('face_observations')->cascadeOnDelete();
            $table->unique('face_observation_id');
            $table->index(['family_space_id', 'disposition'], 'face_observation_reviews_family_disposition_index');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE face_observation_reviews ADD CONSTRAINT face_observation_reviews_disposition_check
    CHECK (disposition IN ('left_unidentified'));
ALTER TABLE face_observation_reviews ENABLE ROW LEVEL SECURITY;
ALTER TABLE face_observation_reviews FORCE ROW LEVEL SECURITY;
CREATE POLICY face_observation_reviews_tenant_isolation ON face_observation_reviews
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('face_observation_reviews');
    }
};
