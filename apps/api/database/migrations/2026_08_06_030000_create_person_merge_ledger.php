<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_merges', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('survivor_person_id', 26);
            $table->char('absorbed_person_id', 26);
            $table->string('status', 40)->default('active');
            $table->json('provenance');
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('merged_at');
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamp('manual_correction_required_at')->nullable();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('survivor_person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->foreign('absorbed_person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->index(['family_space_id', 'absorbed_person_id', 'status']);
            $table->index(['family_space_id', 'survivor_person_id', 'status']);
        });

        Schema::create('person_merge_proposals', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('survivor_person_id', 26);
            $table->char('absorbed_person_id', 26);
            $table->text('context')->nullable();
            $table->string('status', 20)->default('pending');
            $table->char('person_merge_id', 26)->nullable();
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('survivor_person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->foreign('absorbed_person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->foreign('person_merge_id')->references('id')->on('person_merges')->nullOnDelete();
            $table->index(['family_space_id', 'status', 'created_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX person_merges_active_absorbed_unique
ON person_merges (family_space_id, absorbed_person_id)
WHERE status IN ('active', 'manual_correction_required');

CREATE UNIQUE INDEX person_merge_proposals_pending_pair_unique
ON person_merge_proposals (family_space_id, survivor_person_id, absorbed_person_id)
WHERE status = 'pending';

ALTER TABLE person_merges ENABLE ROW LEVEL SECURITY;
ALTER TABLE person_merges FORCE ROW LEVEL SECURITY;
CREATE POLICY person_merges_tenant_isolation ON person_merges
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());

ALTER TABLE person_merge_proposals ENABLE ROW LEVEL SECURITY;
ALTER TABLE person_merge_proposals FORCE ROW LEVEL SECURITY;
CREATE POLICY person_merge_proposals_tenant_isolation ON person_merge_proposals
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('person_merge_proposals');
        Schema::dropIfExists('person_merges');
    }
};
