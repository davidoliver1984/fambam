<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_comments', fn (Blueprint $table) => $table->unique(['id', 'family_space_id'], 'photo_comments_id_family_space_unique'));
        Schema::create('contribution_groups', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->char('upload_batch_id', 26);
            $table->char('album_id', 26);
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign(['album_id', 'family_space_id'])->references(['id', 'family_space_id'])->on('albums')->cascadeOnDelete();
            $table->unique(['family_space_id', 'actor_user_id', 'upload_batch_id', 'album_id'], 'contribution_groups_natural_unique');
        });
        Schema::create('notification_candidates', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('category', 24);
            $table->char('source_action_id', 26);
            $table->timestamp('evaluated_at')->nullable();
            $table->string('in_app_outcome', 32)->default('pending');
            $table->string('email_outcome', 32)->default('pending');
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->unique(['family_space_id', 'recipient_user_id', 'category', 'source_action_id'], 'notification_candidates_natural_unique');
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('category', 24);
            $table->char('source_action_id', 26);
            foreach (['photo_id', 'album_id', 'story_id', 'person_id', 'comment_id'] as $column) {
                $table->char($column, 26)->nullable();
            } $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            foreach (['photo' => 'photos', 'album' => 'albums', 'story' => 'photo_stories', 'person' => 'people', 'comment' => 'photo_comments'] as $column => $parent) {
                $table->foreign(["{$column}_id", 'family_space_id'], "notifications_{$column}_family_foreign")->references(['id', 'family_space_id'])->on($parent)->cascadeOnDelete();
            }
            $table->unique(['family_space_id', 'recipient_user_id', 'category', 'source_action_id'], 'notifications_natural_unique');
        });
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('category', 24);
            $table->char('source_action_id', 26);
            $table->char('notification_id', 26)->nullable();
            foreach (['photo_id', 'album_id', 'story_id', 'person_id', 'comment_id'] as $column) {
                $table->char($column, 26)->nullable();
            }
            $table->string('channel', 16);
            $table->string('status', 16)->default('pending');
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('notification_id')->references('id')->on('notifications')->nullOnDelete();
            foreach (['photo' => 'photos', 'album' => 'albums', 'story' => 'photo_stories', 'person' => 'people', 'comment' => 'photo_comments'] as $column => $parent) {
                $table->foreign(["{$column}_id", 'family_space_id'], "notification_deliveries_{$column}_family_foreign")->references(['id', 'family_space_id'])->on($parent)->cascadeOnDelete();
            }
            $table->unique(['family_space_id', 'recipient_user_id', 'category', 'source_action_id', 'channel'], 'notification_deliveries_natural_unique');
        });
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('category', 24);
            $table->string('channel', 16);
            $table->boolean('enabled');
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->unique(['family_space_id', 'user_id', 'category', 'channel'], 'notification_preferences_natural_unique');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE contribution_groups ENABLE ROW LEVEL SECURITY; ALTER TABLE contribution_groups FORCE ROW LEVEL SECURITY; CREATE POLICY contribution_groups_tenant_isolation ON contribution_groups USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id()); ALTER TABLE notification_candidates ENABLE ROW LEVEL SECURITY; ALTER TABLE notification_candidates FORCE ROW LEVEL SECURITY; CREATE POLICY notification_candidates_tenant_isolation ON notification_candidates USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id()); ALTER TABLE notifications ENABLE ROW LEVEL SECURITY; ALTER TABLE notifications FORCE ROW LEVEL SECURITY; CREATE POLICY notifications_tenant_isolation ON notifications USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id()); ALTER TABLE notification_deliveries ENABLE ROW LEVEL SECURITY; ALTER TABLE notification_deliveries FORCE ROW LEVEL SECURITY; CREATE POLICY notification_deliveries_tenant_isolation ON notification_deliveries USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id()); ALTER TABLE notification_preferences ENABLE ROW LEVEL SECURITY; ALTER TABLE notification_preferences FORCE ROW LEVEL SECURITY; CREATE POLICY notification_preferences_tenant_isolation ON notification_preferences USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id());');
            DB::unprepared(<<<'SQL'
ALTER TABLE notifications ADD CONSTRAINT notifications_subject_check CHECK (
 (category = 'comment' AND photo_id IS NOT NULL AND album_id IS NOT NULL AND comment_id IS NOT NULL AND story_id IS NULL AND person_id IS NULL)
 OR (category = 'contribution' AND album_id IS NOT NULL AND photo_id IS NULL AND story_id IS NULL AND person_id IS NULL AND comment_id IS NULL)
 OR (category = 'story' AND story_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND person_id IS NULL AND comment_id IS NULL)
 OR (category = 'identity' AND person_id IS NOT NULL AND photo_id IS NOT NULL AND album_id IS NULL AND story_id IS NULL AND comment_id IS NULL)
);
ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_subject_check CHECK (
 (category = 'comment' AND photo_id IS NOT NULL AND album_id IS NOT NULL AND comment_id IS NOT NULL AND story_id IS NULL AND person_id IS NULL)
 OR (category = 'contribution' AND album_id IS NOT NULL AND photo_id IS NULL AND story_id IS NULL AND person_id IS NULL AND comment_id IS NULL)
 OR (category = 'story' AND story_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND person_id IS NULL AND comment_id IS NULL)
 OR (category = 'identity' AND person_id IS NOT NULL AND photo_id IS NOT NULL AND album_id IS NULL AND story_id IS NULL AND comment_id IS NULL)
);
SQL);
        }
    }

    public function down(): void
    {
        foreach (['notification_deliveries', 'notifications', 'notification_candidates', 'notification_preferences', 'contribution_groups'] as $table) {
            Schema::dropIfExists($table);
        } Schema::table('photo_comments', fn (Blueprint $table) => $table->dropUnique('photo_comments_id_family_space_unique'));
    }
};
