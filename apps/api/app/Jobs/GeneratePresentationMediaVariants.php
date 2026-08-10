<?php

namespace App\Jobs;

use App\Services\MediaVariantManager;
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

class GeneratePresentationMediaVariants implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /** @param array{family_space_id: string, actor_user_id: int, correlation_id: string, traceparent: string} $context */
    public function __construct(
        public array $context,
        public string $mediaUploadId,
        public string $canonicalSha256,
        public int $processingVersion,
    ) {}

    public function uniqueId(): string
    {
        return "variants:{$this->mediaUploadId}:{$this->canonicalSha256}:{$this->processingVersion}";
    }

    public function handle(MediaVariantManager $variants): void
    {
        $context = TenantOperationContext::fromArray($this->context);
        $parent = Globals::propagator()->extract(['traceparent' => $context->traceparent]);
        $span = Globals::tracerProvider()
            ->getTracer('fambam-api')
            ->spanBuilder('media-upload.presentation-variants')
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setParent($parent)
            ->startSpan();
        $scope = $span->activate();
        Log::withContext($context->toArray() + ['media_upload_id' => $this->mediaUploadId]);

        try {
            $variants->generate(
                $context,
                $this->mediaUploadId,
                $this->canonicalSha256,
                $this->processingVersion,
            );
        } finally {
            Log::withoutContext([...array_keys($context->toArray()), 'media_upload_id']);
            $scope->detach();
            $span->end();
        }
    }

    public function failed(\Throwable $exception): void
    {
        app(MediaVariantManager::class)->markDegraded(
            TenantOperationContext::fromArray($this->context),
            $this->mediaUploadId,
            $this->canonicalSha256,
        );
    }
}
