<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_comments', function (Blueprint $table): void {
            $table->char('parent_comment_id', 26)->nullable()->after('album_id');
            $table->unique(
                ['id', 'family_space_id', 'photo_id', 'album_id'],
                'photo_comments_thread_context_unique',
            );
            $table->foreign(
                ['parent_comment_id', 'family_space_id', 'photo_id', 'album_id'],
                'photo_comments_thread_parent_fk',
            )->references(['id', 'family_space_id', 'photo_id', 'album_id'])
                ->on('photo_comments')
                ->cascadeOnDelete();
            $table->index(
                ['family_space_id', 'photo_id', 'album_id', 'parent_comment_id', 'created_at'],
                'photo_comments_thread_index',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
ALTER TABLE photo_comments
ADD CONSTRAINT photo_comments_reply_shape_check
CHECK (parent_comment_id IS NULL
    OR (parent_comment_id <> id AND album_id IS NOT NULL))
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE photo_comments DROP CONSTRAINT photo_comments_reply_shape_check');
        }

        Schema::table('photo_comments', function (Blueprint $table): void {
            $table->dropIndex('photo_comments_thread_index');
            $table->dropForeign('photo_comments_thread_parent_fk');
            $table->dropUnique('photo_comments_thread_context_unique');
            $table->dropColumn('parent_comment_id');
        });
    }
};
