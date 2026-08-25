<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('location', 255)->nullable();
            $table->string('status', 20)->default('planned');
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->index(['family_space_id', 'starts_on']);
            $table->index(['family_space_id', 'status']);
        });

        Schema::table('albums', function (Blueprint $table): void {
            $table->char('event_id', 26)->nullable();
            $table->foreign('event_id')->references('id')->on('events')->restrictOnDelete();
            $table->index(['family_space_id', 'event_id']);
        });
        Schema::table('photos', function (Blueprint $table): void {
            $table->char('primary_event_id', 26)->nullable();
            $table->foreign('primary_event_id')->references('id')->on('events')->restrictOnDelete();
            $table->index(['family_space_id', 'primary_event_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
ALTER TABLE events ADD CONSTRAINT events_status_check
CHECK (status IN ('planned', 'active', 'completed', 'archived'));
ALTER TABLE events ADD CONSTRAINT events_date_range_check
CHECK (starts_on IS NULL OR ends_on IS NULL OR ends_on >= starts_on);
ALTER TABLE events ENABLE ROW LEVEL SECURITY;
ALTER TABLE events FORCE ROW LEVEL SECURITY;
CREATE POLICY events_tenant_isolation ON events
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
        }
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table): void {
            $table->dropForeign(['primary_event_id']);
            $table->dropIndex(['family_space_id', 'primary_event_id']);
            $table->dropColumn('primary_event_id');
        });
        Schema::table('albums', function (Blueprint $table): void {
            $table->dropForeign(['event_id']);
            $table->dropIndex(['family_space_id', 'event_id']);
            $table->dropColumn('event_id');
        });
        Schema::dropIfExists('events');
    }
};
