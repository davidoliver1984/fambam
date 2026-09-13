<?php

namespace App\Console\Commands;

use App\Stories\LegacyStoryBackfill;
use Illuminate\Console\Command;

final class BackfillLegacyStories extends Command
{
    protected $signature = 'fambam:stories:backfill-legacy';

    protected $description = 'Idempotently backfill legacy PhotoStory records into first-class Stories';

    public function handle(LegacyStoryBackfill $backfill): int
    {
        $result = $backfill->run();
        $this->components->info("Backfilled {$result['stories']} Stories and {$result['revisions']} revisions.");

        return self::SUCCESS;
    }
}
