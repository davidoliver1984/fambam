<?php

namespace App\Console\Commands;

use App\FaceRecognition\FaceClusterGenerationManager;
use App\FaceRecognition\FaceRecognitionCalibration;
use App\Tenancy\TenantOperationContext;
use Illuminate\Console\Command;
use OpenTelemetry\API\Globals;
use Throwable;

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

        $startedAt = hrtime(true);
        try {
            $generation = $clusters->rebuild(
                TenantOperationContext::forBackground($familySpaceId, (int) $actorId),
            );
        } catch (Throwable $exception) {
            $this->recordInvocation('failed', $startedAt);
            throw $exception;
        }
        $this->recordInvocation('succeeded', $startedAt, $generation->id);
        $this->components->info("Activated face-cluster generation {$generation->id}.");

        return self::SUCCESS;
    }

    private function recordInvocation(string $outcome, int $startedAt, ?string $generationId = null): void
    {
        $attributes = ['face.cluster.command.outcome' => $outcome];
        if ($generationId !== null) {
            $attributes['face.cluster.generation_id'] = $generationId;
        }
        $meter = Globals::meterProvider()->getMeter('fambam-api');
        $meter->createCounter('face.cluster.command.invocations')->add(1, $attributes);
        $meter->createHistogram('face.cluster.command.duration')->record(
            (hrtime(true) - $startedAt) / 1_000_000_000,
            $attributes,
        );
    }
}
