<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->softDeletes());
        Schema::table('albums', fn (Blueprint $table) => $table->string('guest_participation', 20)->default('none'));
        Schema::table('invitations', function (Blueprint $table): void {
            $table->char('event_id', 26)->nullable();
            $table->foreign('event_id')->references('id')->on('events')->restrictOnDelete();
            $table->index(['family_space_id', 'event_id']);
        });
        Schema::create('event_admissions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('event_id', 26);
            $table->char('family_space_membership_id', 26);
            $table->timestamp('admitted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('event_id')->references('id')->on('events')->restrictOnDelete();
            $table->foreign('family_space_membership_id')->references('id')->on('family_space_memberships')->restrictOnDelete();
            $table->unique(['event_id', 'family_space_membership_id']);
            $table->index(['family_space_id', 'event_id', 'revoked_at']);
        });

        DB::statement('DROP INDEX IF EXISTS invitations_one_pending_per_family_email');
        DB::statement("CREATE UNIQUE INDEX invitations_one_pending_membership_per_family_email ON invitations (family_space_id, email) WHERE status = 'pending' AND event_id IS NULL");
        DB::statement("CREATE UNIQUE INDEX invitations_one_pending_per_family_email_event ON invitations (family_space_id, email, event_id) WHERE status = 'pending' AND event_id IS NOT NULL");

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
ALTER TABLE albums ADD CONSTRAINT albums_guest_participation_check
CHECK (guest_participation IN ('none', 'view', 'contribute'));
ALTER TABLE albums ADD CONSTRAINT albums_guest_participation_event_check
CHECK (event_id IS NOT NULL OR guest_participation = 'none');
ALTER TABLE event_admissions ENABLE ROW LEVEL SECURITY;
ALTER TABLE event_admissions FORCE ROW LEVEL SECURITY;
CREATE POLICY event_admissions_tenant_isolation ON event_admissions
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS invitations_one_pending_per_family_email_event');
        DB::statement('DROP INDEX IF EXISTS invitations_one_pending_membership_per_family_email');
        DB::statement("CREATE UNIQUE INDEX invitations_one_pending_per_family_email ON invitations (family_space_id, email) WHERE status = 'pending'");
        Schema::dropIfExists('event_admissions');
        Schema::table('invitations', function (Blueprint $table): void {
            $table->dropForeign(['event_id']);
            $table->dropIndex(['family_space_id', 'event_id']);
            $table->dropColumn('event_id');
        });
        Schema::table('albums', fn (Blueprint $table) => $table->dropColumn('guest_participation'));
        Schema::table('events', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
