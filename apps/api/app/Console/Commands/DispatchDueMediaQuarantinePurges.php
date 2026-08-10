<?php

namespace App\Console\Commands;

use App\Jobs\PurgeMediaQuarantine;
use App\Tenancy\TenantOperationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;

class DispatchDueMediaQuarantinePurges extends Command
{
    protected $signature = 'fambam:dispatch-due-media-quarantine-purges';

    protected $description = 'Dispatch idempotent purges for expired media quarantine objects';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->components->error('Media-quarantine discovery requires PostgreSQL.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays((int) config('media.cleanup.quarantine_retention_days'));
        $due = DB::select('SELECT * FROM app_due_media_quarantines(?)', [$cutoff]);

        foreach ($due as $quarantine) {
            $span = Globals::tracerProvider()
                ->getTracer('fambam-api')
                ->spanBuilder('media-upload.quarantine-purge-dispatch')
                ->setSpanKind(SpanKind::KIND_PRODUCER)
                ->startSpan();
            $scope = $span->activate();

            try {
                $context = TenantOperationContext::forBackground(
                    trim((string) $quarantine->family_space_id),
                    (int) $quarantine->actor_user_id,
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

                PurgeMediaQuarantine::dispatch(
                    $context->toArray(),
                    trim((string) $quarantine->media_upload_id),
                );
            } finally {
                $scope->detach();
                $span->end();
            }
        }

        $this->components->info(sprintf('Dispatched %d due media quarantine purge(s).', count($due)));

        return self::SUCCESS;
    }
}
