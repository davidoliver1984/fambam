<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_tag', function (Blueprint $table): void {
            $table->char('family_space_id', 26);
            $table->char('event_id', 26);
            $table->char('tag_id', 26);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['event_id', 'tag_id']);
            $table->foreign(['event_id', 'family_space_id'], 'event_tag_event_family_fk')
                ->references(['id', 'family_space_id'])->on('events')->cascadeOnDelete();
            $table->foreign(['tag_id', 'family_space_id'], 'event_tag_tag_family_fk')
                ->references(['id', 'family_space_id'])->on('tags')->cascadeOnDelete();
            $table->index(['family_space_id', 'tag_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE event_tag ENABLE ROW LEVEL SECURITY;
ALTER TABLE event_tag FORCE ROW LEVEL SECURITY;
CREATE POLICY event_tag_tenant_isolation ON event_tag
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('event_tag');
    }
};
