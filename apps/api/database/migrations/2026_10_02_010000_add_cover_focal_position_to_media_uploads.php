<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_uploads', function (Blueprint $table): void {
            $table->decimal('cover_focal_x', 4, 3)->nullable();
            $table->decimal('cover_focal_y', 4, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media_uploads', function (Blueprint $table): void {
            $table->dropColumn(['cover_focal_x', 'cover_focal_y']);
        });
    }
};
