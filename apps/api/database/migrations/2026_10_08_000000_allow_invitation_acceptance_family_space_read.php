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

        DB::unprepared(<<<'SQL'
CREATE POLICY family_spaces_visible_during_invitation_acceptance ON family_spaces
FOR SELECT
USING (
    id = app_current_family_space_id()
    AND app_authoritative_tenant_operation() = 'invitation_acceptance'
);
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP POLICY IF EXISTS family_spaces_visible_during_invitation_acceptance ON family_spaces');
    }
};
