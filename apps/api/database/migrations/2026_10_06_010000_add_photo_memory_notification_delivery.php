<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Delivery-only persistence; the canonical preference category is defined by Account Settings.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $runtimeRole = $this->runtimeRole();
        foreach (['notifications', 'notification_deliveries'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_subject_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_subject_check CHECK (".$this->notificationSubjects(true).')');
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION app_photo_memory_notification_targets()
RETURNS TABLE (family_space_id char(26), actor_user_id bigint, timezone varchar(64))
LANGUAGE sql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
    SELECT memberships.family_space_id, memberships.user_id, users.timezone
    FROM family_space_memberships AS memberships
    JOIN users ON users.id = memberships.user_id
    JOIN family_spaces ON family_spaces.id = memberships.family_space_id
    WHERE memberships.state = 'active'
      AND family_spaces.status = 'active'
      AND users.revoked_at IS NULL
$$;

REVOKE ALL ON FUNCTION app_photo_memory_notification_targets() FROM PUBLIC;
SQL);
        DB::unprepared("GRANT EXECUTE ON FUNCTION app_photo_memory_notification_targets() TO {$runtimeRole};");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP FUNCTION IF EXISTS app_photo_memory_notification_targets();');
        foreach (['notification_deliveries', 'notifications'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_subject_check");
            DB::table($table)->where('category', 'photo_memory')->delete();
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_subject_check CHECK (".$this->notificationSubjects(false).')');
        }
        DB::table('notification_candidates')->where('category', 'photo_memory')->delete();
    }

    private function notificationSubjects(bool $photoMemory): string
    {
        $branches = [
            "(category = 'comment' AND family_export_id IS NULL AND event_id IS NULL AND ((photo_id IS NOT NULL AND album_id IS NOT NULL AND comment_id IS NOT NULL AND story_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL) OR (story_id IS NOT NULL AND story_comment_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND comment_id IS NULL AND person_id IS NULL)))",
            "(category = 'contribution' AND album_id IS NOT NULL AND photo_id IS NULL AND story_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL AND event_id IS NULL)",
            "(category = 'story' AND story_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL AND event_id IS NULL)",
            "(category = 'identity' AND person_id IS NOT NULL AND photo_id IS NOT NULL AND album_id IS NULL AND story_id IS NULL AND story_comment_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL AND event_id IS NULL)",
            "(category = 'export' AND family_export_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND story_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND event_id IS NULL)",
            "(category = 'attendance' AND event_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND story_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)",
            "(category = 'love' AND num_nonnulls(photo_id, album_id, event_id, story_id) = 1 AND person_id IS NULL AND comment_id IS NULL AND story_comment_id IS NULL AND family_export_id IS NULL)",
        ];
        if ($photoMemory) {
            $branches[] = "(category = 'photo_memory' AND photo_id IS NOT NULL AND album_id IS NULL AND event_id IS NULL AND story_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND story_comment_id IS NULL AND family_export_id IS NULL)";
        }

        return implode(' OR ', $branches);
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
