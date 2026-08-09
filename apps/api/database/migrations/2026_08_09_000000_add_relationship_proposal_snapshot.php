<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relationship_proposals', function (Blueprint $table): void {
            $table->json('relationship_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('relationship_proposals', function (Blueprint $table): void {
            $table->dropColumn('relationship_snapshot');
        });
    }
};
