<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_relationships', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('subject_person_id', 26);
            $table->char('related_person_id', 26);
            $table->string('type', 40);
            $table->string('status', 20)->default('confirmed');
            $table->text('context')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('subject_person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->foreign('related_person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->unique(['family_space_id', 'type', 'subject_person_id', 'related_person_id'], 'person_relationships_canonical_unique');
            $table->index(['family_space_id', 'subject_person_id', 'status']);
            $table->index(['family_space_id', 'related_person_id', 'status']);
        });

        Schema::create('relationship_proposals', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->string('action', 20);
            $table->char('relationship_id', 26)->nullable();
            $table->char('subject_person_id', 26)->nullable();
            $table->char('related_person_id', 26)->nullable();
            $table->string('type', 40)->nullable();
            $table->text('context')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('relationship_id')->references('id')->on('person_relationships')->nullOnDelete();
            $table->foreign('subject_person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->foreign('related_person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->index(['family_space_id', 'status', 'created_at']);
        });

        Schema::create('family_circles', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->unique(['family_space_id', 'name']);
        });

        Schema::create('family_circle_people', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('family_circle_id', 26);
            $table->char('person_id', 26);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('family_circle_id')->references('id')->on('family_circles')->cascadeOnDelete();
            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->unique(['family_space_id', 'family_circle_id', 'person_id'], 'family_circle_people_unique');
            $table->index(['family_space_id', 'person_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE person_relationships ENABLE ROW LEVEL SECURITY;
ALTER TABLE person_relationships FORCE ROW LEVEL SECURITY;
CREATE POLICY person_relationships_tenant_isolation ON person_relationships
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());

ALTER TABLE relationship_proposals ENABLE ROW LEVEL SECURITY;
ALTER TABLE relationship_proposals FORCE ROW LEVEL SECURITY;
CREATE POLICY relationship_proposals_tenant_isolation ON relationship_proposals
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());

ALTER TABLE family_circles ENABLE ROW LEVEL SECURITY;
ALTER TABLE family_circles FORCE ROW LEVEL SECURITY;
CREATE POLICY family_circles_tenant_isolation ON family_circles
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());

ALTER TABLE family_circle_people ENABLE ROW LEVEL SECURITY;
ALTER TABLE family_circle_people FORCE ROW LEVEL SECURITY;
CREATE POLICY family_circle_people_tenant_isolation ON family_circle_people
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('family_circle_people');
        Schema::dropIfExists('family_circles');
        Schema::dropIfExists('relationship_proposals');
        Schema::dropIfExists('person_relationships');
    }
};
