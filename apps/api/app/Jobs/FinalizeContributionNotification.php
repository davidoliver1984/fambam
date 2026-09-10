<?php

namespace App\Jobs;

use App\Services\NotificationManager;
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

class FinalizeContributionNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 300;

    /** @param array{family_space_id:string,actor_user_id:int,correlation_id:string,traceparent:string} $context */
    public function __construct(public array $context, public string $contributionGroupId) {}

    public function uniqueId(): string
    {
        return "contribution-notification:{$this->contributionGroupId}";
    }

    public function handle(NotificationManager $manager): void
    {
        $operationContext = TenantOperationContext::fromArray($this->context);
        $parent = Globals::propagator()->extract(['traceparent' => $operationContext->traceparent]);
        $span = Globals::tracerProvider()->getTracer('fambam-api')
            ->spanBuilder('notification.contribution.finalize')
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setParent($parent)
            ->startSpan();
        $scope = $span->activate();
        Log::withContext($operationContext->toArray());

        try {
            $manager->finalizeContribution($this->context, $this->contributionGroupId);
        } finally {
            Log::withoutContext(array_keys($operationContext->toArray()));
            $scope->detach();
            $span->end();
        }
    }
}
