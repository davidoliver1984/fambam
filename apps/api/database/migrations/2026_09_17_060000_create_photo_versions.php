<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_versions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('photo_id', 26);
            $table->jsonb('edit_recipe');
            $table->jsonb('restore')->nullable();
            $table->string('derived_object_key');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['id', 'photo_id', 'family_space_id']);
            $table->foreign(['photo_id', 'family_space_id'], 'photo_versions_photo_family_fk')
                ->references(['id', 'family_space_id'])->on('photos')->cascadeOnDelete();
        });
        Schema::create('photo_edit_previews', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('photo_id', 26);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('base_photo_version_id', 26)->nullable();
            $table->jsonb('edit_recipe');
            $table->jsonb('restore')->nullable();
            $table->string('object_key');
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign(['photo_id', 'family_space_id'], 'photo_edit_previews_photo_family_fk')
                ->references(['id', 'family_space_id'])->on('photos')->cascadeOnDelete();
        });
        Schema::table('photos', function (Blueprint $table): void {
            $table->char('active_photo_version_id', 26)->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE photos ADD CONSTRAINT photos_active_version_same_photo_fk FOREIGN KEY (active_photo_version_id, id, family_space_id) REFERENCES photo_versions (id, photo_id, family_space_id) ON DELETE RESTRICT');
            foreach (['photo_versions', 'photo_edit_previews'] as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("CREATE POLICY {$table}_tenant_isolation ON {$table} USING (family_space_id = app_current_family_space_id()) WITH CHECK (family_space_id = app_current_family_space_id())");
            }
            DB::unprepared(<<<'SQL'
CREATE FUNCTION app_due_photo_edit_previews()
RETURNS TABLE (preview_id char(26), family_space_id char(26), actor_user_id bigint)
LANGUAGE sql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
    SELECT p.id, p.family_space_id, COALESCE(p.requested_by,
        (SELECT m.user_id FROM family_space_memberships m
         WHERE m.family_space_id = p.family_space_id AND m.role = 'owner'
           AND m.state = 'active' ORDER BY m.created_at, m.id LIMIT 1))
    FROM photo_edit_previews p
    WHERE p.expires_at <= CURRENT_TIMESTAMP
$$;
REVOKE ALL ON FUNCTION app_due_photo_edit_previews() FROM PUBLIC;
SQL);
            DB::statement('GRANT EXECUTE ON FUNCTION app_due_photo_edit_previews() TO '.$this->runtimeRole());
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS app_due_photo_edit_previews()');
            DB::statement('ALTER TABLE photos DROP CONSTRAINT IF EXISTS photos_active_version_same_photo_fk');
        }
        Schema::table('photos', function (Blueprint $table): void {
            $table->dropColumn('active_photo_version_id');
        });
        Schema::dropIfExists('photo_edit_previews');
        Schema::dropIfExists('photo_versions');
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
