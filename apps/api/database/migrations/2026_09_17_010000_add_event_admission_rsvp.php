<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_admissions', function (Blueprint $table): void {
            $table->string('rsvp_status', 20)->default('pending');
            $table->timestamp('rsvp_responded_at')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE event_admissions ADD CONSTRAINT event_admissions_rsvp_status_check CHECK (rsvp_status IN ('pending', 'going', 'not_attending'))");
        }
    }

    public function down(): void
    {
        Schema::table('event_admissions', fn (Blueprint $table) => $table->dropColumn(['rsvp_status', 'rsvp_responded_at']));
    }
};
