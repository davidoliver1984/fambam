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

        DB::statement('ALTER TABLE face_identity_assignments DROP CONSTRAINT face_identity_assignments_status_check');
        DB::statement("ALTER TABLE face_identity_assignments ADD CONSTRAINT face_identity_assignments_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'withdrawn', 'superseded'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("UPDATE face_identity_assignments SET status = 'withdrawn' WHERE status = 'superseded'");
        DB::statement('ALTER TABLE face_identity_assignments DROP CONSTRAINT face_identity_assignments_status_check');
        DB::statement("ALTER TABLE face_identity_assignments ADD CONSTRAINT face_identity_assignments_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'withdrawn'))");
    }
};
