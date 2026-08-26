<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perceptual_hashes', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('media_upload_id', 26);
            $table->string('algorithm', 80);
            $table->unsignedInteger('processing_version');
            $table->char('hash_value', 16);
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('media_upload_id')->references('id')->on('media_uploads')->cascadeOnDelete();
            $table->unique(
                ['media_upload_id', 'algorithm', 'processing_version'],
                'perceptual_hashes_upload_algorithm_version_unique',
            );
            $table->index(
                ['family_space_id', 'algorithm', 'processing_version'],
                'perceptual_hashes_family_algorithm_version_index',
            );
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE perceptual_hashes ADD CONSTRAINT perceptual_hashes_value_check CHECK (hash_value ~ '^[0-9a-f]{16}$')");
        DB::unprepared('ALTER TABLE perceptual_hashes ENABLE ROW LEVEL SECURITY; ALTER TABLE perceptual_hashes FORCE ROW LEVEL SECURITY; CREATE POLICY perceptual_hashes_tenant_isolation ON perceptual_hashes USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id());');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION app_due_perceptual_hashes(p_algorithm text, p_processing_version integer)
RETURNS TABLE (
    media_upload_id char(26),
    family_space_id char(26),
    actor_user_id bigint,
    canonical_sha256 char(64)
)
LANGUAGE sql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
    SELECT
        media_uploads.id,
        media_uploads.family_space_id,
        COALESCE(
            media_uploads.user_id,
            (
                SELECT family_space_memberships.user_id
                FROM family_space_memberships
                WHERE family_space_memberships.family_space_id = media_uploads.family_space_id
                  AND family_space_memberships.role = 'owner'
                  AND family_space_memberships.state = 'active'
                ORDER BY family_space_memberships.created_at, family_space_memberships.id
                LIMIT 1
            )
        ),
        media_uploads.canonical_sha256
    FROM photos
    JOIN media_uploads ON media_uploads.id = photos.media_upload_id
    LEFT JOIN perceptual_hashes
      ON perceptual_hashes.media_upload_id = media_uploads.id
     AND perceptual_hashes.algorithm = p_algorithm
     AND perceptual_hashes.processing_version = p_processing_version
    WHERE photos.deleted_at IS NULL
      AND media_uploads.state = 'ready'
      AND media_uploads.canonical_object_key IS NOT NULL
      AND media_uploads.canonical_sha256 IS NOT NULL
      AND perceptual_hashes.id IS NULL
      AND COALESCE(
          media_uploads.user_id,
          (
              SELECT family_space_memberships.user_id
              FROM family_space_memberships
              WHERE family_space_memberships.family_space_id = media_uploads.family_space_id
                AND family_space_memberships.role = 'owner'
                AND family_space_memberships.state = 'active'
              ORDER BY family_space_memberships.created_at, family_space_memberships.id
              LIMIT 1
          )
      ) IS NOT NULL
    ORDER BY media_uploads.id
$$;

REVOKE ALL ON FUNCTION app_due_perceptual_hashes(text, integer) FROM PUBLIC;
SQL);

        DB::unprepared(sprintf(
            'GRANT EXECUTE ON FUNCTION app_due_perceptual_hashes(text, integer) TO %s;',
            $this->runtimeRole(),
        ));
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS app_due_perceptual_hashes(text, integer);');
        }

        Schema::dropIfExists('perceptual_hashes');
    }

    private function runtimeRole(): string
    {
        $role = (string) config('database.runtime_role');
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $role)) {
            throw new RuntimeException('DB_RUNTIME_USERNAME must be a simple PostgreSQL role identifier.');
        }

        return '"'.$role.'"';
    }
};
