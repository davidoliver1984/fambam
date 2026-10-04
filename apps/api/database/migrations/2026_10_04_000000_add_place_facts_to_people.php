<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->string('birth_place')->nullable()->after('birth_date_precision');
            $table->string('death_place')->nullable()->after('death_date_precision');
            $table->string('residence_place')->nullable()->after('death_place');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->dropColumn(['birth_place', 'death_place', 'residence_place']);
        });
    }
};
