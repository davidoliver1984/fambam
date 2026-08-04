<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX invitations_one_pending_per_family_email
ON invitations (family_space_id, email)
WHERE status = 'pending'
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS invitations_one_pending_per_family_email');
    }
};
