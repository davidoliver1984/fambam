<?php

namespace App\FaceRecognition;

use App\Enums\FaceClusterGenerationStatus;
use App\Enums\FaceClusterStatus;
use App\Enums\FaceIdentityAssignmentStatus;
use App\Enums\FamilySpaceRole;
use App\Models\FaceCluster;
use App\Models\FaceClusterGeneration;
use App\Models\FaceIdentityAssignment;
use App\Models\FaceObservation;
use App\Models\Person;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FaceClusterReviewManager
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly FaceIdentityAssignmentManager $assignments,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param list<FaceCluster> $clusters */
    public function merge(array $clusters, User $actor, Request $request): FaceCluster
    {
        return DB::transaction(function () use ($clusters, $actor, $request): FaceCluster {
            $this->authorizeResolution($actor);
            if (count($clusters) < 2) {
                $this->fail('Choose at least two active clusters to merge.');
            }
            $locked = $this->lockActiveClusters(array_map(fn (FaceCluster $cluster): string => $cluster->id, $clusters));
            $generationId = $locked->pluck('clustering_generation_id')->unique()->sole();
            $observationIds = DB::table('face_cluster_members')->whereIn('face_cluster_id', $locked->pluck('id'))
                ->where('is_active', true)->orderBy('face_observation_id')->pluck('face_observation_id')->unique()->values()->all();
            $this->retireClusters($locked->pluck('id')->all());
            $merged = $this->createActiveCluster((string) $generationId, $observationIds);
            $this->audit->record('face_cluster.merged', $merged, $actor, $request, [
                'source_cluster_ids' => $locked->pluck('id')->all(),
                'member_count' => count($observationIds),
            ]);

            return $merged;
        });
    }

    /**
     * @param  list<list<string>>  $groups
     * @return list<FaceCluster>
     */
    public function split(FaceCluster $cluster, array $groups, User $actor, Request $request): array
    {
        return DB::transaction(function () use ($cluster, $groups, $actor, $request): array {
            $this->authorizeResolution($actor);
            if (count($groups) < 2 || in_array([], $groups, true)) {
                $this->fail('A split requires at least two non-empty groups.');
            }
            $locked = $this->lockActiveClusters([$cluster->id])->sole();
            $current = DB::table('face_cluster_members')->where('face_cluster_id', $locked->id)
                ->where('is_active', true)->orderBy('face_observation_id')->pluck('face_observation_id')->all();
            $submitted = array_merge(...$groups);
            sort($submitted, SORT_STRING);
            if ($submitted !== $current || count($submitted) !== count(array_unique($submitted))) {
                $this->fail('Split groups must partition every active cluster member exactly once.');
            }
            $this->retireClusters([$locked->id]);
            $created = array_map(
                fn (array $group): FaceCluster => $this->createActiveCluster($locked->clustering_generation_id, $group),
                $groups,
            );
            $this->audit->record('face_cluster.split', $locked, $actor, $request, [
                'replacement_cluster_ids' => array_map(fn (FaceCluster $item): string => $item->id, $created),
            ]);

            return $created;
        });
    }

    public function proposeName(FaceCluster $cluster, Person $person, User $actor, Request $request): int
    {
        return DB::transaction(function () use ($cluster, $person, $actor, $request): int {
            $this->authorizeProposal($actor);
            $locked = $this->lockActiveClusters([$cluster->id])->sole();
            $count = 0;
            foreach ($this->activeObservations($locked) as $observation) {
                $this->assignments->propose($observation, $person, $actor, $request);
                $count++;
            }

            return $count;
        });
    }

    public function confirmName(FaceCluster $cluster, Person $person, User $actor, Request $request): int
    {
        return DB::transaction(function () use ($cluster, $person, $actor, $request): int {
            $this->authorizeResolution($actor);
            $locked = $this->lockActiveClusters([$cluster->id])->sole();
            $count = 0;
            foreach ($this->activeObservations($locked) as $observation) {
                $assignment = FaceIdentityAssignment::query()
                    ->where('face_observation_id', $observation->id)
                    ->where('person_id', $person->id)
                    ->where('status', FaceIdentityAssignmentStatus::Pending)
                    ->lockForUpdate()->first();
                if ($assignment === null) {
                    $assignment = $this->assignments->propose($observation, $person, $actor, $request);
                }
                $this->assignments->approve($assignment, $actor, $request);
                $count++;
            }
            $this->retireClusters([$locked->id]);
            $this->audit->record('face_cluster.named_and_retired', $locked, $actor, $request, [
                'person_id' => $person->id,
                'assignment_count' => $count,
            ]);

            return $count;
        });
    }

    /**
     * @param  list<string>  $clusterIds
     * @return Collection<int, FaceCluster>
     */
    private function lockActiveClusters(array $clusterIds): Collection
    {
        $generation = FaceClusterGeneration::query()
            ->where('family_space_id', $this->tenant->familySpace()->id)
            ->where('status', FaceClusterGenerationStatus::Active)->lockForUpdate()->firstOrFail();
        $clusters = FaceCluster::query()->whereIn('id', array_unique($clusterIds))
            ->where('family_space_id', $this->tenant->familySpace()->id)
            ->where('clustering_generation_id', $generation->id)
            ->where('status', FaceClusterStatus::Active)->orderBy('id')->lockForUpdate()->get();
        if ($clusters->count() !== count(array_unique($clusterIds))) {
            $this->fail('Cluster review may act only on active clusters in the current generation.');
        }

        return $clusters;
    }

    /** @return Collection<int, FaceObservation> */
    private function activeObservations(FaceCluster $cluster): Collection
    {
        return FaceObservation::query()->whereIn('id', DB::table('face_cluster_members')
            ->where('face_cluster_id', $cluster->id)->where('is_active', true)->select('face_observation_id'))
            ->where('family_space_id', $this->tenant->familySpace()->id)
            ->orderBy('id')->get();
    }

    /** @param list<string> $clusterIds */
    private function retireClusters(array $clusterIds): void
    {
        DB::table('face_cluster_members')->whereIn('face_cluster_id', $clusterIds)
            ->where('is_active', true)->update(['is_active' => false]);
        FaceCluster::query()->whereIn('id', $clusterIds)->update([
            'status' => FaceClusterStatus::Retired,
            'updated_at' => now(),
        ]);
    }

    /** @param list<string> $observationIds */
    private function createActiveCluster(string $generationId, array $observationIds): FaceCluster
    {
        $cluster = FaceCluster::query()->create([
            'family_space_id' => $this->tenant->familySpace()->id,
            'clustering_generation_id' => $generationId,
            'status' => FaceClusterStatus::Active,
        ]);
        DB::table('face_cluster_members')->insert(array_map(fn (string $observationId): array => [
            'id' => (string) Str::ulid(),
            'family_space_id' => $this->tenant->familySpace()->id,
            'face_cluster_id' => $cluster->id,
            'face_observation_id' => $observationId,
            'is_active' => true,
            'created_at' => now(),
        ], $observationIds));

        return $cluster;
    }

    private function authorizeProposal(User $actor): void
    {
        $membership = $this->tenant->membership();
        if ($membership->user_id !== $actor->id || ! in_array($membership->role, [
            FamilySpaceRole::Owner,
            FamilySpaceRole::Administrator,
            FamilySpaceRole::Member,
        ], true)) {
            throw new AuthorizationException;
        }
    }

    private function authorizeResolution(User $actor): void
    {
        if ($this->tenant->membership()->user_id !== $actor->id
            || ! $this->tenant->membership()->role->canManageMembers()) {
            throw new AuthorizationException;
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['face_cluster' => [$message]]);
    }
}
