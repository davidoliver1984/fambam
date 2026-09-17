<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['notifications', 'notification_deliveries'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->char('event_id', 26)->nullable();
                $table->foreign(['event_id', 'family_space_id'], $tableName.'_event_family_foreign')
                    ->references(['id', 'family_space_id'])->on('events')->cascadeOnDelete();
            });
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$tableName} DROP CONSTRAINT {$tableName}_subject_check");
                DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_subject_check CHECK (".$this->subjectCheck().')');
            }
        }
    }

    public function down(): void
    {
        foreach (['notification_deliveries', 'notifications'] as $tableName) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$tableName} DROP CONSTRAINT {$tableName}_subject_check");
            }
            DB::table($tableName)->where('category', 'attendance')->delete();
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropForeign($tableName.'_event_family_foreign');
                $table->dropColumn('event_id');
            });
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_subject_check CHECK (".$this->subjectCheck(false).')');
            }
        }
    }

    private function subjectCheck(bool $attendance = true): string
    {
        $branches = [
            "(category = 'comment' AND family_export_id IS NULL AND ((photo_id IS NOT NULL AND album_id IS NOT NULL AND comment_id IS NOT NULL AND story_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL) OR (story_id IS NOT NULL AND story_comment_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND comment_id IS NULL AND person_id IS NULL)))",
            "(category = 'contribution' AND album_id IS NOT NULL AND photo_id IS NULL AND story_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)",
            "(category = 'story' AND story_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)",
            "(category = 'identity' AND person_id IS NOT NULL AND photo_id IS NOT NULL AND album_id IS NULL AND story_id IS NULL AND story_comment_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)",
            "(category = 'export' AND family_export_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND story_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL AND comment_id IS NULL)",
        ];
        if ($attendance) {
            $branches = array_map(fn (string $branch): string => substr($branch, 0, -1).' AND event_id IS NULL)', $branches);
            $branches[] = "(category = 'attendance' AND event_id IS NOT NULL AND photo_id IS NULL AND album_id IS NULL AND story_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL AND comment_id IS NULL AND family_export_id IS NULL)";
        }

        return implode(' OR ', $branches);
    }
};
