<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tags', fn (Blueprint $table) => $table->unique(['id', 'family_space_id'], 'tags_id_family_unique'));

        Schema::table('albums', function (Blueprint $table): void {
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('location', 255)->nullable();
            $table->char('cover_photo_id', 26)->nullable();
            $table->decimal('cover_focal_x', 4, 3)->nullable();
            $table->decimal('cover_focal_y', 4, 3)->nullable();
            $table->char('current_cover_intent_id', 26)->nullable();
            $table->foreign(['cover_photo_id', 'family_space_id'], 'albums_cover_photo_family_fk')
                ->references(['id', 'family_space_id'])->on('photos')->restrictOnDelete();
            $table->index(['family_space_id', 'starts_on']);
        });

        Schema::create('album_tag', function (Blueprint $table): void {
            $table->char('family_space_id', 26);
            $table->char('album_id', 26);
            $table->char('tag_id', 26);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['album_id', 'tag_id']);
            $table->foreign(['album_id', 'family_space_id'], 'album_tag_album_family_fk')
                ->references(['id', 'family_space_id'])->on('albums')->cascadeOnDelete();
            $table->foreign(['tag_id', 'family_space_id'], 'album_tag_tag_family_fk')
                ->references(['id', 'family_space_id'])->on('tags')->cascadeOnDelete();
            $table->index(['family_space_id', 'tag_id']);
        });

        Schema::create('album_people', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('album_id', 26);
            $table->char('person_id', 26);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['album_id', 'person_id']);
            $table->foreign(['album_id', 'family_space_id'], 'album_people_album_family_fk')
                ->references(['id', 'family_space_id'])->on('albums')->cascadeOnDelete();
            $table->foreign(['person_id', 'family_space_id'], 'album_people_person_family_fk')
                ->references(['id', 'family_space_id'])->on('people')->cascadeOnDelete();
            $table->index(['family_space_id', 'person_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE albums ADD CONSTRAINT albums_date_range_check
CHECK (starts_on IS NULL OR ends_on IS NULL OR ends_on >= starts_on);
ALTER TABLE albums ADD CONSTRAINT albums_cover_focal_check
CHECK (
    (cover_photo_id IS NULL AND cover_focal_x IS NULL AND cover_focal_y IS NULL)
    OR (cover_photo_id IS NOT NULL AND cover_focal_x BETWEEN 0 AND 1 AND cover_focal_y BETWEEN 0 AND 1)
);
ALTER TABLE album_tag ENABLE ROW LEVEL SECURITY;
ALTER TABLE album_tag FORCE ROW LEVEL SECURITY;
CREATE POLICY album_tag_tenant_isolation ON album_tag
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
ALTER TABLE album_people ENABLE ROW LEVEL SECURITY;
ALTER TABLE album_people FORCE ROW LEVEL SECURITY;
CREATE POLICY album_people_tenant_isolation ON album_people
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('album_people');
        Schema::dropIfExists('album_tag');
        Schema::table('albums', function (Blueprint $table): void {
            $table->dropForeign('albums_cover_photo_family_fk');
            $table->dropIndex(['family_space_id', 'starts_on']);
            $table->dropColumn(['starts_on', 'ends_on', 'location', 'cover_photo_id', 'cover_focal_x', 'cover_focal_y', 'current_cover_intent_id']);
        });
        Schema::table('tags', fn (Blueprint $table) => $table->dropUnique('tags_id_family_unique'));
    }
};
