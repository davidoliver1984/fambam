<?php

namespace App\FaceRecognition;

use App\Enums\FaceIdentityAssignmentStatus;
use App\Enums\FaceSuggestionBand;
use App\Models\FaceIdentityAssignment;
use App\Models\FaceIdentitySuppression;
use App\Models\FaceObservation;
use App\Models\Person;
use App\Services\AuditRecorder;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Support\Facades\DB;
use OpenTelemetry\API\Globals;
use RuntimeException;

final class FaceSuggestionGenerator
{
    public function __construct(
        private readonly SimilaritySearch $similarity,
        private readonly Float32Embedding $embeddings,
        private readonly DatabaseTenantContext $tenant,
        private readonly AuditRecorder $audit,
    ) {}

    public function generate(TenantOperationContext $context, string $faceObservationId): FaceSuggestionOutcome
    {
        if (! config('face_recognition.processing_enabled')) {
            throw new RuntimeException('Automatic face-recognition processing remains disabled until calibration.');
        }
        $thresholds = $this->thresholds();
        $startedAt = hrtime(true);

        $outcome = DB::transaction(function () use ($context, $faceObservationId, $thresholds): FaceSuggestionOutcome {
            $this->tenant->establishUser($context->actorUserId);
            $this->tenant->establishFamilySpace($context->familySpaceId);
            $observation = FaceObservation::query()->lockForUpdate()->findOrFail($faceObservationId);
            if (! $this->hasPhoto($observation)
                || FaceIdentityAssignment::query()->where('face_observation_id', $observation->id)
                    ->whereIn('status', ['pending', 'approved'])->exists()) {
                return new FaceSuggestionOutcome(FaceSuggestionBand::None, []);
            }

            $identity = $this->identityFor($observation);
            if (! $this->isActiveIdentity($identity)) {
                return new FaceSuggestionOutcome(FaceSuggestionBand::None, []);
            }
            $embedding = $this->embeddings->decode(
                $this->binary($observation->getRawOriginal('embedding')),
                $observation->embedding_dimension,
            );
            $matches = $this->similarity->nearestTrustedReferences(
                $context->familySpaceId,
                $identity,
                $embedding,
                (int) config('face_recognition.similarity_max_results'),
            );
            $candidates = $this->aggregate($matches);
            $allowedPersonIds = Person::query()
                ->whereIn('id', array_map(fn (FaceCandidate $candidate): string => $candidate->personId, $candidates))
                ->where('family_space_id', $context->familySpaceId)
                ->where('recognition_allowed', true)
                ->pluck('id')->all();
            $candidates = array_values(array_filter(
                $candidates,
                fn (FaceCandidate $candidate): bool => in_array($candidate->personId, $allowedPersonIds, true),
            ));
            $suppressedPersonIds = FaceIdentitySuppression::query()
                ->where('face_observation_id', $observation->id)
                ->whereNull('reopened_at')
                ->pluck('person_id')
                ->all();
            $candidates = array_values(array_filter(
                $candidates,
                fn (FaceCandidate $candidate): bool => ! in_array($candidate->personId, $suppressedPersonIds, true),
            ));
            $shortlist = array_values(array_filter(
                $candidates,
                fn (FaceCandidate $candidate): bool => $candidate->bestCosineDistance <= $thresholds['shortlist'],
            ));
            if ($shortlist === []) {
                return new FaceSuggestionOutcome(FaceSuggestionBand::None, []);
            }

            $best = $shortlist[0];
            $runnerUp = $shortlist[1] ?? null;
            $unambiguous = $runnerUp === null
                || $runnerUp->bestCosineDistance - $best->bestCosineDistance >= $thresholds['margin'];
            if ($best->bestCosineDistance <= $thresholds['strong']
                && $best->referenceCount >= $thresholds['minimum_references']
                && $unambiguous) {
                $assignment = FaceIdentityAssignment::query()->create([
                    'family_space_id' => $context->familySpaceId,
                    'face_observation_id' => $observation->id,
                    'person_id' => $best->personId,
                    'proposal_source' => 'automatic_suggestion',
                    'status' => FaceIdentityAssignmentStatus::Pending,
                ]);
                $this->audit->record(
                    'face_identity_assignment.proposed',
                    $assignment,
                    metadata: [
                        'face_observation_id' => $observation->id,
                        'person_id' => $best->personId,
                        'proposal_source' => 'automatic_suggestion',
                    ],
                    operationContext: $context,
                );

                return new FaceSuggestionOutcome(FaceSuggestionBand::Strong, [$best], $assignment->id);
            }

            return new FaceSuggestionOutcome(FaceSuggestionBand::Shortlist, $shortlist);
        });
        $meter = Globals::meterProvider()->getMeter('fambam-api');
        $attributes = ['face.suggestion.band' => $outcome->band->value];
        $meter->createCounter('face.recognition.suggestions')->add(1, $attributes);
        $meter->createHistogram('face.recognition.candidate_count')->record(count($outcome->candidates), $attributes);
        $meter->createHistogram('face.recognition.scoring.duration')->record(
            (hrtime(true) - $startedAt) / 1_000_000_000,
            $attributes,
        );

        return $outcome;
    }

    /**
     * @param  list<TrustedReferenceMatch>  $matches
     * @return list<FaceCandidate>
     */
    private function aggregate(array $matches): array
    {
        $people = [];
        foreach ($matches as $match) {
            $current = $people[$match->personId] ?? ['best' => $match->cosineDistance, 'count' => 0];
            $people[$match->personId] = [
                'best' => min($current['best'], $match->cosineDistance),
                'count' => $current['count'] + 1,
            ];
        }
        $candidates = [];
        foreach ($people as $personId => $result) {
            $candidates[] = new FaceCandidate($personId, $result['best'], $result['count']);
        }
        usort($candidates, function (FaceCandidate $left, FaceCandidate $right): int {
            if ($left->bestCosineDistance === $right->bestCosineDistance) {
                return strcmp($left->personId, $right->personId);
            }

            return $left->bestCosineDistance < $right->bestCosineDistance ? -1 : 1;
        });

        return $candidates;
    }

    /** @return array{strong: float, shortlist: float, margin: float, minimum_references: int} */
    private function thresholds(): array
    {
        $values = [
            'strong' => config('face_recognition.suggestion_strong_max_distance'),
            'shortlist' => config('face_recognition.suggestion_shortlist_max_distance'),
            'margin' => config('face_recognition.suggestion_ambiguity_margin'),
            'minimum_references' => config('face_recognition.suggestion_minimum_strong_references'),
        ];
        if (! is_numeric($values['strong']) || ! is_numeric($values['shortlist'])
            || ! is_numeric($values['margin']) || ! is_numeric($values['minimum_references'])) {
            throw new RuntimeException('Face suggestion thresholds have not been calibrated.');
        }
        $thresholds = [
            'strong' => (float) $values['strong'],
            'shortlist' => (float) $values['shortlist'],
            'margin' => (float) $values['margin'],
            'minimum_references' => (int) $values['minimum_references'],
        ];
        if ($thresholds['strong'] < 0 || $thresholds['shortlist'] > 2
            || $thresholds['strong'] > $thresholds['shortlist'] || $thresholds['margin'] < 0
            || $thresholds['minimum_references'] < 1) {
            throw new RuntimeException('Face suggestion thresholds are invalid.');
        }

        return $thresholds;
    }

    private function hasPhoto(FaceObservation $observation): bool
    {
        return DB::table('photos')->join('face_analysis_runs', function ($join): void {
            $join->on('face_analysis_runs.media_upload_id', '=', 'photos.media_upload_id')
                ->on('face_analysis_runs.family_space_id', '=', 'photos.family_space_id');
        })->where('face_analysis_runs.id', $observation->face_analysis_run_id)
            ->where('photos.family_space_id', $observation->family_space_id)
            ->whereNull('photos.deleted_at')->exists();
    }

    private function identityFor(FaceObservation $observation): EmbeddingSpaceIdentity
    {
        $run = DB::table('face_analysis_runs')->where('id', $observation->face_analysis_run_id)->first();
        if ($run === null) {
            throw new RuntimeException('FaceObservation provenance is missing.');
        }

        return new EmbeddingSpaceIdentity(
            (string) $run->provider,
            (string) $run->model_identifier,
            (string) $run->model_weight_checksum,
            (string) $run->config_hash,
        );
    }

    private function isActiveIdentity(EmbeddingSpaceIdentity $identity): bool
    {
        $active = config('image-analysis.identity');

        return is_array($active)
            && $identity->provider === $active['provider']
            && $identity->modelIdentifier === $active['model_identifier']
            && $identity->modelWeightChecksum === $active['model_weight_checksum']
            && $identity->configHash === $active['config_hash'];
    }

    private function binary(mixed $value): string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }
        if (! is_string($value)) {
            throw new RuntimeException('FaceObservation embedding is not readable binary data.');
        }

        return $value;
    }
}
