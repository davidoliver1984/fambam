<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('face_observations', function (Blueprint $table): void {
            $table->unique(['id', 'family_space_id'], 'face_observations_id_family_space_unique');
        });
        Schema::table('people', function (Blueprint $table): void {
            $table->unique(['id', 'family_space_id'], 'people_id_family_space_unique');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        DB::unprepared(<<<'SQL'
CREATE TABLE face_embedding_projections (
    id char(26) PRIMARY KEY,
    family_space_id char(26) NOT NULL,
    face_observation_id char(26) NOT NULL,
    projection_version varchar(40) NOT NULL,
    source_checksum char(64) NOT NULL,
    embedding_dimension integer NOT NULL,
    vector vector NOT NULL,
    created_at timestamp(0) without time zone NOT NULL,
    updated_at timestamp(0) without time zone NOT NULL,
    CONSTRAINT face_embedding_projections_family_foreign
        FOREIGN KEY (family_space_id) REFERENCES family_spaces(id) ON DELETE CASCADE,
    CONSTRAINT face_embedding_projections_observation_family_foreign
        FOREIGN KEY (face_observation_id, family_space_id)
        REFERENCES face_observations(id, family_space_id) ON DELETE CASCADE,
    CONSTRAINT face_embedding_projections_observation_unique UNIQUE (face_observation_id),
    CONSTRAINT face_embedding_projections_checksum_check
        CHECK (source_checksum ~ '^[a-f0-9]{64}$'),
    CONSTRAINT face_embedding_projections_dimension_check
        CHECK (embedding_dimension > 0 AND vector_dims(vector) = embedding_dimension)
);
CREATE INDEX face_embedding_projections_family_index
    ON face_embedding_projections (family_space_id, face_observation_id);
ALTER TABLE face_embedding_projections ENABLE ROW LEVEL SECURITY;
ALTER TABLE face_embedding_projections FORCE ROW LEVEL SECURITY;
CREATE POLICY face_embedding_projections_tenant_isolation
    ON face_embedding_projections
    USING (family_space_id = app_current_family_space_id())
    WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            Schema::dropIfExists('face_embedding_projections');
        }

        Schema::table('people', function (Blueprint $table): void {
            $table->dropUnique('people_id_family_space_unique');
        });
        Schema::table('face_observations', function (Blueprint $table): void {
            $table->dropUnique('face_observations_id_family_space_unique');
        });
    }
};
