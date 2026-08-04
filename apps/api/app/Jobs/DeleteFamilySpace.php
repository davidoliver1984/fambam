<?php

namespace App\Jobs;

use App\Services\FamilySpaceDeletionManager;
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

class DeleteFamilySpace implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    /** @param array{family_space_id: string, actor_user_id: int, correlation_id: string, traceparent: string} $context */
    public function __construct(public array $context) {}

    public function uniqueId(): string
    {
        return $this->context['family_space_id'];
    }

    public function handle(FamilySpaceDeletionManager $deletions): void
    {
        $context = TenantOperationContext::fromArray($this->context);
        $parent = Globals::propagator()->extract(['traceparent' => $context->traceparent]);
        $span = Globals::tracerProvider()
            ->getTracer('fambam-api')
            ->spanBuilder('family-space.deletion-teardown')
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setParent($parent)
            ->startSpan();
        $scope = $span->activate();
        Log::withContext($context->toArray());

        try {
            $deletions->teardown($context);
        } finally {
            Log::withoutContext(array_keys($context->toArray()));
            $scope->detach();
            $span->end();
        }
    }
}
