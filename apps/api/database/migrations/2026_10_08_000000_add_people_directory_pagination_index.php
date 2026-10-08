<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX people_directory_name_cursor_idx ON people (family_space_id, LOWER(TRIM(preferred_name)), id) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS people_directory_name_cursor_idx');
        }
    }
};
