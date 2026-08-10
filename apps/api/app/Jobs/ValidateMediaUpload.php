<?php

namespace App\Jobs;

use App\Services\MediaValidationManager;
use App\Tenancy\TenantOperationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

class ValidateMediaUpload implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /** @param array{family_space_id: string, actor_user_id: int, correlation_id: string, traceparent: string} $context */
    public function __construct(
        public array $context,
        public string $mediaUploadId,
    ) {}

    public function uniqueId(): string
    {
        return "validate:{$this->mediaUploadId}";
    }

    public function handle(MediaValidationManager $validation): void
    {
        $context = TenantOperationContext::fromArray($this->context);
        $parent = Globals::propagator()->extract(['traceparent' => $context->traceparent]);
        $span = Globals::tracerProvider()
            ->getTracer('fambam-api')
            ->spanBuilder('media-upload.validation')
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setParent($parent)
            ->startSpan();
        $scope = $span->activate();
        Log::withContext($context->toArray() + ['media_upload_id' => $this->mediaUploadId]);

        try {
            $validation->validate($context, $this->mediaUploadId);
        } finally {
            Log::withoutContext([...array_keys($context->toArray()), 'media_upload_id']);
            $scope->detach();
            $span->end();
        }
    }

    public function failed(Throwable $exception): void
    {
        app(MediaValidationManager::class)->markFailed(
            TenantOperationContext::fromArray($this->context),
            $this->mediaUploadId,
        );
    }
}
