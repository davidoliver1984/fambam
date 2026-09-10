<?php

namespace App\Jobs;

use App\Enums\NotificationCategory;
use App\Services\NotificationManager;
use App\Tenancy\TenantOperationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;

class ProcessNotificationCandidate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  array{family_space_id:string,actor_user_id:int,correlation_id:string,traceparent:string}  $context
     * @param  array<string, mixed>  $subject
     */
    public function __construct(public array $context, public NotificationCategory $category, public string $sourceActionId, public array $subject) {}

    public function handle(NotificationManager $manager): void
    {
        $operationContext = TenantOperationContext::fromArray($this->context);
        $parent = Globals::propagator()->extract(['traceparent' => $operationContext->traceparent]);
        $span = Globals::tracerProvider()->getTracer('fambam-api')
            ->spanBuilder('notification.candidate.process')
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setParent($parent)
            ->startSpan();
        $scope = $span->activate();
        Log::withContext($operationContext->toArray() + ['notification_category' => $this->category->value]);

        try {
            if ($this->category === NotificationCategory::Contribution && isset($this->subject['contribution_group_id'])) {
                $manager->registerContribution($this->context, $this->sourceActionId, $this->subject);

                return;
            }

            $manager->process($this->context, $this->category, $this->sourceActionId, $this->subject);
        } finally {
            Log::withoutContext([...array_keys($operationContext->toArray()), 'notification_category']);
            $scope->detach();
            $span->end();
        }
    }
}
