<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reactions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            foreach (['album', 'event', 'story'] as $target) {
                $table->char("{$target}_id", 26)->nullable();
            }
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reaction', 10);
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            foreach (['album' => 'albums', 'event' => 'events', 'story' => 'stories'] as $target => $parent) {
                $table->foreign(["{$target}_id", 'family_space_id'], "reactions_{$target}_family_fk")
                    ->references(['id', 'family_space_id'])->on($parent)->cascadeOnDelete();
            }
        });
        Schema::create('love_notification_groups', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            foreach (['photo', 'album', 'event', 'story'] as $target) {
                $table->char("{$target}_id", 26)->nullable();
            }
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            foreach (['photo' => 'photos', 'album' => 'albums', 'event' => 'events', 'story' => 'stories'] as $target => $parent) {
                $table->foreign(["{$target}_id", 'family_space_id'], "love_groups_{$target}_family_fk")
                    ->references(['id', 'family_space_id'])->on($parent)->cascadeOnDelete();
            }
            $table->unique(['id', 'family_space_id']);
        });
        Schema::create('love_notification_group_actors', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('group_id', 26);
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign(['group_id', 'family_space_id'], 'love_group_actors_group_family_fk')
                ->references(['id', 'family_space_id'])->on('love_notification_groups')->cascadeOnDelete();
            $table->unique(['group_id', 'actor_user_id']);
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE reactions ADD CONSTRAINT reactions_one_target CHECK (num_nonnulls(album_id, event_id, story_id) = 1);
ALTER TABLE reactions ADD CONSTRAINT reactions_love_only CHECK (reaction = 'love');
CREATE UNIQUE INDEX reactions_album_user_unique ON reactions (album_id, user_id) WHERE album_id IS NOT NULL;
CREATE UNIQUE INDEX reactions_event_user_unique ON reactions (event_id, user_id) WHERE event_id IS NOT NULL;
CREATE UNIQUE INDEX reactions_story_user_unique ON reactions (story_id, user_id) WHERE story_id IS NOT NULL;
ALTER TABLE love_notification_groups ADD CONSTRAINT love_groups_one_target CHECK (num_nonnulls(photo_id, album_id, event_id, story_id) = 1);
ALTER TABLE reactions ENABLE ROW LEVEL SECURITY;
ALTER TABLE reactions FORCE ROW LEVEL SECURITY;
CREATE POLICY reactions_tenant_isolation ON reactions USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id());
ALTER TABLE love_notification_groups ENABLE ROW LEVEL SECURITY;
ALTER TABLE love_notification_groups FORCE ROW LEVEL SECURITY;
CREATE POLICY love_groups_tenant_isolation ON love_notification_groups USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id());
ALTER TABLE love_notification_group_actors ENABLE ROW LEVEL SECURITY;
ALTER TABLE love_notification_group_actors FORCE ROW LEVEL SECURITY;
CREATE POLICY love_group_actors_tenant_isolation ON love_notification_group_actors USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
        foreach (['notifications', 'notification_deliveries'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_subject_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_subject_check CHECK (".$this->notificationSubjects(true).')');
        }
    }

    public function down(): void
    {
        foreach (['notification_deliveries', 'notifications'] as $table) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_subject_check");
            }
            DB::table($table)->where('category', 'love')->delete();
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_subject_check CHECK (".$this->notificationSubjects(false).')');
            }
        }
        DB::table('notification_candidates')->where('category', 'love')->delete();
        DB::table('notification_preferences')->where('category', 'love')->delete();
        Schema::dropIfExists('love_notification_group_actors');
        Schema::dropIfExists('love_notification_groups');
        Schema::dropIfExists('reactions');
    }

    private function notificationSubjects(bool $love): string
    {
        $branches = [
            "(category = 'comment' AND family_export_id IS NULL AND event_id IS NULL AND ((photo_id IS NOT NULL AND album_id IS NOT NULL AND comment_id IS NOT NULL AND story_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL) OR (story_id IS NOT NULL AND story_comment_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND comment_id IS NULL AND person_id IS NULL)))",
            "(category = 'contribution' AND album_id IS NOT NULL AND photo_id IS NULL AND story_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL AND event_id IS NULL)",
            "(category = 'story' AND story_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL AND event_id IS NULL)",
            "(category = 'identity' AND person_id IS NOT NULL AND photo_id IS NOT NULL AND album_id IS NULL AND story_id IS NULL AND story_comment_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL AND event_id IS NULL)",
            "(category = 'export' AND family_export_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND story_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND event_id IS NULL)",
            "(category = 'attendance' AND event_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND story_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)",
        ];
        if ($love) {
            $branches[] = "(category = 'love' AND num_nonnulls(photo_id, album_id, event_id, story_id) = 1 AND person_id IS NULL AND comment_id IS NULL AND story_comment_id IS NULL AND family_export_id IS NULL)";
        }

        return implode(' OR ', $branches);
    }
};
