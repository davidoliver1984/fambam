<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('albums', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('visibility', 20)->default('family_space');
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->index(['family_space_id', 'visibility']);
        });

        Schema::create('album_photos', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('album_id', 26);
            $table->char('photo_id', 26);
            $table->unsignedInteger('position');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('album_id')->references('id')->on('albums')->cascadeOnDelete();
            $table->foreign('photo_id')->references('id')->on('photos')->cascadeOnDelete();
            $table->unique(['album_id', 'photo_id']);
            $table->unique(['album_id', 'position']);
            $table->index(['family_space_id', 'photo_id']);
        });

        Schema::create('album_grants', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('album_id', 26);
            $table->char('family_space_membership_id', 26);
            $table->boolean('can_view')->default(true);
            $table->boolean('can_contribute')->default(false);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('album_id')->references('id')->on('albums')->cascadeOnDelete();
            $table->foreign('family_space_membership_id')->references('id')->on('family_space_memberships')->cascadeOnDelete();
            $table->unique(['album_id', 'family_space_membership_id']);
            $table->index(['family_space_id', 'family_space_membership_id']);
        });

        Schema::table('media_uploads', function (Blueprint $table): void {
            $table->char('target_album_id', 26)->nullable();
            $table->foreign('target_album_id')->references('id')->on('albums')->nullOnDelete();
            $table->index(['family_space_id', 'target_album_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE album_grants ADD CONSTRAINT album_grants_contribution_implies_view
CHECK (can_view = true AND (can_contribute = false OR can_view = true));

ALTER TABLE albums ENABLE ROW LEVEL SECURITY;
ALTER TABLE albums FORCE ROW LEVEL SECURITY;
CREATE POLICY albums_tenant_isolation ON albums
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
ALTER TABLE album_photos ENABLE ROW LEVEL SECURITY;
ALTER TABLE album_photos FORCE ROW LEVEL SECURITY;
CREATE POLICY album_photos_tenant_isolation ON album_photos
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
ALTER TABLE album_grants ENABLE ROW LEVEL SECURITY;
ALTER TABLE album_grants FORCE ROW LEVEL SECURITY;
CREATE POLICY album_grants_tenant_isolation ON album_grants
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
    }

    public function down(): void
    {
        Schema::table('media_uploads', function (Blueprint $table): void {
            $table->dropForeign(['target_album_id']);
            $table->dropIndex(['family_space_id', 'target_album_id']);
            $table->dropColumn('target_album_id');
        });
        Schema::dropIfExists('album_grants');
        Schema::dropIfExists('album_photos');
        Schema::dropIfExists('albums');
    }
};
