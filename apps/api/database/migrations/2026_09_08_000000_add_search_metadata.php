<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $searchableTables = ['photos', 'people', 'albums', 'events', 'photo_stories'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            foreach ($this->searchableTables as $table) {
                Schema::table($table, fn (Blueprint $schema) => $schema->text('search_vector')->nullable());
            }
            Schema::table('photos', fn (Blueprint $table) => $table->date('historical_date_window_end')->nullable());

            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::unprepared(<<<'SQL'
ALTER TABLE photos ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
    to_tsvector('simple'::regconfig, coalesce(caption, '') || ' ' || coalesce(description, '') || ' ' || coalesce(archive_source_description, '') || ' ' || coalesce(location_description, ''))
) STORED;
ALTER TABLE people ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
    to_tsvector('simple'::regconfig, coalesce(preferred_name, '') || ' ' || coalesce(alternate_names::text, ''))
) STORED;
ALTER TABLE albums ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
    to_tsvector('simple'::regconfig, coalesce(name, '') || ' ' || coalesce(description, ''))
) STORED;
ALTER TABLE events ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
    to_tsvector('simple'::regconfig, coalesce(name, '') || ' ' || coalesce(description, '') || ' ' || coalesce(location, ''))
) STORED;
ALTER TABLE photo_stories ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
    to_tsvector('simple'::regconfig, coalesce(body, ''))
) STORED;
ALTER TABLE photos ADD COLUMN historical_date_window_end date GENERATED ALWAYS AS (
    CASE historical_date_precision
        WHEN 'exact' THEN historical_date
        WHEN 'approximate' THEN historical_date
        WHEN 'month' THEN ((historical_date + INTERVAL '1 month') - INTERVAL '1 day')::date
        WHEN 'year' THEN ((historical_date + INTERVAL '1 year') - INTERVAL '1 day')::date
        WHEN 'decade' THEN ((historical_date + INTERVAL '10 years') - INTERVAL '1 day')::date
        ELSE NULL
    END
) STORED;

CREATE INDEX photos_search_vector_gin ON photos USING gin (search_vector);
CREATE INDEX people_search_vector_gin ON people USING gin (search_vector);
CREATE INDEX albums_search_vector_gin ON albums USING gin (search_vector);
CREATE INDEX events_search_vector_gin ON events USING gin (search_vector);
CREATE INDEX photo_stories_search_vector_gin ON photo_stories USING gin (search_vector);
CREATE INDEX photos_caption_trgm_gin ON photos USING gin (caption gin_trgm_ops);
CREATE INDEX photos_location_description_trgm_gin ON photos USING gin (location_description gin_trgm_ops);
CREATE INDEX people_preferred_name_trgm_gin ON people USING gin (preferred_name gin_trgm_ops);
CREATE INDEX albums_name_trgm_gin ON albums USING gin (name gin_trgm_ops);
CREATE INDEX events_name_trgm_gin ON events USING gin (name gin_trgm_ops);
CREATE INDEX events_location_trgm_gin ON events USING gin (location gin_trgm_ops);
CREATE INDEX tags_label_trgm_gin ON tags USING gin (label gin_trgm_ops);
CREATE INDEX photos_historical_date_window_idx ON photos (family_space_id, historical_date, historical_date_window_end);
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP INDEX IF EXISTS photos_historical_date_window_idx;
DROP INDEX IF EXISTS tags_label_trgm_gin;
DROP INDEX IF EXISTS events_location_trgm_gin;
DROP INDEX IF EXISTS events_name_trgm_gin;
DROP INDEX IF EXISTS albums_name_trgm_gin;
DROP INDEX IF EXISTS people_preferred_name_trgm_gin;
DROP INDEX IF EXISTS photos_location_description_trgm_gin;
DROP INDEX IF EXISTS photos_caption_trgm_gin;
DROP INDEX IF EXISTS photo_stories_search_vector_gin;
DROP INDEX IF EXISTS events_search_vector_gin;
DROP INDEX IF EXISTS albums_search_vector_gin;
DROP INDEX IF EXISTS people_search_vector_gin;
DROP INDEX IF EXISTS photos_search_vector_gin;
ALTER TABLE photo_stories DROP COLUMN search_vector;
ALTER TABLE events DROP COLUMN search_vector;
ALTER TABLE albums DROP COLUMN search_vector;
ALTER TABLE people DROP COLUMN search_vector;
ALTER TABLE photos DROP COLUMN search_vector, DROP COLUMN historical_date_window_end;
SQL);

            return;
        }

        foreach ($this->searchableTables as $table) {
            Schema::table($table, fn (Blueprint $schema) => $schema->dropColumn('search_vector'));
        }
        Schema::table('photos', fn (Blueprint $table) => $table->dropColumn('historical_date_window_end'));
    }
};
