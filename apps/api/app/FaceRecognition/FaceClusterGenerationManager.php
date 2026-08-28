<?php

namespace App\FaceRecognition;

use App\Enums\FaceClusterGenerationStatus;
use App\Enums\FaceClusterStatus;
use App\Models\FaceCluster;
use App\Models\FaceClusterGeneration;
use App\Models\FaceObservation;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class FaceClusterGenerationManager
{
    public function __construct(
        private readonly SimilaritySearch $similarity,
        private readonly Float32Embedding $embeddings,
        private readonly ConservativeFaceClusterer $clusterer,
        private readonly DatabaseTenantContext $tenant,
    ) {}

    public function rebuild(TenantOperationContext $context): FaceClusterGeneration
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Face clustering requires PostgreSQL similarity projections.');
        }

        [$expectedActiveGenerationId, $observations] = DB::transaction(function () use ($context): array {
            $this->establishTenant($context);

            return [
                FaceClusterGeneration::query()
                    ->where('status', FaceClusterGenerationStatus::Active)
                    ->value('id'),
                $this->eligibleObservations($context->familySpaceId)->get(),
            ];
        });

        $identity = $this->activeIdentity();
        $configuredDistance = config('face_recognition.clustering_max_cosine_distance');
        if (! is_numeric($configuredDistance)) {
            throw new RuntimeException('Face clustering has no accepted calibration threshold.');
        }
        $maximumDistance = (float) $configuredDistance;
        $maximumResults = (int) config('face_recognition.similarity_max_results');
        $eligibleIds = $observations->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
        $eligibleLookup = array_fill_keys($eligibleIds, true);
        $pairDistances = [];

        DB::transaction(function () use (
            $context,
            $observations,
            $identity,
            $maximumResults,
            $maximumDistance,
            $eligibleLookup,
            &$pairDistances,
        ): void {
            $this->establishTenant($context);
            foreach ($observations as $observation) {
                $embedding = $this->embeddings->decode(
                    $this->binary($observation->getRawOriginal('embedding')),
                    $observation->embedding_dimension,
                );
                foreach ($this->similarity->nearest(
                    $context->familySpaceId,
                    $identity,
                    $embedding,
                    $maximumResults,
                ) as $match) {
                    if ($match->faceObservationId === $observation->id
                        || ! isset($eligibleLookup[$match->faceObservationId])
                        || $match->cosineDistance > $maximumDistance) {
                        continue;
                    }
                    $pairDistances[ConservativeFaceClusterer::pairKey(
                        $observation->id,
                        $match->faceObservationId,
                    )] = $match->cosineDistance;
                }
            }
        });

        $groups = $this->clusterer->cluster($eligibleIds, $pairDistances, $maximumDistance);
        $generation = DB::transaction(function () use ($context, $groups): FaceClusterGeneration {
            $this->establishTenant($context);
            $generation = FaceClusterGeneration::query()->create([
                'family_space_id' => $context->familySpaceId,
                'status' => FaceClusterGenerationStatus::Building,
            ]);
            foreach ($groups as $group) {
                $cluster = FaceCluster::query()->create([
                    'family_space_id' => $context->familySpaceId,
                    'clustering_generation_id' => $generation->id,
                    'status' => FaceClusterStatus::Active,
                ]);
                DB::table('face_cluster_members')->insert(array_map(fn (string $observationId): array => [
                    'id' => (string) Str::ulid(),
                    'family_space_id' => $context->familySpaceId,
                    'face_cluster_id' => $cluster->id,
                    'face_observation_id' => $observationId,
                    'is_active' => false,
                    'created_at' => now(),
                ], $group));
            }

            return $generation;
        });

        $this->activate($context, $generation->id, $expectedActiveGenerationId);

        return DB::transaction(function () use ($context, $generation): FaceClusterGeneration {
            $this->establishTenant($context);

            return FaceClusterGeneration::query()->findOrFail($generation->id);
        });
    }

    private function activate(
        TenantOperationContext $context,
        string $newGenerationId,
        ?string $expectedActiveGenerationId,
    ): void {
        DB::transaction(function () use ($context, $newGenerationId, $expectedActiveGenerationId): void {
            $this->establishTenant($context);
            DB::table('family_spaces')->where('id', $context->familySpaceId)->lockForUpdate()->first();
            $currentActiveGenerationId = FaceClusterGeneration::query()
                ->where('status', FaceClusterGenerationStatus::Active)
                ->value('id');
            if ($currentActiveGenerationId !== $expectedActiveGenerationId) {
                throw new RuntimeException('The active face-cluster generation changed while this rebuild was running.');
            }

            $newGeneration = FaceClusterGeneration::query()->whereKey($newGenerationId)->lockForUpdate()->firstOrFail();
            if ($newGeneration->status !== FaceClusterGenerationStatus::Building) {
                throw new RuntimeException('Only a fully built face-cluster generation may be activated.');
            }

            if ($currentActiveGenerationId !== null) {
                $oldClusterIds = FaceCluster::query()
                    ->where('clustering_generation_id', $currentActiveGenerationId)
                    ->where('status', FaceClusterStatus::Active)
                    ->pluck('id');
                DB::table('face_cluster_members')->whereIn('face_cluster_id', $oldClusterIds)
                    ->where('is_active', true)->update(['is_active' => false]);
                FaceCluster::query()->whereIn('id', $oldClusterIds)->update([
                    'status' => FaceClusterStatus::Superseded,
                    'updated_at' => now(),
                ]);
            }

            $now = now();
            DB::update(<<<'SQL'
UPDATE face_cluster_generations
SET status = CASE WHEN id = ? THEN 'active' ELSE 'superseded' END,
    activated_at = CASE WHEN id = ? THEN ? ELSE activated_at END,
    superseded_at = CASE WHEN id = ? THEN superseded_at ELSE ? END
WHERE family_space_id = ?
  AND (id = ? OR status = 'active')
SQL, [
                $newGenerationId,
                $newGenerationId,
                $now,
                $newGenerationId,
                $now,
                $context->familySpaceId,
                $newGenerationId,
            ]);
            DB::table('face_cluster_members')
                ->whereIn('face_cluster_id', FaceCluster::query()
                    ->where('clustering_generation_id', $newGenerationId)->select('id'))
                ->update(['is_active' => true]);
        });
    }

    /** @return Builder<FaceObservation> */
    private function eligibleObservations(string $familySpaceId): Builder
    {
        $identity = $this->activeIdentity();
        $query = FaceObservation::query()
            ->select('face_observations.*')
            ->join('face_analysis_runs', function ($join): void {
                $join->on('face_analysis_runs.id', '=', 'face_observations.face_analysis_run_id')
                    ->on('face_analysis_runs.family_space_id', '=', 'face_observations.family_space_id');
            })
            ->join('face_embedding_projections', function ($join): void {
                $join->on('face_embedding_projections.face_observation_id', '=', 'face_observations.id')
                    ->on('face_embedding_projections.family_space_id', '=', 'face_observations.family_space_id');
            })
            ->where('face_observations.family_space_id', $familySpaceId)
            ->where('face_embedding_projections.projection_version', config('face_recognition.projection_version'))
            ->whereColumn('face_embedding_projections.embedding_dimension', 'face_observations.embedding_dimension')
            ->whereRaw("face_embedding_projections.source_checksum = encode(sha256(face_observations.embedding), 'hex')")
            ->where('face_analysis_runs.status', 'succeeded')
            ->where('face_analysis_runs.provider', $identity->provider)
            ->where('face_analysis_runs.model_identifier', $identity->modelIdentifier)
            ->where('face_analysis_runs.model_weight_checksum', $identity->modelWeightChecksum)
            ->where('face_analysis_runs.config_hash', $identity->configHash)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('face_cluster_members as retired_members')
                    ->join('face_clusters as retired_clusters', function ($join): void {
                        $join->on('retired_clusters.id', '=', 'retired_members.face_cluster_id')
                            ->on('retired_clusters.family_space_id', '=', 'retired_members.family_space_id');
                    })
                    ->whereColumn('retired_members.face_observation_id', 'face_observations.id')
                    ->where('retired_clusters.status', FaceClusterStatus::Retired->value);
            })
            ->orderBy('face_observations.id');

        if (Schema::hasTable('face_identity_assignments')) {
            $query->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('face_identity_assignments')
                    ->whereColumn('face_identity_assignments.face_observation_id', 'face_observations.id')
                    ->whereColumn('face_identity_assignments.family_space_id', 'face_observations.family_space_id')
                    ->whereIn('face_identity_assignments.status', ['pending', 'approved']);
            });
        }

        return $query;
    }

    private function activeIdentity(): EmbeddingSpaceIdentity
    {
        $identity = config('image-analysis.identity');
        if (! is_array($identity)) {
            throw new InvalidArgumentException('The active face-analysis identity is not configured.');
        }

        return new EmbeddingSpaceIdentity(
            (string) $identity['provider'],
            (string) $identity['model_identifier'],
            (string) $identity['model_weight_checksum'],
            (string) $identity['config_hash'],
        );
    }

    private function establishTenant(TenantOperationContext $context): void
    {
        $this->tenant->establishUser($context->actorUserId);
        $this->tenant->establishFamilySpace($context->familySpaceId);
    }

    private function binary(mixed $value): string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException('FaceObservation embedding is not readable binary data.');
        }

        return $value;
    }
}
