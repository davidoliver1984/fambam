<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photos', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('media_upload_id', 26)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility', 20)->default('family_space');
            $table->string('caption', 255)->nullable();
            $table->text('description')->nullable();
            $table->text('archive_source_description')->nullable();
            $table->char('photographer_person_id', 26)->nullable();
            $table->string('photographer_description', 255)->nullable();
            $table->char('scanner_person_id', 26)->nullable();
            $table->string('scanner_description', 255)->nullable();
            $table->char('physical_owner_person_id', 26)->nullable();
            $table->string('physical_source_description', 255)->nullable();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('media_upload_id')->references('id')->on('media_uploads')->restrictOnDelete();
            $table->foreign('photographer_person_id')->references('id')->on('people')->nullOnDelete();
            $table->foreign('scanner_person_id')->references('id')->on('people')->nullOnDelete();
            $table->foreign('physical_owner_person_id')->references('id')->on('people')->nullOnDelete();
            $table->index(['family_space_id', 'visibility']);
            $table->index(['family_space_id', 'created_by']);
        });

        Schema::create('photo_provenance_proposals', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('photo_id', 26);
            $table->string('role', 30);
            $table->char('person_id', 26)->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('clears_claim')->default(false);
            $table->string('status', 20)->default('pending');
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('photo_id')->references('id')->on('photos')->cascadeOnDelete();
            $table->foreign('person_id')->references('id')->on('people')->nullOnDelete();
            $table->index(['family_space_id', 'photo_id', 'status']);
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->string('label', 80);
            $table->string('normalized_label', 80);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->unique(['family_space_id', 'normalized_label']);
        });

        Schema::create('photo_tag', function (Blueprint $table): void {
            $table->char('family_space_id', 26);
            $table->char('photo_id', 26);
            $table->char('tag_id', 26);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('photo_id')->references('id')->on('photos')->cascadeOnDelete();
            $table->foreign('tag_id')->references('id')->on('tags')->cascadeOnDelete();
            $table->primary(['photo_id', 'tag_id']);
            $table->index(['family_space_id', 'tag_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE photos ADD CONSTRAINT photos_photographer_value_check
CHECK (photographer_person_id IS NULL OR photographer_description IS NULL);
ALTER TABLE photos ADD CONSTRAINT photos_scanner_value_check
CHECK (scanner_person_id IS NULL OR scanner_description IS NULL);
ALTER TABLE photos ADD CONSTRAINT photos_physical_owner_value_check
CHECK (physical_owner_person_id IS NULL OR physical_source_description IS NULL);
ALTER TABLE photo_provenance_proposals ADD CONSTRAINT photo_provenance_proposal_value_check
CHECK (
    (clears_claim = true AND person_id IS NULL AND description IS NULL)
    OR (clears_claim = false AND ((person_id IS NOT NULL) <> (description IS NOT NULL)))
);

ALTER TABLE photos ENABLE ROW LEVEL SECURITY;
ALTER TABLE photos FORCE ROW LEVEL SECURITY;
CREATE POLICY photos_tenant_isolation ON photos
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());

ALTER TABLE photo_provenance_proposals ENABLE ROW LEVEL SECURITY;
ALTER TABLE photo_provenance_proposals FORCE ROW LEVEL SECURITY;
CREATE POLICY photo_provenance_proposals_tenant_isolation ON photo_provenance_proposals
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());

ALTER TABLE tags ENABLE ROW LEVEL SECURITY;
ALTER TABLE tags FORCE ROW LEVEL SECURITY;
CREATE POLICY tags_tenant_isolation ON tags
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());

ALTER TABLE photo_tag ENABLE ROW LEVEL SECURITY;
ALTER TABLE photo_tag FORCE ROW LEVEL SECURITY;
CREATE POLICY photo_tag_tenant_isolation ON photo_tag
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('photo_provenance_proposals');
        Schema::dropIfExists('photos');
    }
};
