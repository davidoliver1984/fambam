<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_exports', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('scope', 24);
            $table->string('state', 20)->default('pending');
            $table->string('object_key');
            $table->char('archive_sha256', 64)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->unsignedInteger('photo_count')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->unique(['id', 'family_space_id'], 'family_exports_id_family_space_unique');
            $table->index(['family_space_id', 'requested_by', 'created_at']);
            $table->index(['state', 'expires_at']);
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->char('family_export_id', 26)->nullable();
            $table->foreign(['family_export_id', 'family_space_id'], 'notifications_family_export_family_foreign')
                ->references(['id', 'family_space_id'])->on('family_exports')->cascadeOnDelete();
        });
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->char('family_export_id', 26)->nullable();
            $table->foreign(['family_export_id', 'family_space_id'], 'notification_deliveries_family_export_family_foreign')
                ->references(['id', 'family_space_id'])->on('family_exports')->cascadeOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $runtimeRole = $this->runtimeRole();
        DB::unprepared(<<<'SQL'
ALTER TABLE family_exports ADD CONSTRAINT family_exports_scope_check
CHECK (scope IN ('family_space_full', 'personal'));
ALTER TABLE family_exports ADD CONSTRAINT family_exports_state_check
CHECK (state IN ('pending', 'processing', 'ready', 'failed', 'expired'));
ALTER TABLE family_exports ENABLE ROW LEVEL SECURITY;
ALTER TABLE family_exports FORCE ROW LEVEL SECURITY;
CREATE POLICY family_exports_tenant_isolation ON family_exports
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());

ALTER TABLE notifications DROP CONSTRAINT notifications_subject_check;
ALTER TABLE notifications ADD CONSTRAINT notifications_subject_check CHECK (
 (category = 'comment' AND photo_id IS NOT NULL AND album_id IS NOT NULL AND comment_id IS NOT NULL AND story_id IS NULL AND person_id IS NULL AND family_export_id IS NULL)
 OR (category = 'contribution' AND album_id IS NOT NULL AND photo_id IS NULL AND story_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)
 OR (category = 'story' AND story_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)
 OR (category = 'identity' AND person_id IS NOT NULL AND photo_id IS NOT NULL AND album_id IS NULL AND story_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)
 OR (category = 'export' AND family_export_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND story_id IS NULL AND person_id IS NULL AND comment_id IS NULL)
);
ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_subject_check;
ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_subject_check CHECK (
 (category = 'comment' AND photo_id IS NOT NULL AND album_id IS NOT NULL AND comment_id IS NOT NULL AND story_id IS NULL AND person_id IS NULL AND family_export_id IS NULL)
 OR (category = 'contribution' AND album_id IS NOT NULL AND photo_id IS NULL AND story_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)
 OR (category = 'story' AND story_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)
 OR (category = 'identity' AND person_id IS NOT NULL AND photo_id IS NOT NULL AND album_id IS NULL AND story_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)
 OR (category = 'export' AND family_export_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND story_id IS NULL AND person_id IS NULL AND comment_id IS NULL)
);

CREATE OR REPLACE FUNCTION app_due_family_exports()
RETURNS TABLE (family_export_id char(26), family_space_id char(26), actor_user_id bigint)
LANGUAGE sql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
    SELECT family_exports.id, family_exports.family_space_id, family_exports.requested_by
    FROM family_exports
    WHERE family_exports.state = 'ready'
      AND family_exports.expires_at <= CURRENT_TIMESTAMP
$$;

REVOKE ALL ON FUNCTION app_due_family_exports() FROM PUBLIC;
SQL);
        DB::unprepared("GRANT EXECUTE ON FUNCTION app_due_family_exports() TO {$runtimeRole};");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS app_due_family_exports();');
            DB::unprepared(<<<'SQL'
ALTER TABLE notifications DROP CONSTRAINT notifications_subject_check;
ALTER TABLE notifications ADD CONSTRAINT notifications_subject_check CHECK (
 (category = 'comment' AND photo_id IS NOT NULL AND album_id IS NOT NULL AND comment_id IS NOT NULL AND story_id IS NULL AND person_id IS NULL)
 OR (category = 'contribution' AND album_id IS NOT NULL AND photo_id IS NULL AND story_id IS NULL AND person_id IS NULL AND comment_id IS NULL)
 OR (category = 'story' AND story_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND person_id IS NULL AND comment_id IS NULL)
 OR (category = 'identity' AND person_id IS NOT NULL AND photo_id IS NOT NULL AND album_id IS NULL AND story_id IS NULL AND comment_id IS NULL)
);
ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_subject_check;
ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_subject_check CHECK (
 (category = 'comment' AND photo_id IS NOT NULL AND album_id IS NOT NULL AND comment_id IS NOT NULL AND story_id IS NULL AND person_id IS NULL)
 OR (category = 'contribution' AND album_id IS NOT NULL AND photo_id IS NULL AND story_id IS NULL AND person_id IS NULL AND comment_id IS NULL)
 OR (category = 'story' AND story_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND person_id IS NULL AND comment_id IS NULL)
 OR (category = 'identity' AND person_id IS NOT NULL AND photo_id IS NOT NULL AND album_id IS NULL AND story_id IS NULL AND comment_id IS NULL)
);
SQL);
        }
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropForeign('notification_deliveries_family_export_family_foreign');
            $table->dropColumn('family_export_id');
        });
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropForeign('notifications_family_export_family_foreign');
            $table->dropColumn('family_export_id');
        });
        Schema::dropIfExists('family_exports');
    }

    private function runtimeRole(): string
    {
        $role = (string) config('database.runtime_role');
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $role)) {
            throw new RuntimeException('The configured database runtime role is invalid.');
        }

        return $role;
    }
};
