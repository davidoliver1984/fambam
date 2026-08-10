<?php

namespace App\Console\Commands;

use App\Jobs\AbandonMediaUpload;
use App\Tenancy\TenantOperationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;

class DispatchDueAbandonedMediaUploads extends Command
{
    protected $signature = 'fambam:dispatch-due-abandoned-media-uploads';

    protected $description = 'Dispatch idempotent cleanup for expired initiated media uploads';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->components->error('Abandoned-media discovery requires PostgreSQL.');

            return self::FAILURE;
        }

        $cutoff = now()->subHours((int) config('media.cleanup.abandoned_after_hours'));
        $due = DB::select('SELECT * FROM app_due_abandoned_media_uploads(?)', [$cutoff]);

        foreach ($due as $upload) {
            $span = Globals::tracerProvider()
                ->getTracer('fambam-api')
                ->spanBuilder('media-upload.abandoned-cleanup-dispatch')
                ->setSpanKind(SpanKind::KIND_PRODUCER)
                ->startSpan();
            $scope = $span->activate();

            try {
                $context = TenantOperationContext::forBackground(
                    trim((string) $upload->family_space_id),
                    (int) $upload->actor_user_id,
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
                AbandonMediaUpload::dispatch(
                    $context->toArray(),
                    trim((string) $upload->media_upload_id),
                );
            } finally {
                $scope->detach();
                $span->end();
            }
        }

        $this->components->info(sprintf('Dispatched %d abandoned media cleanup(s).', count($due)));

        return self::SUCCESS;
    }
}
