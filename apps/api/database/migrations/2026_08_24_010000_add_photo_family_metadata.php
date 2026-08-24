<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photos', function (Blueprint $table): void {
            $table->string('historical_date_precision', 20)->nullable();
            $table->date('historical_date')->nullable();
            $table->string('location_description', 255)->nullable();
            $table->index(['family_space_id', 'historical_date']);
        });

        Schema::create('photo_metadata_proposals', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('photo_id', 26);
            $table->string('field', 30);
            $table->string('date_precision', 20)->nullable();
            $table->date('date_value')->nullable();
            $table->string('location_description', 255)->nullable();
            $table->boolean('clears_claim')->default(false);
            $table->string('status', 20)->default('pending');
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('photo_id')->references('id')->on('photos')->cascadeOnDelete();
            $table->index(['family_space_id', 'photo_id', 'status']);
        });

        Schema::create('photo_people', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('photo_id', 26);
            $table->char('person_id', 26);
            $table->string('proposal_source', 30)->default('human');
            $table->string('status', 20)->default('pending');
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('photo_id')->references('id')->on('photos')->cascadeOnDelete();
            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->index(['family_space_id', 'photo_id', 'status']);
            $table->index(['family_space_id', 'person_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX photo_people_active_unique ON photo_people (photo_id, person_id) WHERE status IN ('pending', 'approved')");

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE photos ADD CONSTRAINT photos_historical_date_check
CHECK (
    (historical_date_precision IS NULL AND historical_date IS NULL)
    OR (historical_date_precision = 'unknown' AND historical_date IS NULL)
    OR (historical_date_precision IN ('exact', 'month', 'year', 'decade', 'approximate') AND historical_date IS NOT NULL)
);
ALTER TABLE photo_metadata_proposals ADD CONSTRAINT photo_metadata_proposals_value_check
CHECK (
    (clears_claim = true AND date_precision IS NULL AND date_value IS NULL AND location_description IS NULL)
    OR (clears_claim = false AND field = 'historical_date' AND date_precision IS NOT NULL AND location_description IS NULL
        AND ((date_precision = 'unknown' AND date_value IS NULL)
            OR (date_precision IN ('exact', 'month', 'year', 'decade', 'approximate') AND date_value IS NOT NULL)))
    OR (clears_claim = false AND field = 'location' AND date_precision IS NULL AND date_value IS NULL AND location_description IS NOT NULL)
);

ALTER TABLE photo_metadata_proposals ENABLE ROW LEVEL SECURITY;
ALTER TABLE photo_metadata_proposals FORCE ROW LEVEL SECURITY;
CREATE POLICY photo_metadata_proposals_tenant_isolation ON photo_metadata_proposals
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());

ALTER TABLE photo_people ENABLE ROW LEVEL SECURITY;
ALTER TABLE photo_people FORCE ROW LEVEL SECURITY;
CREATE POLICY photo_people_tenant_isolation ON photo_people
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_people');
        Schema::dropIfExists('photo_metadata_proposals');
        Schema::table('photos', function (Blueprint $table): void {
            $table->dropIndex(['family_space_id', 'historical_date']);
            $table->dropColumn(['historical_date_precision', 'historical_date', 'location_description']);
        });
    }
};
