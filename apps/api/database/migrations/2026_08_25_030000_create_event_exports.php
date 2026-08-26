<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_exports', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('event_id', 26);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('state', 20)->default('pending');
            $table->string('object_key');
            $table->char('archive_sha256', 64)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->unsignedInteger('photo_count')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('event_id')->references('id')->on('events')->restrictOnDelete();
            $table->index(['family_space_id', 'event_id', 'created_at']);
            $table->index(['state', 'expires_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $runtimeRole = $this->runtimeRole();
        DB::unprepared(<<<'SQL'
ALTER TABLE event_exports ADD CONSTRAINT event_exports_state_check
CHECK (state IN ('pending', 'processing', 'ready', 'failed', 'expired'));
ALTER TABLE event_exports ENABLE ROW LEVEL SECURITY;
ALTER TABLE event_exports FORCE ROW LEVEL SECURITY;
CREATE POLICY event_exports_tenant_isolation ON event_exports
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());

CREATE OR REPLACE FUNCTION app_due_event_exports()
RETURNS TABLE (event_export_id char(26), family_space_id char(26), actor_user_id bigint)
LANGUAGE sql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
    SELECT event_exports.id, event_exports.family_space_id, event_exports.requested_by
    FROM event_exports
    WHERE event_exports.state = 'ready'
      AND event_exports.expires_at <= CURRENT_TIMESTAMP
$$;

REVOKE ALL ON FUNCTION app_due_event_exports() FROM PUBLIC;
SQL);
        DB::unprepared(<<<SQL
GRANT EXECUTE ON FUNCTION app_due_event_exports() TO {$runtimeRole};
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS app_due_event_exports();');
        }

        Schema::dropIfExists('event_exports');
    }

    private function runtimeRole(): string
    {
        $role = (string) config('database.runtime_role');
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $role)) {
            throw new RuntimeException('The configured database runtime role is invalid.');
        }

        return $role;
    }
};
