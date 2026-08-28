<?php

namespace Tests\Feature;

use App\Enums\FaceAnalysisRunStatus;
use App\Enums\FaceSuggestionBand;
use App\FaceRecognition\EmbeddingSpaceIdentity;
use App\FaceRecognition\FaceSuggestionGenerator;
use App\FaceRecognition\SimilarityMatch;
use App\FaceRecognition\SimilaritySearch;
use App\FaceRecognition\TrustedReferenceMatch;
use App\Models\FaceAnalysisRun;
use App\Models\FaceObservation;
use App\Models\FamilySpace;
use App\Models\MediaUpload;
use App\Models\Person;
use App\Models\Photo;
use App\Models\User;
use App\Tenancy\TenantOperationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class FaceSuggestionGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_reference_stays_ephemeral_but_multiple_unambiguous_references_create_pending_suggestion(): void
    {
        [$family, $owner, $run] = $this->facePhoto();
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $similarPerson = Person::factory()->create(['family_space_id' => $family->id]);
        $firstTarget = $this->observation($family, $run, 0);
        $secondTarget = $this->observation($family, $run, 1);
        $ambiguousTarget = $this->observation($family, $run, 2);
        $search = new FixtureSimilaritySearch([
            new TrustedReferenceMatch('reference-a', $person->id, 0.1),
        ]);
        $generator = $this->app->makeWith(FaceSuggestionGenerator::class, ['similarity' => $search]);
        $this->enableFixtureThresholds();
        $context = TenantOperationContext::forBackground($family->id, $owner->id);

        $shortlist = $generator->generate($context, $firstTarget->id);
        $this->assertSame(FaceSuggestionBand::Shortlist, $shortlist->band);
        $this->assertNull($shortlist->assignmentId);
        $this->assertDatabaseCount('face_identity_assignments', 0);

        $search->trusted = [
            new TrustedReferenceMatch('reference-a', $person->id, 0.1),
            new TrustedReferenceMatch('reference-b', $person->id, 0.11),
            new TrustedReferenceMatch('reference-c', $similarPerson->id, 0.12),
            new TrustedReferenceMatch('reference-d', $similarPerson->id, 0.13),
        ];
        $ambiguous = $generator->generate($context, $ambiguousTarget->id);
        $this->assertSame(FaceSuggestionBand::Shortlist, $ambiguous->band);
        $this->assertCount(2, $ambiguous->candidates);
        $this->assertNull($ambiguous->assignmentId);

        $search->trusted = [
            new TrustedReferenceMatch('reference-a', $person->id, 0.1),
            new TrustedReferenceMatch('reference-b', $person->id, 0.12),
        ];
        $strong = $generator->generate($context, $secondTarget->id);
        $this->assertSame(FaceSuggestionBand::Strong, $strong->band);
        $this->assertNotNull($strong->assignmentId);
        $this->assertDatabaseHas('face_identity_assignments', [
            'id' => $strong->assignmentId,
            'face_observation_id' => $secondTarget->id,
            'person_id' => $person->id,
            'proposal_source' => 'automatic_suggestion',
            'status' => 'pending',
            'proposed_by' => null,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'face_identity_assignment.proposed',
            'actor_user_id' => null,
        ]);
    }

    public function test_automatic_generation_fails_closed_while_processing_gate_is_disabled(): void
    {
        [$family, $owner, $run] = $this->facePhoto();
        $target = $this->observation($family, $run, 0);
        config()->set('face_recognition.processing_enabled', false);
        $generator = $this->app->makeWith(FaceSuggestionGenerator::class, [
            'similarity' => new FixtureSimilaritySearch([]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('remains disabled');
        $generator->generate(TenantOperationContext::forBackground($family->id, $owner->id), $target->id);
    }

    /** @return array{FamilySpace, User, FaceAnalysisRun} */
    private function facePhoto(): array
    {
        $family = FamilySpace::factory()->create();
        $owner = User::factory()->create();
        $upload = MediaUpload::factory()->create(['family_space_id' => $family->id, 'user_id' => $owner->id]);
        Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $owner->id,
        ]);
        $identity = config('image-analysis.identity');
        $run = FaceAnalysisRun::query()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'canonical_sha256' => str_repeat('a', 64),
            'contract_version' => '1',
            'provider' => $identity['provider'],
            'model_identifier' => $identity['model_identifier'],
            'model_weight_checksum' => $identity['model_weight_checksum'],
            'config_hash' => $identity['config_hash'],
            'status' => FaceAnalysisRunStatus::Succeeded,
            'attempt_count' => 1,
            'succeeded_at' => now(),
        ]);

        return [$family, $owner, $run];
    }

    private function observation(FamilySpace $family, FaceAnalysisRun $run, int $index): FaceObservation
    {
        return FaceObservation::query()->create([
            'family_space_id' => $family->id,
            'face_analysis_run_id' => $run->id,
            'face_index' => $index,
            'bounds_x' => 0,
            'bounds_y' => 0,
            'bounds_width' => 1,
            'bounds_height' => 1,
            'landmarks' => [],
            'landmark_scheme' => '5-point',
            'detection_confidence' => 1,
            'embedding' => pack('g*', 1.0, 0.0, 0.0),
            'embedding_dimension' => 3,
            'embedding_dtype' => 'float32',
        ]);
    }

    private function enableFixtureThresholds(): void
    {
        config()->set([
            'face_recognition.processing_enabled' => true,
            'face_recognition.suggestion_strong_max_distance' => 0.2,
            'face_recognition.suggestion_shortlist_max_distance' => 0.4,
            'face_recognition.suggestion_ambiguity_margin' => 0.05,
            'face_recognition.suggestion_minimum_strong_references' => 2,
        ]);
    }
}

class FixtureSimilaritySearch implements SimilaritySearch
{
    /** @param list<TrustedReferenceMatch> $trusted */
    public function __construct(public array $trusted) {}

    public function nearest(
        string $familySpaceId,
        EmbeddingSpaceIdentity $identity,
        array $embedding,
        int $limit,
    ): array {
        return array_map(
            fn (TrustedReferenceMatch $match): SimilarityMatch => new SimilarityMatch(
                $match->faceObservationId,
                $match->cosineDistance,
            ),
            $this->trusted,
        );
    }

    public function nearestTrustedReferences(
        string $familySpaceId,
        EmbeddingSpaceIdentity $identity,
        array $embedding,
        int $limit,
    ): array {
        return array_slice($this->trusted, 0, $limit);
    }
}
