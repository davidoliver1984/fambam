<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->string('preferred_name', 120);
            $table->json('alternate_names')->nullable();
            $table->string('identity_status', 20)->default('provisional');
            $table->date('birth_date')->nullable();
            $table->string('birth_date_precision', 20)->default('unknown');
            $table->boolean('is_deceased')->default(false);
            $table->date('death_date')->nullable();
            $table->string('death_date_precision', 20)->default('unknown');
            $table->text('biography')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->index(['family_space_id', 'preferred_name']);
            $table->index(['family_space_id', 'identity_status']);
        });

        Schema::create('person_detail_proposals', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('person_id', 26);
            $table->json('changes');
            $table->string('status', 20)->default('pending');
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->index(['family_space_id', 'person_id', 'status']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE people ENABLE ROW LEVEL SECURITY;
ALTER TABLE people FORCE ROW LEVEL SECURITY;
CREATE POLICY people_tenant_isolation ON people
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());

ALTER TABLE person_detail_proposals ENABLE ROW LEVEL SECURITY;
ALTER TABLE person_detail_proposals FORCE ROW LEVEL SECURITY;
CREATE POLICY person_detail_proposals_tenant_isolation ON person_detail_proposals
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('person_detail_proposals');
        Schema::dropIfExists('people');
    }
};
