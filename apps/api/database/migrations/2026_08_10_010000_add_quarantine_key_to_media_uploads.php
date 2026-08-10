<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_uploads', function (Blueprint $table): void {
            $table->string('quarantine_object_key', 512)->nullable()->unique()->after('original_object_key');
        });
    }

    public function down(): void
    {
        Schema::table('media_uploads', function (Blueprint $table): void {
            $table->dropUnique(['quarantine_object_key']);
            $table->dropColumn('quarantine_object_key');
        });
    }
};
