<?php

namespace App\Services;

use App\Enums\FaceAnalysisAttemptStatus;
use App\Enums\FaceAnalysisFailureCategory;
use App\Enums\FaceAnalysisRunStatus;
use App\Enums\MediaUploadState;
use App\FaceAnalysis\FaceAnalysisMessageValidator;
use App\FaceAnalysis\FaceAnalysisRequestPublisher;
use App\FaceAnalysis\FaceAnalysisResultAuthority;
use App\FaceAnalysis\FaceAnalysisResultValidator;
use App\FaceAnalysis\InvalidFaceAnalysisMessage;
use App\FaceAnalysis\InvalidFaceAnalysisResult;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaObjectStorage;
use App\Media\MediaSigningAudience;
use App\Models\FaceAnalysisAttempt;
use App\Models\FaceAnalysisRun;
use App\Models\FaceObservation;
use App\Models\MediaUpload;
use App\Storage\FamilyStorageKey;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FaceAnalysisPipeline
{
    public function __construct(
        private readonly FaceAnalysisRequestPublisher $publisher,
        private readonly FaceAnalysisResultAuthority $resultAuthority,
        private readonly MediaDeliveryUrlSigner $deliverySigner,
        private readonly MediaObjectStorage $storage,
        private readonly FaceAnalysisMessageValidator $messages,
        private readonly FaceAnalysisResultValidator $results,
        private readonly DatabaseTenantContext $databaseTenantContext,
    ) {}

    public function dispatch(TenantOperationContext $context, string $mediaUploadId, string $canonicalSha256): void
    {
        $dispatch = DB::transaction(function () use ($context, $mediaUploadId, $canonicalSha256): ?array {
            $this->establishContext($context);
            $upload = MediaUpload::query()->lockForUpdate()->find($mediaUploadId);
            if ($upload === null
                || ! in_array($upload->state, [MediaUploadState::Processing, MediaUploadState::Ready, MediaUploadState::Degraded], true)
                || $upload->canonical_object_key === null
                || $upload->canonical_mime_type === null
                || ! hash_equals((string) $upload->canonical_sha256, $canonicalSha256)) {
                return null;
            }

            $identity = $this->identity();
            $runIdentity = [
                'family_space_id' => $context->familySpaceId,
                'media_upload_id' => $upload->id,
                'canonical_sha256' => $canonicalSha256,
                'provider' => $identity['provider'],
                'model_identifier' => $identity['model_identifier'],
                'model_weight_checksum' => $identity['model_weight_checksum'],
                'config_hash' => $identity['config_hash'],
            ];
            FaceAnalysisRun::query()->insertOrIgnore([$runIdentity + [
                'id' => (string) Str::ulid(),
                'contract_version' => (string) config('image-analysis.contract_version'),
                'status' => FaceAnalysisRunStatus::Pending->value,
                'attempt_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]]);
            $run = FaceAnalysisRun::query()->where($runIdentity)->lockForUpdate()->firstOrFail();
            if ($run->status === FaceAnalysisRunStatus::Succeeded) {
                return null;
            }

            $attempt = FaceAnalysisAttempt::query()
                ->where('face_analysis_run_id', $run->id)
                ->where('status', FaceAnalysisAttemptStatus::Dispatched)
                ->latest('dispatched_at')
                ->first();
            if ($attempt === null) {
                if ($run->attempt_count >= (int) config('image-analysis.max_attempts_per_run')) {
                    $run->update(['status' => FaceAnalysisRunStatus::Failed, 'failed_at' => now()]);

                    return null;
                }
                $attemptId = (string) Str::ulid();
                $attempt = FaceAnalysisAttempt::query()->create([
                    'id' => $attemptId,
                    'family_space_id' => $context->familySpaceId,
                    'face_analysis_run_id' => $run->id,
                    'expected_result_object_key' => FamilyStorageKey::for(
                        $context->familySpaceId,
                        "face-analysis/{$attemptId}/result.json",
                    ),
                    'status' => FaceAnalysisAttemptStatus::Dispatched,
                    'dispatched_at' => now(),
                ]);
                $run->update([
                    'status' => FaceAnalysisRunStatus::Processing,
                    'attempt_count' => $run->attempt_count + 1,
                    'failed_at' => null,
                ]);
            }

            return compact('upload', 'attempt', 'identity');
        });
        if ($dispatch === null) {
            return;
        }

        /** @var MediaUpload $upload */
        $upload = $dispatch['upload'];
        /** @var FaceAnalysisAttempt $attempt */
        $attempt = $dispatch['attempt'];
        $expiresAt = now()->addMinutes((int) config('image-analysis.authority_ttl_minutes'));
        $canonical = $this->deliverySigner->authorizeRead(
            (string) $upload->canonical_object_key,
            (string) $upload->canonical_mime_type,
            $expiresAt,
            MediaSigningAudience::Service,
        );
        $result = $this->resultAuthority->authorizeWrite(
            $attempt->expected_result_object_key,
            $expiresAt,
        );
        $this->publisher->publish([
            'contract_version' => (string) config('image-analysis.contract_version'),
            'request_id' => $attempt->id,
            'family_space_id' => $context->familySpaceId,
            'media_upload_id' => $upload->id,
            'canonical_sha256' => $canonicalSha256,
            'canonical_get_authority' => [
                'url' => $canonical->url,
                'headers' => (object) [],
                'expires_at' => $canonical->expiresAt->toIso8601String(),
            ],
            'result_put_authority' => [
                'url' => $result->url,
                'headers' => $result->headers,
                'expires_at' => $result->expiresAt->toIso8601String(),
            ],
            'analysis_identity' => $dispatch['identity'],
            'correlation_id' => $context->correlationId,
            'traceparent' => $context->traceparent,
        ]);
    }

    public function complete(TenantOperationContext $context, string $body): void
    {
        $message = $this->messages->decode($body, 'completed');
        $snapshot = $this->attemptSnapshot($context, $message);
        if ($snapshot === null) {
            return;
        }
        /** @var FaceAnalysisAttempt $attempt */
        $attempt = $snapshot['attempt'];
        /** @var MediaUpload $upload */
        $upload = $snapshot['upload'];
        if (! hash_equals($attempt->expected_result_object_key, (string) $message['result_object_key'])) {
            $this->failAttempt($context, $attempt->id, FaceAnalysisFailureCategory::ResultArtifactInvalid, 'Unexpected result object key.');

            return;
        }
        $stored = $this->storage->inspect($attempt->expected_result_object_key);
        if ($stored === null) {
            $this->failAttempt($context, $attempt->id, FaceAnalysisFailureCategory::ResultArtifactInvalid, 'Result artifact is unavailable.');

            return;
        }
        if ($stored->byteSize > (int) config('image-analysis.result.max_bytes')) {
            $this->failAttempt($context, $attempt->id, FaceAnalysisFailureCategory::ResultArtifactOversized, 'Result artifact exceeds its byte limit.');
            $this->storage->delete($attempt->expected_result_object_key);

            return;
        }

        $path = tempnam(sys_get_temp_dir(), 'fambam-face-result-');
        if ($path === false) {
            throw new \RuntimeException('A temporary face-analysis result file could not be created.');
        }
        chmod($path, 0600);
        try {
            $this->storage->downloadTo($attempt->expected_result_object_key, $path);
            $payload = file_get_contents($path);
            $checksum = hash_file('sha256', $path);
            if (! is_string($payload) || $checksum === false || ! hash_equals((string) $message['result_sha256'], $checksum)) {
                $this->failAttempt($context, $attempt->id, FaceAnalysisFailureCategory::ResultChecksumMismatch, 'Result artifact checksum mismatch.');

                return;
            }
            try {
                $validated = $this->results->validate(
                    $payload,
                    (int) $upload->pixel_width,
                    (int) $upload->pixel_height,
                    (int) $message['detected_face_count'],
                );
            } catch (InvalidFaceAnalysisResult $exception) {
                $this->failAttempt($context, $attempt->id, $exception->category, $exception->getMessage());

                return;
            }

            DB::transaction(function () use ($context, $attempt, $validated): void {
                $this->establishContext($context);
                $locked = FaceAnalysisAttempt::query()->lockForUpdate()->find($attempt->id);
                if ($locked === null || $locked->status !== FaceAnalysisAttemptStatus::Dispatched) {
                    return;
                }
                $run = FaceAnalysisRun::query()->lockForUpdate()->findOrFail($locked->face_analysis_run_id);
                if ($run->status === FaceAnalysisRunStatus::Succeeded) {
                    $locked->update(['status' => FaceAnalysisAttemptStatus::Superseded, 'resolved_at' => now()]);

                    return;
                }
                foreach ($validated->faces as $index => $face) {
                    FaceObservation::query()->create([
                        'family_space_id' => $context->familySpaceId,
                        'face_analysis_run_id' => $run->id,
                        'face_index' => $index,
                        'bounds_x' => $face['bounds']['x'],
                        'bounds_y' => $face['bounds']['y'],
                        'bounds_width' => $face['bounds']['width'],
                        'bounds_height' => $face['bounds']['height'],
                        'landmarks' => $face['landmarks'],
                        'landmark_scheme' => $face['landmark_scheme'],
                        'detection_confidence' => $face['detection_confidence'],
                        'embedding' => pack('g*', ...$face['embedding']),
                        'embedding_dimension' => $face['embedding_dimension'],
                        'embedding_dtype' => $face['embedding_dtype'],
                        'quality_signals' => $face['quality_signals'] ?? [],
                        'provider_diagnostics' => $face['provider_diagnostics'] ?? null,
                    ]);
                }
                $locked->update(['status' => FaceAnalysisAttemptStatus::Succeeded, 'resolved_at' => now()]);
                $run->update(['status' => FaceAnalysisRunStatus::Succeeded, 'succeeded_at' => now(), 'failed_at' => null]);
            });
        } finally {
            @unlink($path);
            $this->storage->delete($attempt->expected_result_object_key);
        }
    }

    public function fail(TenantOperationContext $context, string $body): void
    {
        $message = $this->messages->decode($body, 'failed');
        if ($this->attemptSnapshot($context, $message) === null) {
            return;
        }
        $this->failAttempt(
            $context,
            (string) $message['request_id'],
            FaceAnalysisFailureCategory::from((string) $message['failure_category']),
            (string) $message['failure_detail'],
        );
    }

    public function timeout(TenantOperationContext $context, string $attemptId): void
    {
        $this->failAttempt($context, $attemptId, FaceAnalysisFailureCategory::AttemptTimedOut, 'Analysis attempt exceeded its staleness window.');
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{attempt: FaceAnalysisAttempt, upload: MediaUpload}|null
     */
    private function attemptSnapshot(TenantOperationContext $context, array $message): ?array
    {
        return DB::transaction(function () use ($context, $message): ?array {
            $this->establishContext($context);
            $attempt = FaceAnalysisAttempt::query()->with('run.mediaUpload')->find((string) $message['request_id']);
            if ($attempt === null || $attempt->status !== FaceAnalysisAttemptStatus::Dispatched) {
                return null;
            }
            $run = $attempt->run;
            $upload = $run->mediaUpload;
            $identity = $message['analysis_identity'];
            if (! hash_equals($context->familySpaceId, (string) $message['family_space_id'])
                || ! hash_equals($run->media_upload_id, (string) $message['media_upload_id'])
                || ! hash_equals($run->canonical_sha256, (string) $message['canonical_sha256'])
                || $run->contract_version !== $message['contract_version']
                || $run->provider !== $identity['provider']
                || $run->model_identifier !== $identity['model_identifier']
                || ! hash_equals($run->model_weight_checksum, $identity['model_weight_checksum'])
                || ! hash_equals($run->config_hash, $identity['config_hash'])) {
                throw new InvalidFaceAnalysisMessage('Message identity does not match its persisted attempt.');
            }
            if (! in_array($upload->state, [MediaUploadState::Processing, MediaUploadState::Ready, MediaUploadState::Degraded], true)
                || $upload->canonical_object_key === null
                || ! hash_equals((string) $upload->canonical_sha256, $run->canonical_sha256)) {
                throw new InvalidFaceAnalysisMessage('MediaUpload is no longer eligible for this analysis result.');
            }

            return compact('attempt', 'upload');
        });
    }

    private function failAttempt(TenantOperationContext $context, string $attemptId, FaceAnalysisFailureCategory $category, string $detail): void
    {
        /** @var array{media_upload_id: string, canonical_sha256: string}|null $retry */
        $retry = DB::transaction(function () use ($context, $attemptId, $category, $detail): ?array {
            $this->establishContext($context);
            $attempt = FaceAnalysisAttempt::query()->lockForUpdate()->find($attemptId);
            if ($attempt === null || $attempt->status !== FaceAnalysisAttemptStatus::Dispatched) {
                return null;
            }
            $run = FaceAnalysisRun::query()->lockForUpdate()->findOrFail($attempt->face_analysis_run_id);
            if ($run->status === FaceAnalysisRunStatus::Succeeded) {
                $attempt->update([
                    'status' => FaceAnalysisAttemptStatus::Superseded,
                    'resolved_at' => now(),
                ]);

                return null;
            }
            $attempt->update([
                'status' => FaceAnalysisAttemptStatus::Failed,
                'failure_category' => $category,
                'failure_detail' => Str::limit($detail, 512, ''),
                'resolved_at' => now(),
            ]);
            if ($run->attempt_count >= (int) config('image-analysis.max_attempts_per_run')) {
                $run->update(['status' => FaceAnalysisRunStatus::Failed, 'failed_at' => now()]);

                return null;
            }
            $run->update(['status' => FaceAnalysisRunStatus::Processing, 'failed_at' => null]);

            return ['media_upload_id' => $run->media_upload_id, 'canonical_sha256' => $run->canonical_sha256];
        });
        if ($retry !== null) {
            $this->dispatch($context, $retry['media_upload_id'], $retry['canonical_sha256']);
        }
    }

    /** @return array{provider: string, model_identifier: string, model_weight_checksum: string, config_hash: string} */
    private function identity(): array
    {
        /** @var array{provider: string, model_identifier: string, model_weight_checksum: string, config_hash: string} $identity */
        $identity = config('image-analysis.identity');

        return $identity;
    }

    private function establishContext(TenantOperationContext $context): void
    {
        $this->databaseTenantContext->establishUser($context->actorUserId);
        $this->databaseTenantContext->establishFamilySpace($context->familySpaceId);
    }
}
