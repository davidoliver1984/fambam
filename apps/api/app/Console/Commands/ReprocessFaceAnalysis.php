<?php

namespace App\Console\Commands;

use App\Enums\MediaUploadState;
use App\Jobs\DispatchFaceAnalysis;
use App\Models\FamilySpace;
use App\Models\MediaUpload;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReprocessFaceAnalysis extends Command
{
    protected $signature = 'fambam:reprocess-face-analysis
        {--family-space= : Required Family Space ULID}
        {--actor= : Required operator User ID}
        {--media-upload=* : Optional bounded MediaUpload ULIDs within that Family Space}';

    protected $description = 'Audit and request bounded face-analysis reprocessing for one Family Space';

    public function handle(DatabaseTenantContext $tenant, AuditRecorder $audit): int
    {
        $familySpaceId = $this->option('family-space');
        $actorId = $this->option('actor');
        if (! is_string($familySpaceId) || $familySpaceId === '' || ! is_numeric($actorId)) {
            $this->components->error('--family-space and --actor are required; platform-wide reprocessing is prohibited.');

            return self::INVALID;
        }
        $requested = array_values(array_filter($this->option('media-upload'), 'is_string'));
        $context = TenantOperationContext::forBackground($familySpaceId, (int) $actorId);
        $uploads = DB::transaction(function () use ($tenant, $audit, $context, $requested): array {
            $tenant->establishUser($context->actorUserId);
            $tenant->establishFamilySpace($context->familySpaceId);
            $family = FamilySpace::query()->findOrFail($context->familySpaceId);
            $actor = User::query()->findOrFail($context->actorUserId);
            $query = MediaUpload::query()
                ->whereIn('state', [MediaUploadState::Processing, MediaUploadState::Ready, MediaUploadState::Degraded])
                ->whereNotNull('canonical_object_key')
                ->whereNotNull('canonical_sha256');
            if ($requested !== []) {
                $query->whereIn('id', $requested);
            }
            $uploads = $query->orderBy('id')->get(['id', 'canonical_sha256']);
            if ($requested !== [] && $uploads->count() !== count(array_unique($requested))) {
                throw new \InvalidArgumentException('Every requested MediaUpload must be eligible and belong to the selected Family Space.');
            }
            $audit->record(
                'face_analysis.reprocessing_requested',
                $family,
                $actor,
                metadata: [
                    'scope' => $requested === [] ? 'family_space' : 'media_uploads',
                    'media_upload_ids' => $requested,
                    'analysis_identity' => config('image-analysis.identity'),
                ],
                operationContext: $context,
            );

            return $uploads->map(fn (MediaUpload $upload): array => [
                'id' => $upload->id,
                'canonical_sha256' => (string) $upload->canonical_sha256,
            ])->all();
        });
        foreach ($uploads as $upload) {
            DispatchFaceAnalysis::dispatch($context->toArray(), $upload['id'], $upload['canonical_sha256']);
        }
        $this->components->info(sprintf('Requested %d bounded face-analysis run(s).', count($uploads)));

        return self::SUCCESS;
    }
}
