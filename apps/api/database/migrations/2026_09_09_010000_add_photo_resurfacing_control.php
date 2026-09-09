<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photos', function (Blueprint $table): void {
            $table->boolean('do_not_resurface')->default(false);
            $table->index(
                ['family_space_id', 'do_not_resurface', 'historical_date'],
                'photos_resurfacing_date_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table): void {
            $table->dropIndex('photos_resurfacing_date_index');
            $table->dropColumn('do_not_resurface');
        });
    }
};
