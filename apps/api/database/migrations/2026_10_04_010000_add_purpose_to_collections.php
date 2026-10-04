<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table): void {
            $table->string('purpose', 32)->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE collections ADD CONSTRAINT collections_purpose_check CHECK (purpose IS NULL OR purpose IN ('prints', 'calendar'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE collections DROP CONSTRAINT collections_purpose_check');
        }
        Schema::table('collections', fn (Blueprint $table) => $table->dropColumn('purpose'));
    }
};
