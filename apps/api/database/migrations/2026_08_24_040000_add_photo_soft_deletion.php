<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photos', function (Blueprint $table): void {
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['family_space_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table): void {
            $table->dropIndex(['family_space_id', 'deleted_at']);
            $table->dropForeign(['deleted_by']);
            $table->dropColumn('deleted_by');
            $table->dropSoftDeletes();
        });
    }
};
