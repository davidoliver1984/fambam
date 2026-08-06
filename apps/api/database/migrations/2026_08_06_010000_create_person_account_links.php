<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_account_links', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('person_id', 26);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->unique(['family_space_id', 'person_id']);
            $table->unique(['family_space_id', 'user_id']);
        });

        Schema::create('person_account_claims', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('person_id', 26);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->index(['family_space_id', 'person_id', 'status']);
            $table->index(['family_space_id', 'user_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX person_account_claims_pending_person_unique ON person_account_claims (family_space_id, person_id) WHERE status = 'pending'");
        DB::statement("CREATE UNIQUE INDEX person_account_claims_pending_user_unique ON person_account_claims (family_space_id, user_id) WHERE status = 'pending'");

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE person_account_links ENABLE ROW LEVEL SECURITY;
ALTER TABLE person_account_links FORCE ROW LEVEL SECURITY;
CREATE POLICY person_account_links_tenant_isolation ON person_account_links
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());

ALTER TABLE person_account_claims ENABLE ROW LEVEL SECURITY;
ALTER TABLE person_account_claims FORCE ROW LEVEL SECURITY;
CREATE POLICY person_account_claims_tenant_isolation ON person_account_claims
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('person_account_claims');
        Schema::dropIfExists('person_account_links');
    }
};
