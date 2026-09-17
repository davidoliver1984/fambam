<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->unique(['id', 'family_space_id']);
            $table->index(['family_space_id', 'owner_user_id']);
        });

        Schema::create('collection_photos', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('collection_id', 26);
            $table->char('photo_id', 26);
            $table->unsignedInteger('position');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign(['collection_id', 'family_space_id'], 'collection_photos_collection_family_fk')
                ->references(['id', 'family_space_id'])->on('collections')->cascadeOnDelete();
            $table->foreign(['photo_id', 'family_space_id'], 'collection_photos_photo_family_fk')
                ->references(['id', 'family_space_id'])->on('photos')->cascadeOnDelete();
            $table->unique(['collection_id', 'photo_id']);
            $table->unique(['collection_id', 'position']);
            $table->index(['family_space_id', 'photo_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
ALTER TABLE collection_photos ADD CONSTRAINT collection_photos_position_positive CHECK (position > 0);
ALTER TABLE collections ENABLE ROW LEVEL SECURITY;
ALTER TABLE collections FORCE ROW LEVEL SECURITY;
CREATE POLICY collections_tenant_isolation ON collections
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
ALTER TABLE collection_photos ENABLE ROW LEVEL SECURITY;
ALTER TABLE collection_photos FORCE ROW LEVEL SECURITY;
CREATE POLICY collection_photos_tenant_isolation ON collection_photos
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_photos');
        Schema::dropIfExists('collections');
    }
};
