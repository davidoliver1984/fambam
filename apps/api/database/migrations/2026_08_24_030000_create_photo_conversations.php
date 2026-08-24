<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['photo_stories', 'photo_comments'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->char('id', 26)->primary();
                $table->char('family_space_id', 26);
                $table->char('photo_id', 26);
                $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('body');
                $table->timestamp('edited_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
                $table->foreign('photo_id')->references('id')->on('photos')->cascadeOnDelete();
                $table->index(['family_space_id', 'photo_id', 'created_at']);
            });
        }
        foreach (['photo_story_revisions' => ['photo_story_id', 'photo_stories'], 'photo_comment_revisions' => ['photo_comment_id', 'photo_comments']] as $tableName => [$parent, $parentTable]) {
            Schema::create($tableName, function (Blueprint $table) use ($parent, $parentTable): void {
                $table->char('id', 26)->primary();
                $table->char('family_space_id', 26);
                $table->char($parent, 26);
                $table->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('revision');
                $table->text('body');
                $table->timestamp('created_at')->useCurrent();
                $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
                $table->foreign($parent)->references('id')->on($parentTable)->cascadeOnDelete();
                $table->unique([$parent, 'revision']);
            });
        }
        Schema::create('photo_reactions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('photo_id', 26);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reaction', 20);
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('photo_id')->references('id')->on('photos')->cascadeOnDelete();
            $table->unique(['photo_id', 'user_id']);
            $table->index(['family_space_id', 'photo_id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE photo_reactions ADD CONSTRAINT photo_reactions_vocabulary_check CHECK (reaction IN ('love', 'smile', 'laugh', 'remember'))");
            foreach (['photo_stories', 'photo_story_revisions', 'photo_comments', 'photo_comment_revisions', 'photo_reactions'] as $table) {
                DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY; ALTER TABLE {$table} FORCE ROW LEVEL SECURITY; CREATE POLICY {$table}_tenant_isolation ON {$table} USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id());");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_reactions');
        Schema::dropIfExists('photo_comment_revisions');
        Schema::dropIfExists('photo_story_revisions');
        Schema::dropIfExists('photo_comments');
        Schema::dropIfExists('photo_stories');
    }
};
