<?php

namespace App\Console\Commands;

use App\FaceRecognition\FaceEmbeddingProjectionManager;
use App\Tenancy\TenantOperationContext;
use Illuminate\Console\Command;

class RebuildFaceEmbeddingProjections extends Command
{
    protected $signature = 'fambam:rebuild-face-embedding-projections
        {--family-space= : Required Family Space ULID}
        {--actor= : Required operator User ID}
        {--face-observation=* : Optional bounded FaceObservation ULIDs within that Family Space}';

    protected $description = 'Deterministically rebuild disposable face-embedding projections for one Family Space';

    public function handle(FaceEmbeddingProjectionManager $projections): int
    {
        $familySpaceId = $this->option('family-space');
        $actorId = $this->option('actor');
        if (! is_string($familySpaceId) || $familySpaceId === '' || ! is_numeric($actorId)) {
            $this->components->error('--family-space and --actor are required; platform-wide rebuilding is prohibited.');

            return self::INVALID;
        }

        $requested = array_values(array_filter($this->option('face-observation'), 'is_string'));
        $count = $projections->rebuild(
            TenantOperationContext::forBackground($familySpaceId, (int) $actorId),
            $requested,
        );
        $this->components->info(sprintf('Rebuilt %d face-embedding projection(s).', $count));

        return self::SUCCESS;
    }
}
