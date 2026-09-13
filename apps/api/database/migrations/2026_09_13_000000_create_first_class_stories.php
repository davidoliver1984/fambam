<?php

use App\Stories\LegacyStoryBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stories', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            foreach (['person_id', 'album_id', 'event_id', 'photo_id'] as $column) {
                $table->char($column, 26)->nullable();
            }
            $table->json('body');
            $table->text('body_plain_text');
            $table->timestamp('edited_at')->nullable();
            $table->char('deletion_operation_id', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            foreach (['person' => 'people', 'album' => 'albums', 'event' => 'events', 'photo' => 'photos'] as $column => $parent) {
                $table->foreign(["{$column}_id", 'family_space_id'], "stories_{$column}_family_foreign")
                    ->references(['id', 'family_space_id'])->on($parent)->cascadeOnDelete();
            }
            $table->unique(['id', 'family_space_id'], 'stories_id_family_space_unique');
            $table->index(['family_space_id', 'created_at', 'id']);
        });

        Schema::create('story_revisions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('story_id', 26);
            $table->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision');
            $table->json('body');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign(['story_id', 'family_space_id'], 'story_revisions_story_family_foreign')
                ->references(['id', 'family_space_id'])->on('stories')->cascadeOnDelete();
            $table->unique(['story_id', 'revision']);
        });

        Schema::create('story_comments', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('story_id', 26);
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('body');
            $table->char('deleted_with_story_operation_id', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign(['story_id', 'family_space_id'], 'story_comments_story_family_foreign')
                ->references(['id', 'family_space_id'])->on('stories')->cascadeOnDelete();
            $table->unique(['id', 'family_space_id'], 'story_comments_id_family_space_unique');
            $table->index(['family_space_id', 'story_id', 'created_at']);
        });

        $this->createMentionTable('story_person_mentions', 'story_id', 'stories');
        $this->createMentionTable('story_comment_person_mentions', 'story_comment_id', 'story_comments');

        app(LegacyStoryBackfill::class)->run();

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
ALTER TABLE stories ADD CONSTRAINT stories_exactly_one_subject_check CHECK (
    num_nonnulls(person_id, album_id, event_id, photo_id) = 1
);
ALTER TABLE stories ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
    to_tsvector('english'::regconfig, body_plain_text)
) STORED;
CREATE INDEX stories_search_vector_gin ON stories USING gin (search_vector);
ALTER TABLE stories ENABLE ROW LEVEL SECURITY;
ALTER TABLE stories FORCE ROW LEVEL SECURITY;
CREATE POLICY stories_tenant_isolation ON stories USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id());
ALTER TABLE story_revisions ENABLE ROW LEVEL SECURITY;
ALTER TABLE story_revisions FORCE ROW LEVEL SECURITY;
CREATE POLICY story_revisions_tenant_isolation ON story_revisions USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id());
ALTER TABLE story_comments ENABLE ROW LEVEL SECURITY;
ALTER TABLE story_comments FORCE ROW LEVEL SECURITY;
CREATE POLICY story_comments_tenant_isolation ON story_comments USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id());
ALTER TABLE story_person_mentions ENABLE ROW LEVEL SECURITY;
ALTER TABLE story_person_mentions FORCE ROW LEVEL SECURITY;
CREATE POLICY story_person_mentions_tenant_isolation ON story_person_mentions USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id());
ALTER TABLE story_comment_person_mentions ENABLE ROW LEVEL SECURITY;
ALTER TABLE story_comment_person_mentions FORCE ROW LEVEL SECURITY;
CREATE POLICY story_comment_person_mentions_tenant_isolation ON story_comment_person_mentions USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
        } else {
            Schema::table('stories', fn (Blueprint $table) => $table->text('search_vector')->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('story_comment_person_mentions');
        Schema::dropIfExists('story_person_mentions');
        Schema::dropIfExists('story_comments');
        Schema::dropIfExists('story_revisions');
        Schema::dropIfExists('stories');
    }

    private function createMentionTable(string $tableName, string $ownerColumn, string $ownerTable): void
    {
        Schema::create($tableName, function (Blueprint $table) use ($tableName, $ownerColumn, $ownerTable): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char($ownerColumn, 26);
            $table->char('mention_id', 26);
            $table->char('person_id', 26);
            $table->text('historical_label_snapshot');
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign([$ownerColumn, 'family_space_id'], "{$tableName}_owner_family_foreign")
                ->references(['id', 'family_space_id'])->on($ownerTable)->cascadeOnDelete();
            $table->foreign(['person_id', 'family_space_id'], "{$tableName}_person_family_foreign")
                ->references(['id', 'family_space_id'])->on('people')->cascadeOnDelete();
            $table->unique([$ownerColumn, 'mention_id'], "{$tableName}_owner_mention_unique");
            $table->index(['family_space_id', 'person_id']);
        });
    }
};
