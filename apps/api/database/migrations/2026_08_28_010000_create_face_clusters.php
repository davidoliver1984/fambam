<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_cluster_generations', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->string('status', 20)->default('building');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('created_at');
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->unique(['id', 'family_space_id'], 'face_cluster_generations_id_family_unique');
            $table->index(['family_space_id', 'status']);
        });

        Schema::create('face_clusters', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('clustering_generation_id', 26);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign(['clustering_generation_id', 'family_space_id'], 'face_clusters_generation_family_foreign')
                ->references(['id', 'family_space_id'])->on('face_cluster_generations')->cascadeOnDelete();
            $table->unique(['id', 'family_space_id'], 'face_clusters_id_family_unique');
            $table->index(['family_space_id', 'clustering_generation_id', 'status'], 'face_clusters_generation_status_index');
        });

        Schema::create('face_cluster_members', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('face_cluster_id', 26);
            $table->char('face_observation_id', 26);
            $table->boolean('is_active')->default(false);
            $table->timestamp('created_at');
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign(['face_cluster_id', 'family_space_id'], 'face_cluster_members_cluster_family_foreign')
                ->references(['id', 'family_space_id'])->on('face_clusters')->cascadeOnDelete();
            $table->foreign(['face_observation_id', 'family_space_id'], 'face_cluster_members_observation_family_foreign')
                ->references(['id', 'family_space_id'])->on('face_observations')->cascadeOnDelete();
            $table->unique(['face_cluster_id', 'face_observation_id'], 'face_cluster_members_cluster_observation_unique');
            $table->index(['family_space_id', 'face_observation_id'], 'face_cluster_members_family_observation_index');
        });

        DB::statement("CREATE UNIQUE INDEX face_cluster_generations_active_unique ON face_cluster_generations (family_space_id) WHERE status = 'active'");
        DB::statement('CREATE UNIQUE INDEX face_cluster_members_active_unique ON face_cluster_members (face_observation_id) WHERE is_active = true');

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE face_cluster_generations ADD CONSTRAINT face_cluster_generations_status_check
    CHECK (status IN ('building', 'active', 'superseded'));
ALTER TABLE face_clusters ADD CONSTRAINT face_clusters_status_check
    CHECK (status IN ('active', 'retired', 'superseded'));

ALTER TABLE face_cluster_generations ENABLE ROW LEVEL SECURITY;
ALTER TABLE face_cluster_generations FORCE ROW LEVEL SECURITY;
CREATE POLICY face_cluster_generations_tenant_isolation ON face_cluster_generations
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());

ALTER TABLE face_clusters ENABLE ROW LEVEL SECURITY;
ALTER TABLE face_clusters FORCE ROW LEVEL SECURITY;
CREATE POLICY face_clusters_tenant_isolation ON face_clusters
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());

ALTER TABLE face_cluster_members ENABLE ROW LEVEL SECURITY;
ALTER TABLE face_cluster_members FORCE ROW LEVEL SECURITY;
CREATE POLICY face_cluster_members_tenant_isolation ON face_cluster_members
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('face_cluster_members');
        Schema::dropIfExists('face_clusters');
        Schema::dropIfExists('face_cluster_generations');
    }
};
