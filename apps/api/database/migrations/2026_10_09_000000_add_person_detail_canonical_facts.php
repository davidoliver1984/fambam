<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->text('profile_quote')->nullable()->after('biography');
            $table->string('profile_quote_attribution', 120)->nullable()->after('profile_quote');
        });

        Schema::create('person_known_for', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('person_id', 26);
            $table->string('label', 120);
            $table->unsignedSmallInteger('position');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->unique(['person_id', 'label'], 'person_known_for_person_label_unique');
            $table->unique(['person_id', 'position'], 'person_known_for_person_position_unique');
            $table->index(['family_space_id', 'person_id', 'position'], 'person_known_for_family_person_position_idx');
        });

        Schema::table('person_relationships', function (Blueprint $table): void {
            $table->date('relationship_started_on')->nullable()->after('context');
            $table->string('relationship_started_on_precision', 20)->default('unknown')->after('relationship_started_on');
        });

        Schema::table('relationship_proposals', function (Blueprint $table): void {
            $table->date('relationship_started_on')->nullable()->after('context');
            $table->string('relationship_started_on_precision', 20)->default('unknown')->after('relationship_started_on');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE person_known_for ENABLE ROW LEVEL SECURITY;
ALTER TABLE person_known_for FORCE ROW LEVEL SECURITY;
CREATE POLICY person_known_for_tenant_isolation ON person_known_for
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
    }

    public function down(): void
    {
        Schema::table('relationship_proposals', function (Blueprint $table): void {
            $table->dropColumn(['relationship_started_on', 'relationship_started_on_precision']);
        });
        Schema::table('person_relationships', function (Blueprint $table): void {
            $table->dropColumn(['relationship_started_on', 'relationship_started_on_precision']);
        });
        Schema::dropIfExists('person_known_for');
        Schema::table('people', function (Blueprint $table): void {
            $table->dropColumn(['profile_quote', 'profile_quote_attribution']);
        });
    }
};
