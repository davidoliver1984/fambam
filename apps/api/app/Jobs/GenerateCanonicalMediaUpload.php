<?php

namespace App\Jobs;

use App\Services\MediaCanonicalManager;
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

class GenerateCanonicalMediaUpload implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /** @param array{family_space_id: string, actor_user_id: int, correlation_id: string, traceparent: string} $context */
    public function __construct(
        public array $context,
        public string $mediaUploadId,
        public string $sourceSha256,
    ) {}

    public function uniqueId(): string
    {
        return "canonical:{$this->mediaUploadId}:{$this->sourceSha256}";
    }

    public function handle(MediaCanonicalManager $canonical): void
    {
        $context = TenantOperationContext::fromArray($this->context);
        $parent = Globals::propagator()->extract(['traceparent' => $context->traceparent]);
        $span = Globals::tracerProvider()
            ->getTracer('fambam-api')
            ->spanBuilder('media-upload.canonical-generation')
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setParent($parent)
            ->startSpan();
        $scope = $span->activate();
        Log::withContext($context->toArray() + ['media_upload_id' => $this->mediaUploadId]);

        try {
            $canonical->generate($context, $this->mediaUploadId, $this->sourceSha256);
        } finally {
            Log::withoutContext([...array_keys($context->toArray()), 'media_upload_id']);
            $scope->detach();
            $span->end();
        }
    }
}
