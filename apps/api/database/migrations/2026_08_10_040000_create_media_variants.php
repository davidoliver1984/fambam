<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_variants', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('family_space_id', 26);
            $table->char('media_upload_id', 26);
            $table->string('transform_name', 20);
            $table->unsignedInteger('processing_version');
            $table->string('object_key', 512)->unique();
            $table->string('mime_type', 255);
            $table->char('sha256', 64);
            $table->unsignedInteger('pixel_width');
            $table->unsignedInteger('pixel_height');
            $table->unsignedBigInteger('byte_size');
            $table->timestamps();

            $table->foreign('family_space_id')->references('id')->on('family_spaces')->cascadeOnDelete();
            $table->foreign('media_upload_id')->references('id')->on('media_uploads')->cascadeOnDelete();
            $table->unique(
                ['media_upload_id', 'transform_name', 'processing_version'],
                'media_variants_identity_unique',
            );
            $table->index(['family_space_id', 'media_upload_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE media_variants ENABLE ROW LEVEL SECURITY;
ALTER TABLE media_variants FORCE ROW LEVEL SECURITY;
CREATE POLICY media_variants_tenant_isolation ON media_variants
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('media_variants');
    }
};
