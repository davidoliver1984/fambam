<?php

use App\Enums\FamilySpaceRole;
use App\Enums\FamilySpaceStatus;
use App\Enums\InvitationStatus;
use App\Enums\MembershipState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('can_create_family_spaces')->default(false)->after('can_invite');
        });

        Schema::create('family_spaces', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('status', 30)->default(FamilySpaceStatus::Active->value)->index();
            $table->timestamp('deletion_requested_at')->nullable();
            $table->foreignId('deletion_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_deletion_at')->nullable();
            $table->timestamps();
        });

        Schema::table('invitations', function (Blueprint $table): void {
            $table->foreignUlid('family_space_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->string('role', 30)->default(FamilySpaceRole::Member->value)->after('email');
            $table->index(['family_space_id', 'email', 'status']);
        });

        Schema::create('family_space_memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('family_space_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 30);
            $table->string('state', 20)->default(MembershipState::Active->value)->index();
            $table->foreignId('invitation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['family_space_id', 'user_id']);
            $table->index(['user_id', 'state']);
            $table->index(['family_space_id', 'role', 'state']);
        });

        $this->migrateExistingAccounts();

        Schema::table('invitations', function (Blueprint $table): void {
            $table->foreignUlid('family_space_id')->nullable(false)->change();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('can_invite');
        });

        $this->installOwnerInvariant();
    }

    public function down(): void
    {
        $this->removeOwnerInvariant();
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('can_invite')->default(false)->after('timezone');
        });
        Schema::dropIfExists('family_space_memberships');

        Schema::table('invitations', function (Blueprint $table): void {
            $table->dropIndex(['family_space_id', 'email', 'status']);
            $table->dropConstrainedForeignId('family_space_id');
            $table->dropColumn('role');
        });

        Schema::dropIfExists('family_spaces');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('can_create_family_spaces');
        });
    }

    private function migrateExistingAccounts(): void
    {
        $users = DB::table('users')->orderBy('id')->get(['id']);

        if ($users->isEmpty()) {
            return;
        }

        $now = now();
        $familySpaceId = (string) Str::ulid();
        DB::table('family_spaces')->insert([
            'id' => $familySpaceId,
            'slug' => 'family-archive',
            'name' => 'Family Archive',
            'status' => FamilySpaceStatus::Active->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($users as $index => $user) {
            DB::table('family_space_memberships')->insert([
                'id' => (string) Str::ulid(),
                'family_space_id' => $familySpaceId,
                'user_id' => $user->id,
                'role' => $index === 0 ? FamilySpaceRole::Owner->value : FamilySpaceRole::Member->value,
                'state' => MembershipState::Active->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $pendingInvitationIds = DB::table('invitations')
            ->where('status', InvitationStatus::Pending->value)
            ->pluck('id');
        DB::table('invitation_claims')->whereIn('invitation_id', $pendingInvitationIds)->delete();
        DB::table('invitations')->whereIn('id', $pendingInvitationIds)->update([
            'status' => InvitationStatus::Expired->value,
            'token_hash' => null,
        ]);
        DB::table('invitations')->update(['family_space_id' => $familySpaceId]);
    }

    private function installOwnerInvariant(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION ensure_family_space_has_active_owner() RETURNS trigger AS $$
DECLARE
    target_family_space_id char(26);
BEGIN
    IF TG_TABLE_NAME = 'family_spaces' THEN
        target_family_space_id := COALESCE(NEW.id, OLD.id);
    ELSE
        target_family_space_id := COALESCE(NEW.family_space_id, OLD.family_space_id);
    END IF;

    IF EXISTS (
        SELECT 1 FROM family_spaces
        WHERE id = target_family_space_id AND status <> 'deleted'
    ) AND NOT EXISTS (
        SELECT 1 FROM family_space_memberships
        WHERE family_space_id = target_family_space_id
          AND role = 'owner'
          AND state = 'active'
    ) THEN
        RAISE EXCEPTION 'family space % must retain an active owner', target_family_space_id;
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER family_spaces_require_active_owner
AFTER INSERT OR UPDATE ON family_spaces
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION ensure_family_space_has_active_owner();

CREATE CONSTRAINT TRIGGER family_space_memberships_require_active_owner
AFTER INSERT OR UPDATE OR DELETE ON family_space_memberships
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION ensure_family_space_has_active_owner();
SQL);
    }

    private function removeOwnerInvariant(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS family_space_memberships_require_active_owner ON family_space_memberships;
DROP TRIGGER IF EXISTS family_spaces_require_active_owner ON family_spaces;
DROP FUNCTION IF EXISTS ensure_family_space_has_active_owner();
SQL);
    }
};
