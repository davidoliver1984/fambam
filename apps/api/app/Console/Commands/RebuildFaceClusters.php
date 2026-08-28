<?php

namespace App\Console\Commands;

use App\FaceRecognition\FaceClusterGenerationManager;
use App\FaceRecognition\FaceRecognitionCalibration;
use App\Tenancy\TenantOperationContext;
use Illuminate\Console\Command;

class RebuildFaceClusters extends Command
{
    protected $signature = 'fambam:rebuild-face-clusters
        {--family-space= : Required Family Space ULID}
        {--actor= : Required operator User ID}';

    protected $description = 'Build and atomically activate conservative unknown-face clusters for one Family Space';

    public function handle(
        FaceClusterGenerationManager $clusters,
        FaceRecognitionCalibration $calibration,
    ): int {
        if (! config('face_recognition.processing_enabled')) {
            $this->components->error('Automatic face-recognition processing remains disabled until FPA-P10-S07 calibration.');

            return self::FAILURE;
        }
        try {
            $calibration->assertAccepted();
        } catch (\RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $familySpaceId = $this->option('family-space');
        $actorId = $this->option('actor');
        if (! is_string($familySpaceId) || $familySpaceId === '' || ! is_numeric($actorId)) {
            $this->components->error('--family-space and --actor are required; platform-wide clustering is prohibited.');

            return self::INVALID;
        }

        $generation = $clusters->rebuild(
            TenantOperationContext::forBackground($familySpaceId, (int) $actorId),
        );
        $this->components->info("Activated face-cluster generation {$generation->id}.");

        return self::SUCCESS;
    }
}
