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
CREATE OR REPLACE FUNCTION app_face_analysis_attempt_context(p_request_id char(26))
RETURNS TABLE (family_space_id char(26), actor_user_id bigint, attempt_status varchar)
LANGUAGE sql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
    SELECT
        attempts.family_space_id,
        COALESCE(
            uploads.user_id,
            (
                SELECT memberships.user_id
                FROM family_space_memberships memberships
                WHERE memberships.family_space_id = attempts.family_space_id
                  AND memberships.role = 'owner'
                  AND memberships.state = 'active'
                ORDER BY memberships.created_at, memberships.id
                LIMIT 1
            )
        ),
        attempts.status
    FROM face_analysis_attempts attempts
    JOIN face_analysis_runs runs ON runs.id = attempts.face_analysis_run_id
      AND runs.family_space_id = attempts.family_space_id
    JOIN media_uploads uploads ON uploads.id = runs.media_upload_id
      AND uploads.family_space_id = runs.family_space_id
    WHERE attempts.id = p_request_id
$$;

CREATE OR REPLACE FUNCTION app_due_face_analysis_attempts(p_cutoff timestamp with time zone)
RETURNS TABLE (attempt_id char(26), family_space_id char(26), actor_user_id bigint)
LANGUAGE sql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
    SELECT
        attempts.id,
        attempts.family_space_id,
        COALESCE(
            uploads.user_id,
            (
                SELECT memberships.user_id
                FROM family_space_memberships memberships
                WHERE memberships.family_space_id = attempts.family_space_id
                  AND memberships.role = 'owner'
                  AND memberships.state = 'active'
                ORDER BY memberships.created_at, memberships.id
                LIMIT 1
            )
        )
    FROM face_analysis_attempts attempts
    JOIN face_analysis_runs runs ON runs.id = attempts.face_analysis_run_id
      AND runs.family_space_id = attempts.family_space_id
    JOIN media_uploads uploads ON uploads.id = runs.media_upload_id
      AND uploads.family_space_id = runs.family_space_id
    WHERE attempts.status = 'dispatched'
      AND attempts.dispatched_at < p_cutoff
    ORDER BY attempts.dispatched_at, attempts.id
$$;

REVOKE ALL ON FUNCTION app_face_analysis_attempt_context(char(26)) FROM PUBLIC;
REVOKE ALL ON FUNCTION app_due_face_analysis_attempts(timestamp with time zone) FROM PUBLIC;
SQL);

        DB::unprepared(sprintf(
            'GRANT EXECUTE ON FUNCTION app_face_analysis_attempt_context(char(26)) TO %1$s; GRANT EXECUTE ON FUNCTION app_due_face_analysis_attempts(timestamp with time zone) TO %1$s;',
            $this->runtimeRole(),
        ));
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS app_face_analysis_attempt_context(char(26)); DROP FUNCTION IF EXISTS app_due_face_analysis_attempts(timestamp with time zone);');
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
