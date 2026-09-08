<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_searches', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('filters');
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->unique(['id', 'family_space_id'], 'saved_searches_id_family_unique');
            $table->index(['family_space_id', 'created_by', 'created_at'], 'saved_searches_creator_index');
        });

        Schema::create('saved_search_people', function (Blueprint $table): void {
            $table->char('saved_search_id', 26);
            $table->char('family_space_id', 26);
            $table->char('person_id', 26);

            $table->foreign(['saved_search_id', 'family_space_id'], 'saved_search_people_search_family_foreign')
                ->references(['id', 'family_space_id'])->on('saved_searches')->cascadeOnDelete();
            $table->foreign(['person_id', 'family_space_id'], 'saved_search_people_person_family_foreign')
                ->references(['id', 'family_space_id'])->on('people')->cascadeOnDelete();
            $table->unique(['saved_search_id', 'person_id'], 'saved_search_people_search_person_unique');
            $table->index(['family_space_id', 'person_id'], 'saved_search_people_family_person_index');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE saved_searches ENABLE ROW LEVEL SECURITY;
ALTER TABLE saved_searches FORCE ROW LEVEL SECURITY;
CREATE POLICY saved_searches_tenant_isolation ON saved_searches
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());

ALTER TABLE saved_search_people ENABLE ROW LEVEL SECURITY;
ALTER TABLE saved_search_people FORCE ROW LEVEL SECURITY;
CREATE POLICY saved_search_people_tenant_isolation ON saved_search_people
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_search_people');
        Schema::dropIfExists('saved_searches');
    }
};
