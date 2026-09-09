<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', fn (Blueprint $table) => $table->unique(['id', 'family_space_id'], 'albums_id_family_space_unique'));
        Schema::table('events', fn (Blueprint $table) => $table->unique(['id', 'family_space_id'], 'events_id_family_space_unique'));
        Schema::table('photos', fn (Blueprint $table) => $table->unique(['id', 'family_space_id'], 'photos_id_family_space_unique'));
        Schema::table('photo_stories', fn (Blueprint $table) => $table->unique(['id', 'family_space_id'], 'photo_stories_id_family_space_unique'));

        Schema::create('family_activities', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->char('actor_person_id', 26)->nullable();
            $table->string('action_type', 40);
            $table->char('subject_album_id', 26)->nullable();
            $table->char('subject_event_id', 26)->nullable();
            $table->char('subject_story_id', 26)->nullable();
            $table->char('subject_person_id', 26)->nullable();
            $table->char('contribution_batch_id', 26)->nullable();
            $table->json('photo_ids')->nullable();
            $table->timestamp('created_at');

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign(['actor_person_id', 'family_space_id'], 'family_activities_actor_person_family_foreign')
                ->references(['id', 'family_space_id'])->on('people')->restrictOnDelete();
            $table->foreign(['subject_album_id', 'family_space_id'], 'family_activities_album_family_foreign')
                ->references(['id', 'family_space_id'])->on('albums')->cascadeOnDelete();
            $table->foreign(['subject_event_id', 'family_space_id'], 'family_activities_event_family_foreign')
                ->references(['id', 'family_space_id'])->on('events')->cascadeOnDelete();
            $table->foreign(['subject_story_id', 'family_space_id'], 'family_activities_story_family_foreign')
                ->references(['id', 'family_space_id'])->on('photo_stories')->cascadeOnDelete();
            $table->foreign(['subject_person_id', 'family_space_id'], 'family_activities_subject_person_family_foreign')
                ->references(['id', 'family_space_id'])->on('people')->cascadeOnDelete();
            $table->unique(['id', 'family_space_id'], 'family_activities_id_family_space_unique');
            $table->index(['family_space_id', 'created_at', 'id'], 'family_activities_recent_index');
            $table->index(
                ['family_space_id', 'actor_user_id', 'action_type', 'subject_album_id', 'contribution_batch_id'],
                'family_activities_grouping_index',
            );
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE family_activities ADD CONSTRAINT family_activities_subject_check CHECK (
    (action_type IN ('photos_added_to_album', 'album_created') AND subject_album_id IS NOT NULL AND subject_event_id IS NULL AND subject_story_id IS NULL AND subject_person_id IS NULL)
 OR (action_type = 'event_created' AND subject_album_id IS NULL AND subject_event_id IS NOT NULL AND subject_story_id IS NULL AND subject_person_id IS NULL)
 OR (action_type = 'story_added' AND subject_album_id IS NULL AND subject_event_id IS NULL AND subject_story_id IS NOT NULL AND subject_person_id IS NULL)
 OR (action_type = 'person_identity_confirmed' AND subject_album_id IS NULL AND subject_event_id IS NULL AND subject_story_id IS NULL AND subject_person_id IS NOT NULL)
);
ALTER TABLE family_activities ENABLE ROW LEVEL SECURITY;
ALTER TABLE family_activities FORCE ROW LEVEL SECURITY;
CREATE POLICY family_activities_tenant_isolation ON family_activities
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('family_activities');
        Schema::table('photo_stories', fn (Blueprint $table) => $table->dropUnique('photo_stories_id_family_space_unique'));
        Schema::table('photos', fn (Blueprint $table) => $table->dropUnique('photos_id_family_space_unique'));
        Schema::table('events', fn (Blueprint $table) => $table->dropUnique('events_id_family_space_unique'));
        Schema::table('albums', fn (Blueprint $table) => $table->dropUnique('albums_id_family_space_unique'));
    }
};
