<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_uploads', function (Blueprint $table): void {
            $table->timestamp('staging_deleted_at')->nullable()->after('staging_object_key');
            $table->index(['state', 'created_at'], 'media_uploads_abandoned_discovery_index');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $runtimeRole = $this->runtimeRole();
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION app_due_abandoned_media_uploads(p_cutoff timestamp with time zone)
RETURNS TABLE (media_upload_id char(26), family_space_id char(26), actor_user_id bigint)
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
        )
    FROM media_uploads
    WHERE media_uploads.staging_deleted_at IS NULL
      AND (
          (media_uploads.state = 'initiated' AND media_uploads.created_at <= p_cutoff)
          OR media_uploads.state = 'abandoned'
      )
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
$$;

REVOKE ALL ON FUNCTION app_due_abandoned_media_uploads(timestamp with time zone) FROM PUBLIC;
SQL);
        DB::unprepared(<<<SQL
GRANT EXECUTE ON FUNCTION app_due_abandoned_media_uploads(timestamp with time zone) TO {$runtimeRole};
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS app_due_abandoned_media_uploads(timestamp with time zone);');
        }

        Schema::table('media_uploads', function (Blueprint $table): void {
            $table->dropIndex('media_uploads_abandoned_discovery_index');
            $table->dropColumn('staging_deleted_at');
        });
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
