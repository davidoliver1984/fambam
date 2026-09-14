<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('photo_story_revisions');
        Schema::dropIfExists('photo_stories');
    }

    public function down(): void
    {
        throw new RuntimeException('The legacy PhotoStory removal is not reversible; restore from a pre-cutover backup instead.');
    }
};
