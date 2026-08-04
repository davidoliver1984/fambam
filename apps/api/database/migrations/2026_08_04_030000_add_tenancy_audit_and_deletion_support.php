<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->char('family_space_id', 26)->nullable()->after('id')->index();
            $table->string('correlation_id', 64)->nullable()->after('actor_user_id')->index();
            $table->string('traceparent', 55)->nullable()->after('correlation_id');
        });

        DB::table('audit_events')->orderBy('id')->each(function (object $audit): void {
            $metadata = is_string($audit->metadata)
                ? json_decode($audit->metadata, true)
                : null;
            $familySpaceId = is_array($metadata) && is_string($metadata['family_space_id'] ?? null)
                ? $metadata['family_space_id']
                : $this->familySpaceIdForLegacyAudit($audit);
            DB::table('audit_events')->where('id', $audit->id)->update([
                'family_space_id' => $familySpaceId,
                'correlation_id' => 'legacy-audit-'.$audit->id,
                'traceparent' => sprintf(
                    '00-%s-%s-01',
                    bin2hex(random_bytes(16)),
                    bin2hex(random_bytes(8)),
                ),
            ]);
        });

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->string('correlation_id', 64)->nullable(false)->change();
            $table->string('traceparent', 55)->nullable(false)->change();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $runtimeRole = $this->runtimeRole();

        DB::unprepared(<<<'SQL'
ALTER TABLE audit_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_events FORCE ROW LEVEL SECURITY;

CREATE POLICY audit_events_insert_in_context ON audit_events
FOR INSERT
WITH CHECK (
    family_space_id IS NULL
    OR family_space_id = app_current_family_space_id()
);

CREATE POLICY family_spaces_visible_to_deletion_teardown ON family_spaces
FOR SELECT
USING (
    id = app_current_family_space_id()
    AND app_authoritative_tenant_operation() = 'deletion_teardown'
);

CREATE POLICY memberships_update_for_deletion_teardown ON family_space_memberships
FOR UPDATE
USING (
    app_current_user_id() IS NOT NULL
    AND family_space_id = app_current_family_space_id()
    AND app_authoritative_tenant_operation() = 'deletion_teardown'
)
WITH CHECK (
    app_current_user_id() IS NOT NULL
    AND family_space_id = app_current_family_space_id()
    AND app_authoritative_tenant_operation() = 'deletion_teardown'
);

CREATE OR REPLACE FUNCTION app_due_family_space_deletions()
RETURNS TABLE (family_space_id char(26), actor_user_id bigint)
LANGUAGE sql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
    SELECT id, deletion_requested_by
    FROM family_spaces
    WHERE status IN ('deletion_requested', 'deleting')
      AND scheduled_deletion_at <= CURRENT_TIMESTAMP
      AND deletion_requested_by IS NOT NULL
$$;

REVOKE ALL ON FUNCTION app_due_family_space_deletions() FROM PUBLIC;
SQL);

        DB::unprepared(<<<SQL
REVOKE SELECT, UPDATE, DELETE ON audit_events FROM {$runtimeRole};
GRANT INSERT ON audit_events TO {$runtimeRole};
GRANT EXECUTE ON FUNCTION app_due_family_space_deletions() TO {$runtimeRole};
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $runtimeRole = $this->runtimeRole();

            DB::unprepared(<<<SQL
GRANT SELECT, UPDATE, DELETE ON audit_events TO {$runtimeRole};
SQL);

            DB::unprepared(<<<'SQL'
DROP FUNCTION IF EXISTS app_due_family_space_deletions();
DROP POLICY IF EXISTS memberships_update_for_deletion_teardown ON family_space_memberships;
DROP POLICY IF EXISTS family_spaces_visible_to_deletion_teardown ON family_spaces;
DROP POLICY IF EXISTS audit_events_insert_in_context ON audit_events;
ALTER TABLE audit_events DISABLE ROW LEVEL SECURITY;
SQL);
        }

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex(['family_space_id']);
            $table->dropIndex(['correlation_id']);
            $table->dropColumn(['family_space_id', 'correlation_id', 'traceparent']);
        });
    }

    private function runtimeRole(): string
    {
        $role = (string) config('database.runtime_role');

        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $role)) {
            throw new RuntimeException('DB_RUNTIME_USERNAME must be a simple PostgreSQL role identifier.');
        }

        return '"'.$role.'"';
    }

    private function familySpaceIdForLegacyAudit(object $audit): ?string
    {
        if ($audit->subject_type === 'App\\Models\\FamilySpace') {
            return (string) $audit->subject_id;
        }

        if ($audit->subject_type === 'App\\Models\\FamilySpaceMembership') {
            return DB::table('family_space_memberships')
                ->where('id', $audit->subject_id)
                ->value('family_space_id');
        }

        if ($audit->subject_type === 'App\\Models\\Invitation') {
            return DB::table('invitations')
                ->where('id', $audit->subject_id)
                ->value('family_space_id');
        }

        return null;
    }
};
