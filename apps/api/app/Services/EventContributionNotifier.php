<?php

namespace App\Services;

use App\Jobs\SendEventContributionNotifications;
use App\Models\Album;
use App\Models\Photo;
use App\Tenancy\TenantOperationContext;
use Illuminate\Support\Facades\DB;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;

class EventContributionNotifier
{
    public function dispatch(Album $album, Photo $photo, TenantOperationContext $context): void
    {
        if ($album->event_id === null) {
            return;
        }

        DB::afterCommit(function () use ($album, $photo, $context): void {
            $parent = Globals::propagator()->extract(['traceparent' => $context->traceparent]);
            $span = Globals::tracerProvider()
                ->getTracer('fambam-api')
                ->spanBuilder('event.contribution-notifications dispatch')
                ->setSpanKind(SpanKind::KIND_PRODUCER)
                ->setParent($parent)
                ->startSpan();
            $scope = $span->activate();

            try {
                $dispatchContext = $context;
                $spanContext = Span::getCurrent()->getContext();
                if ($spanContext->isValid()) {
                    $dispatchContext = new TenantOperationContext(
                        $context->familySpaceId,
                        $context->actorUserId,
                        $context->correlationId,
                        sprintf(
                            '00-%s-%s-%02x',
                            $spanContext->getTraceId(),
                            $spanContext->getSpanId(),
                            $spanContext->getTraceFlags(),
                        ),
                    );
                }
                SendEventContributionNotifications::dispatch(
                    $dispatchContext->toArray(),
                    $album->event_id,
                    $photo->id,
                );
            } finally {
                $scope->detach();
                $span->end();
            }
        });
    }
}
