<?php

use App\Enums\FamilySpaceDefaultVisibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_spaces', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name');
            $table->string('default_visibility', 20)
                ->default(FamilySpaceDefaultVisibility::FamilySpace->value)
                ->after('description');
        });

        Schema::table('family_space_memberships', function (Blueprint $table): void {
            $table->timestamp('joined_at')->nullable()->after('invitation_id');
        });
        DB::table('family_space_memberships')->update(['joined_at' => DB::raw('created_at')]);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }
        Schema::table('family_space_memberships', function (Blueprint $table): void {
            $table->timestamp('joined_at')->useCurrent()->nullable(false)->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE POLICY memberships_self_leave ON family_space_memberships
FOR UPDATE
USING (
    user_id = app_current_user_id()
    AND family_space_id = app_current_family_space_id()
    AND app_authoritative_tenant_operation() = 'self_leave'
)
WITH CHECK (
    user_id = app_current_user_id()
    AND family_space_id = app_current_family_space_id()
    AND app_authoritative_tenant_operation() = 'self_leave'
);

CREATE OR REPLACE FUNCTION enforce_membership_self_leave_update() RETURNS trigger AS $$
BEGIN
    IF app_authoritative_tenant_operation() = 'self_leave' THEN
        IF OLD.user_id <> app_current_user_id()
            OR OLD.family_space_id <> app_current_family_space_id()
            OR OLD.role = 'owner'
            OR OLD.state <> 'active'
            OR NEW.family_space_id IS DISTINCT FROM OLD.family_space_id
            OR NEW.user_id IS DISTINCT FROM OLD.user_id
            OR NEW.role IS DISTINCT FROM OLD.role
            OR NEW.invitation_id IS DISTINCT FROM OLD.invitation_id
            OR NEW.joined_at IS DISTINCT FROM OLD.joined_at
            OR NEW.state <> 'removed'
            OR NEW.removed_at IS NULL
            OR NEW.removed_by IS DISTINCT FROM app_current_user_id()
        THEN
            RAISE EXCEPTION 'self-leave may only remove the current non-owner membership';
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER family_space_memberships_enforce_self_leave
BEFORE UPDATE ON family_space_memberships
FOR EACH ROW EXECUTE FUNCTION enforce_membership_self_leave_update();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS family_space_memberships_enforce_self_leave ON family_space_memberships;
DROP FUNCTION IF EXISTS enforce_membership_self_leave_update();
DROP POLICY IF EXISTS memberships_self_leave ON family_space_memberships;
SQL);
        }

        Schema::table('family_space_memberships', function (Blueprint $table): void {
            $table->dropColumn('joined_at');
        });
        Schema::table('family_spaces', function (Blueprint $table): void {
            $table->dropColumn(['description', 'default_visibility']);
        });
    }
};
