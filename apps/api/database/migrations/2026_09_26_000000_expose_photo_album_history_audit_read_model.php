<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION app_photo_album_history_events(
    requested_family_space_id text,
    requested_photo_id text
)
RETURNS TABLE (
    event_id bigint,
    actor_user_id bigint,
    event_type text,
    album_id text,
    created_at timestamp without time zone
)
LANGUAGE sql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
    SELECT
        audit.id,
        audit.actor_user_id,
        CASE audit.action
            WHEN 'album.photo_added' THEN 'added'
            ELSE 'removed'
        END,
        identity.album_id,
        audit.created_at
    FROM audit_events AS audit
    LEFT JOIN album_photos AS current_link
      ON current_link.id::text = audit.subject_id
     AND current_link.family_space_id = audit.family_space_id
    LEFT JOIN LATERAL (
        SELECT
            COALESCE(
                NULLIF(audit.metadata::jsonb ->> 'album_id', ''),
                NULLIF(related.metadata::jsonb ->> 'album_id', ''),
                current_link.album_id::text
            ) AS album_id,
            COALESCE(
                NULLIF(audit.metadata::jsonb ->> 'photo_id', ''),
                NULLIF(related.metadata::jsonb ->> 'photo_id', ''),
                current_link.photo_id::text
            ) AS photo_id
        FROM (SELECT 1) AS singleton
        LEFT JOIN LATERAL (
            SELECT candidate.metadata
            FROM audit_events AS candidate
            WHERE candidate.family_space_id = audit.family_space_id
              AND candidate.subject_type = audit.subject_type
              AND candidate.subject_id = audit.subject_id
              AND candidate.action IN ('album.photo_added', 'album.photo_removed')
              AND candidate.metadata::jsonb ? 'album_id'
              AND candidate.metadata::jsonb ? 'photo_id'
            ORDER BY candidate.id
            LIMIT 1
        ) AS related ON true
    ) AS identity ON true
    WHERE app_current_user_id() IS NOT NULL
      AND app_current_family_space_id()::text = requested_family_space_id
      AND audit.family_space_id::text = requested_family_space_id
      AND audit.subject_type = 'App\Models\AlbumPhoto'
      AND audit.action IN ('album.photo_added', 'album.photo_removed')
      AND identity.photo_id = requested_photo_id
      AND identity.album_id IS NOT NULL
    ORDER BY audit.created_at DESC, audit.id DESC
$$;

REVOKE ALL ON FUNCTION app_photo_album_history_events(text, text) FROM PUBLIC;
SQL);

        DB::statement('GRANT EXECUTE ON FUNCTION app_photo_album_history_events(text, text) TO '.$this->runtimeRole());
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS app_photo_album_history_events(text, text)');
        }
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
