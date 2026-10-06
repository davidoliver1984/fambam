<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photos', function (Blueprint $table): void {
            $table->index(
                ['family_space_id', 'historical_date', 'id'],
                'photos_family_historical_date_cursor_index',
            );
            $table->index(
                ['family_space_id', 'created_at', 'id'],
                'photos_family_created_cursor_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table): void {
            $table->dropIndex('photos_family_historical_date_cursor_index');
            $table->dropIndex('photos_family_created_cursor_index');
        });
    }
};
