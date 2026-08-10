<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_uploads', function (Blueprint $table): void {
            $table->unsignedInteger('pixel_width')->nullable()->after('detected_mime_type');
            $table->unsignedInteger('pixel_height')->nullable()->after('pixel_width');
            $table->unsignedTinyInteger('original_orientation')->nullable()->after('pixel_height');
            $table->string('camera_make')->nullable()->after('original_orientation');
            $table->string('camera_model')->nullable()->after('camera_make');
            $table->string('exif_capture_timestamp', 40)->nullable()->after('camera_model');
            $table->decimal('gps_latitude', 10, 7)->nullable()->after('exif_capture_timestamp');
            $table->decimal('gps_longitude', 10, 7)->nullable()->after('gps_latitude');
            $table->text('original_exif_base64')->nullable()->after('gps_longitude');
            $table->text('original_icc_profile_base64')->nullable()->after('original_exif_base64');
            $table->string('canonical_mime_type')->nullable()->after('canonical_object_key');
            $table->char('canonical_sha256', 64)->nullable()->after('canonical_mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('media_uploads', function (Blueprint $table): void {
            $table->dropColumn([
                'pixel_width',
                'pixel_height',
                'original_orientation',
                'camera_make',
                'camera_model',
                'exif_capture_timestamp',
                'gps_latitude',
                'gps_longitude',
                'original_exif_base64',
                'original_icc_profile_base64',
                'canonical_mime_type',
                'canonical_sha256',
            ]);
        });
    }
};
