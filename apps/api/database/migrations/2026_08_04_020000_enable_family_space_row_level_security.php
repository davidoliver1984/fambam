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

        $runtimeRole = $this->runtimeRole();

        if (! DB::table('pg_roles')->where('rolname', trim($runtimeRole, '"'))->exists()) {
            throw new RuntimeException('The PostgreSQL runtime role must be provisioned before migrations run.');
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION app_current_user_id() RETURNS bigint
LANGUAGE sql STABLE PARALLEL SAFE
AS $$
    SELECT NULLIF(current_setting('app.current_user_id', true), '')::bigint
$$;

CREATE OR REPLACE FUNCTION app_current_family_space_id() RETURNS char(26)
LANGUAGE sql STABLE PARALLEL SAFE
AS $$
    SELECT NULLIF(current_setting('app.current_family_space_id', true), '')::char(26)
$$;

CREATE OR REPLACE FUNCTION app_can_manage_memberships() RETURNS boolean
LANGUAGE sql STABLE PARALLEL SAFE
AS $$
    SELECT COALESCE(NULLIF(current_setting('app.can_manage_memberships', true), '')::boolean, false)
$$;

CREATE OR REPLACE FUNCTION app_authoritative_tenant_operation() RETURNS text
LANGUAGE sql STABLE PARALLEL SAFE
AS $$
    SELECT NULLIF(current_setting('app.authoritative_tenant_operation', true), '')
$$;

ALTER TABLE family_spaces ENABLE ROW LEVEL SECURITY;
ALTER TABLE family_spaces FORCE ROW LEVEL SECURITY;

CREATE POLICY family_spaces_visible_to_active_members ON family_spaces
FOR SELECT
USING (
    app_current_user_id() IS NOT NULL
    AND EXISTS (
        SELECT 1
        FROM family_space_memberships membership
        WHERE membership.family_space_id = family_spaces.id
          AND membership.user_id = app_current_user_id()
          AND membership.state = 'active'
    )
);

CREATE POLICY family_spaces_create_authoritative ON family_spaces
FOR INSERT
WITH CHECK (
    app_current_user_id() IS NOT NULL
    AND id = app_current_family_space_id()
    AND app_authoritative_tenant_operation() = 'family_space_creation'
);

CREATE POLICY family_spaces_update_in_context ON family_spaces
FOR UPDATE
USING (app_current_user_id() IS NOT NULL AND id = app_current_family_space_id())
WITH CHECK (app_current_user_id() IS NOT NULL AND id = app_current_family_space_id());

ALTER TABLE family_space_memberships ENABLE ROW LEVEL SECURITY;
ALTER TABLE family_space_memberships FORCE ROW LEVEL SECURITY;

CREATE POLICY memberships_visible_to_self_or_tenant ON family_space_memberships
FOR SELECT
USING (
    user_id = app_current_user_id()
    OR family_space_id = app_current_family_space_id()
);

CREATE POLICY memberships_insert_in_authorized_context ON family_space_memberships
FOR INSERT
WITH CHECK (
    app_current_user_id() IS NOT NULL
    AND family_space_id = app_current_family_space_id()
    AND (
        app_can_manage_memberships()
        OR app_authoritative_tenant_operation() IN ('family_space_creation', 'invitation_acceptance')
    )
);

CREATE POLICY memberships_update_in_authorized_context ON family_space_memberships
FOR UPDATE
USING (
    app_current_user_id() IS NOT NULL
    AND family_space_id = app_current_family_space_id()
    AND (
        app_can_manage_memberships()
        OR app_authoritative_tenant_operation() = 'invitation_acceptance'
    )
)
WITH CHECK (
    app_current_user_id() IS NOT NULL
    AND family_space_id = app_current_family_space_id()
    AND (
        app_can_manage_memberships()
        OR app_authoritative_tenant_operation() = 'invitation_acceptance'
    )
);

CREATE POLICY memberships_delete_in_admin_context ON family_space_memberships
FOR DELETE
USING (
    app_current_user_id() IS NOT NULL
    AND family_space_id = app_current_family_space_id()
    AND app_can_manage_memberships()
);
SQL);

        DB::unprepared(<<<SQL
GRANT USAGE ON SCHEMA public TO {$runtimeRole};
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$runtimeRole};
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {$runtimeRole};
GRANT EXECUTE ON FUNCTION app_current_user_id() TO {$runtimeRole};
GRANT EXECUTE ON FUNCTION app_current_family_space_id() TO {$runtimeRole};
GRANT EXECUTE ON FUNCTION app_can_manage_memberships() TO {$runtimeRole};
GRANT EXECUTE ON FUNCTION app_authoritative_tenant_operation() TO {$runtimeRole};
REVOKE DELETE ON family_spaces FROM {$runtimeRole};
REVOKE ALL ON migrations FROM {$runtimeRole};
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$runtimeRole};
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO {$runtimeRole};
SQL);

        DB::statement("COMMENT ON FUNCTION app_current_family_space_id() IS 'Class C policy anchor: ordinary tenant tables must FORCE RLS and constrain USING and WITH CHECK to this value.'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $runtimeRole = $this->runtimeRole();

        DB::unprepared(<<<SQL
ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES FROM {$runtimeRole};
ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON SEQUENCES FROM {$runtimeRole};
DROP POLICY IF EXISTS memberships_delete_in_admin_context ON family_space_memberships;
DROP POLICY IF EXISTS memberships_update_in_authorized_context ON family_space_memberships;
DROP POLICY IF EXISTS memberships_insert_in_authorized_context ON family_space_memberships;
DROP POLICY IF EXISTS memberships_visible_to_self_or_tenant ON family_space_memberships;
ALTER TABLE family_space_memberships DISABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS family_spaces_update_in_context ON family_spaces;
DROP POLICY IF EXISTS family_spaces_create_authoritative ON family_spaces;
DROP POLICY IF EXISTS family_spaces_visible_to_active_members ON family_spaces;
ALTER TABLE family_spaces DISABLE ROW LEVEL SECURITY;
DROP FUNCTION IF EXISTS app_authoritative_tenant_operation();
DROP FUNCTION IF EXISTS app_can_manage_memberships();
DROP FUNCTION IF EXISTS app_current_family_space_id();
DROP FUNCTION IF EXISTS app_current_user_id();
SQL);
    }

    private function runtimeRole(): string
    {
        $role = (string) config('database.runtime_role');

        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $role)) {
            throw new RuntimeException('DB_RUNTIME_USERNAME must be a simple PostgreSQL role identifier.');
        }

        return '"'.$role.'"';
    }
};
