<?php

namespace App\Console\Commands;

use App\Jobs\DeleteFamilySpace;
use App\Tenancy\TenantOperationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;

class DispatchDueFamilySpaceDeletions extends Command
{
    protected $signature = 'fambam:dispatch-due-family-space-deletions';

    protected $description = 'Dispatch idempotent teardown jobs for due Family Space deletions';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->components->error('Due-deletion discovery requires PostgreSQL.');

            return self::FAILURE;
        }

        $due = DB::select('SELECT family_space_id, actor_user_id FROM app_due_family_space_deletions()');

        foreach ($due as $deletion) {
            $span = Globals::tracerProvider()
                ->getTracer('fambam-api')
                ->spanBuilder('family-space.deletion-dispatch')
                ->setSpanKind(SpanKind::KIND_PRODUCER)
                ->startSpan();
            $scope = $span->activate();

            try {
                $context = TenantOperationContext::forBackground(
                    trim((string) $deletion->family_space_id),
                    (int) $deletion->actor_user_id,
                );
                $spanContext = Span::getCurrent()->getContext();

                if ($spanContext->isValid()) {
                    $context = new TenantOperationContext(
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

                DeleteFamilySpace::dispatch($context->toArray());
            } finally {
                $scope->detach();
                $span->end();
            }
        }

        $this->components->info(sprintf('Dispatched %d due Family Space deletion(s).', count($due)));

        return self::SUCCESS;
    }
}
