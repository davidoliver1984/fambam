<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table): void {
            $table->timestamp('deleting_at')->nullable();
        });
        Schema::table('family_exports', function (Blueprint $table): void {
            $table->char('collection_id', 26)->nullable();
            $table->char('album_id', 26)->nullable();
            $table->char('selection_checksum', 64)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('generation_started_at')->nullable();
            $table->timestamp('generation_finished_at')->nullable();
            $table->timestamp('storage_reconciled_at')->nullable();
            $table->foreign(['collection_id', 'family_space_id'], 'family_exports_collection_family_fk')
                ->references(['id', 'family_space_id'])->on('collections')->restrictOnDelete();
            $table->foreign(['album_id', 'family_space_id'], 'family_exports_album_family_fk')
                ->references(['id', 'family_space_id'])->on('albums')->restrictOnDelete();
            $table->index(['cancelled_at', 'storage_reconciled_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE family_exports DROP CONSTRAINT family_exports_scope_check;
ALTER TABLE family_exports ADD CONSTRAINT family_exports_scope_check
CHECK (scope IN ('family_space_full', 'personal', 'collection', 'album'));
ALTER TABLE family_exports ADD CONSTRAINT family_exports_curated_target_check
CHECK ((scope = 'collection' AND album_id IS NULL
        AND (collection_id IS NOT NULL OR cancelled_at IS NOT NULL))
    OR (scope = 'album' AND album_id IS NOT NULL AND collection_id IS NULL)
    OR (scope IN ('family_space_full', 'personal') AND album_id IS NULL AND collection_id IS NULL));
CREATE OR REPLACE FUNCTION app_due_family_exports()
RETURNS TABLE (family_export_id char(26), family_space_id char(26), actor_user_id bigint)
LANGUAGE sql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
    SELECT e.id, e.family_space_id, e.requested_by
    FROM family_exports e
    WHERE (e.state = 'ready' AND e.expires_at <= CURRENT_TIMESTAMP)
       OR (e.cancelled_at IS NOT NULL AND e.storage_reconciled_at IS NULL)
$$;
REVOKE ALL ON FUNCTION app_due_family_exports() FROM PUBLIC;
SQL);
        DB::statement('GRANT EXECUTE ON FUNCTION app_due_family_exports() TO '.$this->runtimeRole());
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
ALTER TABLE family_exports DROP CONSTRAINT family_exports_curated_target_check;
CREATE OR REPLACE FUNCTION app_due_family_exports()
RETURNS TABLE (family_export_id char(26), family_space_id char(26), actor_user_id bigint)
LANGUAGE sql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
    SELECT e.id, e.family_space_id, e.requested_by
    FROM family_exports e
    WHERE e.state = 'ready' AND e.expires_at <= CURRENT_TIMESTAMP
$$;
ALTER TABLE family_exports DROP CONSTRAINT family_exports_scope_check;
ALTER TABLE family_exports ADD CONSTRAINT family_exports_scope_check
CHECK (scope IN ('family_space_full', 'personal'));
SQL);
        }
        Schema::table('family_exports', function (Blueprint $table): void {
            $table->dropForeign('family_exports_collection_family_fk');
            $table->dropForeign('family_exports_album_family_fk');
            $table->dropIndex(['cancelled_at', 'storage_reconciled_at']);
            $table->dropColumn(['collection_id', 'album_id', 'selection_checksum', 'cancelled_at',
                'generation_started_at', 'generation_finished_at', 'storage_reconciled_at']);
        });
        Schema::table('collections', function (Blueprint $table): void {
            $table->dropColumn('deleting_at');
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
};
