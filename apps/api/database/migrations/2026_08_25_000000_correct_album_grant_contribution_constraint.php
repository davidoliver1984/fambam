<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE album_grants DROP CONSTRAINT IF EXISTS album_grants_contribution_implies_view');
        DB::statement(<<<'SQL'
ALTER TABLE album_grants ADD CONSTRAINT album_grants_contribution_implies_view
CHECK (NOT can_contribute OR can_view)
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE album_grants DROP CONSTRAINT IF EXISTS album_grants_contribution_implies_view');
        DB::statement(<<<'SQL'
ALTER TABLE album_grants ADD CONSTRAINT album_grants_contribution_implies_view
CHECK (can_view = true AND (can_contribute = false OR can_view = true))
SQL);
    }
};
