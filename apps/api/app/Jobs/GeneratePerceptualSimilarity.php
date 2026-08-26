<?php

namespace App\Jobs;

use App\Services\PerceptualSimilarityManager;
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

class GeneratePerceptualSimilarity implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /** @param array{family_space_id: string, actor_user_id: int, correlation_id: string, traceparent: string} $context */
    public function __construct(
        public array $context,
        public string $mediaUploadId,
        public string $canonicalSha256,
        public string $algorithm,
        public int $processingVersion,
    ) {}

    public function uniqueId(): string
    {
        return "perceptual:{$this->mediaUploadId}:{$this->canonicalSha256}:{$this->algorithm}:{$this->processingVersion}";
    }

    public function handle(PerceptualSimilarityManager $similarity): void
    {
        $context = TenantOperationContext::fromArray($this->context);
        $parent = Globals::propagator()->extract(['traceparent' => $context->traceparent]);
        $span = Globals::tracerProvider()
            ->getTracer('fambam-api')
            ->spanBuilder('photo.perceptual-similarity')
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setParent($parent)
            ->startSpan();
        $scope = $span->activate();
        Log::withContext($context->toArray() + ['media_upload_id' => $this->mediaUploadId]);

        try {
            $similarity->generate(
                $context,
                $this->mediaUploadId,
                $this->canonicalSha256,
                $this->algorithm,
                $this->processingVersion,
            );
        } finally {
            Log::withoutContext([...array_keys($context->toArray()), 'media_upload_id']);
            $scope->detach();
            $span->end();
        }
    }
}
