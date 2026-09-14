<?php

use App\Stories\RichTextDocument;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP INDEX IF EXISTS people_search_vector_gin; DROP INDEX IF EXISTS albums_search_vector_gin; DROP INDEX IF EXISTS events_search_vector_gin; ALTER TABLE people DROP COLUMN search_vector; ALTER TABLE albums DROP COLUMN search_vector; ALTER TABLE events DROP COLUMN search_vector;');
        } else {
            foreach (['people', 'albums', 'events'] as $table) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('search_vector'));
            }
        }

        Schema::table('people', function (Blueprint $table): void {
            $table->json('biography_document')->nullable();
            $table->text('biography_plain_text')->nullable();
        });
        foreach (['albums', 'events'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->json('description_document')->nullable();
                $table->text('description_plain_text')->nullable();
            });
        }
        Schema::table('photo_comments', function (Blueprint $table): void {
            $table->json('body_document')->nullable();
            $table->text('body_plain_text')->nullable();
        });
        Schema::table('photo_comment_revisions', fn (Blueprint $table) => $table->json('body_document')->nullable());

        $documents = new RichTextDocument;
        $families = DB::table('family_spaces')->pluck('id');
        foreach ($families as $familyId) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("SELECT set_config('app.current_family_space_id', ?, true)", [$familyId]);
            }
            foreach ([['people', 'biography', 'biography_document', 'biography_plain_text'],
                ['albums', 'description', 'description_document', 'description_plain_text'],
                ['events', 'description', 'description_document', 'description_plain_text'],
                ['photo_comments', 'body', 'body_document', 'body_plain_text']] as [$table, $source, $target, $plain]) {
                DB::table($table)->where('family_space_id', $familyId)->orderBy('id')->each(function ($row) use ($documents, $table, $source, $target, $plain): void {
                    if ($row->{$source} === null) {
                        return;
                    }
                    $document = $documents->fromPlainText((string) $row->{$source});
                    DB::table($table)->where('id', $row->id)->update([
                        $target => json_encode($document, JSON_THROW_ON_ERROR),
                        $plain => $documents->plainText($document),
                    ]);
                });
            }
            DB::table('photo_comment_revisions')->where('family_space_id', $familyId)->orderBy('id')->each(function ($row) use ($documents): void {
                DB::table('photo_comment_revisions')->where('id', $row->id)->update([
                    'body_document' => json_encode($documents->fromPlainText((string) $row->body), JSON_THROW_ON_ERROR),
                ]);
            });
        }

        Schema::table('people', function (Blueprint $table): void {
            $table->dropColumn('biography');
            $table->renameColumn('biography_document', 'biography');
        });
        foreach (['albums', 'events'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('description');
                $table->renameColumn('description_document', 'description');
            });
        }
        Schema::table('photo_comments', function (Blueprint $table): void {
            $table->dropColumn('body');
            $table->renameColumn('body_document', 'body');
        });
        Schema::table('photo_comment_revisions', function (Blueprint $table): void {
            $table->dropColumn('body');
            $table->renameColumn('body_document', 'body');
        });
        Schema::table('photo_comments', fn (Blueprint $table) => $table->json('body')->nullable(false)->change());
        Schema::table('photo_comment_revisions', fn (Blueprint $table) => $table->json('body')->nullable(false)->change());

        $this->createMentionTable('person_biography_mentions', 'biography_person_id', 'people');
        $this->createMentionTable('album_description_mentions', 'album_id', 'albums');
        $this->createMentionTable('event_description_mentions', 'event_id', 'events');
        $this->createMentionTable('photo_comment_person_mentions', 'photo_comment_id', 'photo_comments');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
ALTER TABLE people ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('simple'::regconfig, coalesce(preferred_name, '') || ' ' || coalesce(alternate_names::text, '') || ' ' || coalesce(biography_plain_text, ''))) STORED;
ALTER TABLE albums ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('simple'::regconfig, coalesce(name, '') || ' ' || coalesce(description_plain_text, ''))) STORED;
ALTER TABLE events ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('simple'::regconfig, coalesce(name, '') || ' ' || coalesce(description_plain_text, '') || ' ' || coalesce(location, ''))) STORED;
CREATE INDEX people_search_vector_gin ON people USING gin (search_vector);
CREATE INDEX albums_search_vector_gin ON albums USING gin (search_vector);
CREATE INDEX events_search_vector_gin ON events USING gin (search_vector);
ALTER TABLE person_biography_mentions ENABLE ROW LEVEL SECURITY; ALTER TABLE person_biography_mentions FORCE ROW LEVEL SECURITY; CREATE POLICY person_biography_mentions_tenant_isolation ON person_biography_mentions USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id());
ALTER TABLE album_description_mentions ENABLE ROW LEVEL SECURITY; ALTER TABLE album_description_mentions FORCE ROW LEVEL SECURITY; CREATE POLICY album_description_mentions_tenant_isolation ON album_description_mentions USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id());
ALTER TABLE event_description_mentions ENABLE ROW LEVEL SECURITY; ALTER TABLE event_description_mentions FORCE ROW LEVEL SECURITY; CREATE POLICY event_description_mentions_tenant_isolation ON event_description_mentions USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id());
ALTER TABLE photo_comment_person_mentions ENABLE ROW LEVEL SECURITY; ALTER TABLE photo_comment_person_mentions FORCE ROW LEVEL SECURITY; CREATE POLICY photo_comment_person_mentions_tenant_isolation ON photo_comment_person_mentions USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
        } else {
            foreach (['people', 'albums', 'events'] as $table) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->text('search_vector')->nullable());
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('The rich-text surface migration is intentionally irreversible; restore a pre-migration backup.');
    }

    private function createMentionTable(string $name, string $ownerColumn, string $ownerTable): void
    {
        Schema::create($name, function (Blueprint $table) use ($name, $ownerColumn, $ownerTable): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char($ownerColumn, 26);
            $table->char('mention_id', 26);
            $table->char('person_id', 26);
            $table->text('historical_label_snapshot');
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign([$ownerColumn, 'family_space_id'], "{$name}_owner_family_foreign")
                ->references(['id', 'family_space_id'])->on($ownerTable)->cascadeOnDelete();
            $table->foreign(['person_id', 'family_space_id'], "{$name}_person_family_foreign")
                ->references(['id', 'family_space_id'])->on('people')->cascadeOnDelete();
            $table->unique([$ownerColumn, 'mention_id'], "{$name}_owner_mention_unique");
            $table->index(['family_space_id', 'person_id']);
        });
    }
};
