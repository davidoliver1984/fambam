<?php

namespace Tests\Feature;

use App\Enums\FaceAnalysisAttemptStatus;
use App\Enums\FaceAnalysisRunStatus;
use App\Enums\MediaUploadState;
use App\FaceAnalysis\FaceAnalysisRequestPublisher;
use App\FaceAnalysis\FaceAnalysisResultAuthority;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaObjectStorage;
use App\Media\MediaSigningAudience;
use App\Media\StoredObject;
use App\Media\UploadAuthorization;
use App\Models\FaceAnalysisAttempt;
use App\Models\FaceAnalysisRun;
use App\Models\FamilySpace;
use App\Models\MediaUpload;
use App\Models\User;
use App\Services\FaceAnalysisPipeline;
use App\Tenancy\TenantOperationContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaceAnalysisPipelineTest extends TestCase
{
    use RefreshDatabase;

    private PipelinePublisher $publisher;

    private PipelineStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publisher = new PipelinePublisher;
        $this->storage = new PipelineStorage;
        $this->app->instance(FaceAnalysisRequestPublisher::class, $this->publisher);
        $this->app->instance(FaceAnalysisResultAuthority::class, new PipelineResultAuthority);
        $this->app->instance(MediaDeliveryUrlSigner::class, new PipelineDeliverySigner);
        $this->app->instance(MediaObjectStorage::class, $this->storage);
    }

    public function test_dispatch_is_logically_idempotent_and_attempt_precedes_publish(): void
    {
        [$context, $upload] = $this->fixture();
        $pipeline = app(FaceAnalysisPipeline::class);

        $pipeline->dispatch($context, $upload->id, (string) $upload->canonical_sha256);
        $pipeline->dispatch($context, $upload->id, (string) $upload->canonical_sha256);

        $this->assertDatabaseCount('face_analysis_runs', 1);
        $this->assertDatabaseCount('face_analysis_attempts', 1);
        $this->assertCount(2, $this->publisher->messages);
        $this->assertSame(
            $this->publisher->messages[0]['request_id'],
            $this->publisher->messages[1]['request_id'],
        );
        $encoded = json_encode($this->publisher->messages[0], JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded);
        $this->assertIsObject($decoded);
        $this->assertIsObject($decoded->canonical_get_authority->headers);
    }

    public function test_completion_persists_once_and_redelivery_is_a_no_op(): void
    {
        [$context, $upload] = $this->fixture();
        $pipeline = app(FaceAnalysisPipeline::class);
        $pipeline->dispatch($context, $upload->id, (string) $upload->canonical_sha256);
        $request = $this->publisher->messages[0];
        $artifact = json_encode(['contract_version' => '1', 'faces' => []], JSON_THROW_ON_ERROR);
        $key = $request['result_put_authority']['url'];
        $this->storage->objects[$key] = $artifact;
        $completion = $this->completion($request, $key, $artifact);

        $pipeline->complete($context, $completion);
        $pipeline->complete($context, $completion);

        $this->assertDatabaseCount('face_observations', 0);
        $this->assertSame(FaceAnalysisRunStatus::Succeeded, FaceAnalysisRun::query()->sole()->status);
        $this->assertSame(FaceAnalysisAttemptStatus::Succeeded, FaceAnalysisAttempt::query()->sole()->status);
        $this->assertSame([$key], $this->storage->deleted);
    }

    public function test_failed_attempt_retries_with_a_new_attempt_under_the_same_run(): void
    {
        [$context, $upload] = $this->fixture();
        $pipeline = app(FaceAnalysisPipeline::class);
        $pipeline->dispatch($context, $upload->id, (string) $upload->canonical_sha256);
        $first = FaceAnalysisAttempt::query()->sole();

        $pipeline->timeout($context, $first->id);

        $this->assertDatabaseCount('face_analysis_runs', 1);
        $this->assertDatabaseCount('face_analysis_attempts', 2);
        $this->assertSame(FaceAnalysisAttemptStatus::Failed, $first->fresh()->status);
        $this->assertNotSame(
            $first->id,
            FaceAnalysisAttempt::query()->where('status', FaceAnalysisAttemptStatus::Dispatched)->sole()->id,
        );
    }

    public function test_late_failure_is_superseded_after_the_run_succeeds(): void
    {
        [$context, $upload] = $this->fixture();
        $pipeline = app(FaceAnalysisPipeline::class);
        $pipeline->dispatch($context, $upload->id, (string) $upload->canonical_sha256);
        $attempt = FaceAnalysisAttempt::query()->sole();
        $attempt->run->update(['status' => FaceAnalysisRunStatus::Succeeded, 'succeeded_at' => now()]);

        $pipeline->timeout($context, $attempt->id);

        $this->assertSame(FaceAnalysisAttemptStatus::Superseded, $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->failure_category);
    }

    /** @return array{TenantOperationContext, MediaUpload} */
    private function fixture(): array
    {
        $family = FamilySpace::factory()->create();
        $user = User::factory()->create();
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'state' => MediaUploadState::Ready,
            'canonical_object_key' => "families/{$family->id}/media/upload/canonical.jpg",
            'canonical_mime_type' => 'image/jpeg',
            'canonical_sha256' => str_repeat('a', 64),
            'pixel_width' => 100,
            'pixel_height' => 100,
        ]);

        return [TenantOperationContext::forBackground($family->id, $user->id), $upload];
    }

    /** @param array<string, mixed> $request */
    private function completion(array $request, string $key, string $artifact): string
    {
        return json_encode([
            'contract_version' => $request['contract_version'],
            'request_id' => $request['request_id'],
            'family_space_id' => $request['family_space_id'],
            'media_upload_id' => $request['media_upload_id'],
            'canonical_sha256' => $request['canonical_sha256'],
            'analysis_identity' => $request['analysis_identity'],
            'result_object_key' => $key,
            'result_sha256' => hash('sha256', $artifact),
            'detected_face_count' => 0,
        ], JSON_THROW_ON_ERROR);
    }
}

class PipelinePublisher implements FaceAnalysisRequestPublisher
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    public function publish(array $message): void
    {
        $this->assertAttemptExists($message['request_id']);
        $this->messages[] = $message;
    }

    private function assertAttemptExists(mixed $requestId): void
    {
        if (! is_string($requestId) || ! FaceAnalysisAttempt::query()->whereKey($requestId)->exists()) {
            throw new \RuntimeException('Attempt was not durable before publish.');
        }
    }
}

class PipelineResultAuthority implements FaceAnalysisResultAuthority
{
    public function authorizeWrite(string $key, DateTimeInterface $expiresAt): UploadAuthorization
    {
        return new UploadAuthorization($key, ['If-None-Match' => '*'], CarbonImmutable::instance($expiresAt));
    }
}

class PipelineDeliverySigner implements MediaDeliveryUrlSigner
{
    public function authorizeRead(string $key, string $responseContentType, DateTimeInterface $expiresAt, MediaSigningAudience $audience): MediaDeliveryAuthorization
    {
        return new MediaDeliveryAuthorization('http://service/canonical', CarbonImmutable::instance($expiresAt));
    }
}

class PipelineStorage implements MediaObjectStorage
{
    /** @var array<string, string> */
    public array $objects = [];

    /** @var list<string> */
    public array $deleted = [];

    public function authorizeSingleWrite(string $key, DateTimeInterface $expiresAt, MediaSigningAudience $audience): UploadAuthorization
    {
        throw new \LogicException('Not used.');
    }

    public function inspect(string $key): ?StoredObject
    {
        return isset($this->objects[$key]) ? new StoredObject(strlen($this->objects[$key]), null) : null;
    }

    public function downloadTo(string $key, string $localPath): void
    {
        file_put_contents($localPath, $this->objects[$key]);
    }

    public function finalizeWriteOnce(string $localPath, string $key, string $sha256): void
    {
        throw new \LogicException('Not used.');
    }

    public function delete(string $key): void
    {
        $this->deleted[] = $key;
        unset($this->objects[$key]);
    }
}
