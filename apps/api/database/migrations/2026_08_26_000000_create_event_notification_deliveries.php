<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_notification_deliveries', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('event_id', 26);
            $table->char('photo_id', 26);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('event_id')->references('id')->on('events')->restrictOnDelete();
            $table->foreign('photo_id')->references('id')->on('photos')->restrictOnDelete();
            $table->unique(['event_id', 'photo_id', 'user_id']);
            $table->index(['family_space_id', 'event_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
ALTER TABLE event_notification_deliveries ENABLE ROW LEVEL SECURITY;
ALTER TABLE event_notification_deliveries FORCE ROW LEVEL SECURITY;
CREATE POLICY event_notification_deliveries_tenant_isolation ON event_notification_deliveries
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_notification_deliveries');
    }
};
