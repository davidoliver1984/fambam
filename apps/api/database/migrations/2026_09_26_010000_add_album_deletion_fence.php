<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table): void {
            $table->timestamp('deleting_at')->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE family_exports DROP CONSTRAINT family_exports_curated_target_check;
ALTER TABLE family_exports ADD CONSTRAINT family_exports_curated_target_check
CHECK ((scope = 'collection' AND album_id IS NULL
        AND (collection_id IS NOT NULL OR cancelled_at IS NOT NULL))
    OR (scope = 'album' AND collection_id IS NULL
        AND (album_id IS NOT NULL OR cancelled_at IS NOT NULL))
    OR (scope IN ('family_space_full', 'personal') AND album_id IS NULL AND collection_id IS NULL));
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
ALTER TABLE family_exports DROP CONSTRAINT family_exports_curated_target_check;
ALTER TABLE family_exports ADD CONSTRAINT family_exports_curated_target_check
CHECK ((scope = 'collection' AND album_id IS NULL
        AND (collection_id IS NOT NULL OR cancelled_at IS NOT NULL))
    OR (scope = 'album' AND album_id IS NOT NULL AND collection_id IS NULL)
    OR (scope IN ('family_space_full', 'personal') AND album_id IS NULL AND collection_id IS NULL));
SQL);
        }

        Schema::table('albums', function (Blueprint $table): void {
            $table->dropColumn('deleting_at');
        });
    }
};
