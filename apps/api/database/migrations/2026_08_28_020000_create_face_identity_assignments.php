<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_identity_assignments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('face_observation_id', 26);
            $table->char('person_id', 26);
            $table->string('proposal_source', 40)->default('human');
            $table->string('status', 20)->default('pending');
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign(['face_observation_id', 'family_space_id'], 'face_identity_assignments_observation_family_foreign')
                ->references(['id', 'family_space_id'])->on('face_observations')->cascadeOnDelete();
            $table->foreign(['person_id', 'family_space_id'], 'face_identity_assignments_person_family_foreign')
                ->references(['id', 'family_space_id'])->on('people')->cascadeOnDelete();
            $table->index(['family_space_id', 'person_id', 'status'], 'face_identity_assignments_gallery_index');
        });

        DB::statement("CREATE UNIQUE INDEX face_identity_assignments_active_unique ON face_identity_assignments (face_observation_id) WHERE status IN ('pending', 'approved')");

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE face_identity_assignments ADD CONSTRAINT face_identity_assignments_status_check
    CHECK (status IN ('pending', 'approved', 'rejected', 'withdrawn'));
ALTER TABLE face_identity_assignments ENABLE ROW LEVEL SECURITY;
ALTER TABLE face_identity_assignments FORCE ROW LEVEL SECURITY;
CREATE POLICY face_identity_assignments_tenant_isolation ON face_identity_assignments
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('face_identity_assignments');
    }
};
