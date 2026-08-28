<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_identity_suppressions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('face_observation_id', 26);
            $table->char('person_id', 26);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at');
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamp('created_at');

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign(['face_observation_id', 'family_space_id'], 'face_identity_suppressions_observation_family_foreign')
                ->references(['id', 'family_space_id'])->on('face_observations')->cascadeOnDelete();
            $table->foreign(['person_id', 'family_space_id'], 'face_identity_suppressions_person_family_foreign')
                ->references(['id', 'family_space_id'])->on('people')->cascadeOnDelete();
            $table->unique(
                ['family_space_id', 'face_observation_id', 'person_id'],
                'face_identity_suppressions_pair_unique',
            );
            $table->index(['family_space_id', 'face_observation_id', 'reopened_at'], 'face_identity_suppressions_active_index');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE face_identity_suppressions ADD CONSTRAINT face_identity_suppressions_reopening_check
CHECK ((reopened_at IS NULL AND reopened_by IS NULL) OR (reopened_at IS NOT NULL AND reopened_by IS NOT NULL));
ALTER TABLE face_identity_suppressions ENABLE ROW LEVEL SECURITY;
ALTER TABLE face_identity_suppressions FORCE ROW LEVEL SECURITY;
CREATE POLICY face_identity_suppressions_tenant_isolation ON face_identity_suppressions
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('face_identity_suppressions');
    }
};
