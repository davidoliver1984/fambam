<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_people', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('event_id', 26);
            $table->char('person_id', 26);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['event_id', 'person_id']);
            $table->foreign(['event_id', 'family_space_id'], 'event_people_event_family_fk')
                ->references(['id', 'family_space_id'])->on('events')->cascadeOnDelete();
            $table->foreign(['person_id', 'family_space_id'], 'event_people_person_family_fk')
                ->references(['id', 'family_space_id'])->on('people')->cascadeOnDelete();
            $table->index(['family_space_id', 'person_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
ALTER TABLE event_people ENABLE ROW LEVEL SECURITY;
ALTER TABLE event_people FORCE ROW LEVEL SECURITY;
CREATE POLICY event_people_tenant_isolation ON event_people
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_people');
    }
};
