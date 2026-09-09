<?php

namespace App\Console\Commands;

use App\FaceAnalysis\FaceAnalysisMessageValidator;
use App\FaceAnalysis\FaceAnalysisResultQueue;
use App\FaceAnalysis\InvalidFaceAnalysisMessage;
use App\Services\FaceAnalysisPipeline;
use App\Tenancy\TenantOperationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;

class ConsumeFaceAnalysisResults extends Command
{
    protected $signature = 'fambam:consume-face-analysis-results {--once : Poll each result queue once and exit}';

    protected $description = 'Consume raw face-analysis completion and failure messages';

    public function handle(
        FaceAnalysisResultQueue $queue,
        FaceAnalysisMessageValidator $messages,
        FaceAnalysisPipeline $pipeline,
    ): int {
        if (DB::getDriverName() !== 'pgsql') {
            $this->components->error('Face-analysis result consumption requires PostgreSQL.');

            return self::FAILURE;
        }

        do {
            foreach (['completed', 'failed'] as $kind) {
                foreach ($queue->receive($kind) as $message) {
                    try {
                        $requestId = $messages->requestId($message->body);
                        $resolved = DB::selectOne('SELECT * FROM app_face_analysis_attempt_context(?)', [$requestId]);
                        if ($resolved === null || ! is_numeric($resolved->actor_user_id ?? null)) {
                            throw new InvalidFaceAnalysisMessage('Request identifier is not associated with a trusted attempt.');
                        }
                        $context = TenantOperationContext::forBackground(
                            trim((string) $resolved->family_space_id),
                            (int) $resolved->actor_user_id,
                        );
                        if ($message->traceparent !== null) {
                            $context = new TenantOperationContext(
                                $context->familySpaceId,
                                $context->actorUserId,
                                $context->correlationId,
                                $message->traceparent,
                            );
                        }
                        $parent = Globals::propagator()->extract(['traceparent' => $context->traceparent]);
                        $span = Globals::tracerProvider()
                            ->getTracer('fambam-api')
                            ->spanBuilder("face-analysis.result {$kind}")
                            ->setSpanKind(SpanKind::KIND_CONSUMER)
                            ->setParent($parent)
                            ->startSpan();
                        $scope = $span->activate();
                        try {
                            if ($kind === 'completed') {
                                $pipeline->complete($context, $message->body);
                            } else {
                                $pipeline->fail($context, $message->body);
                            }
                            $queue->delete($kind, $message->receiptHandle);
                        } finally {
                            $scope->detach();
                            $span->end();
                        }
                    } catch (InvalidFaceAnalysisMessage $exception) {
                        Log::warning('Face-analysis result message rejected.', [
                            'queue' => $kind,
                            'reason' => $exception->getMessage(),
                        ]);
                    } catch (\Throwable $exception) {
                        Log::error('Face-analysis result processing failed safely.', [
                            'queue' => $kind,
                            'exception_type' => $exception::class,
                        ]);
                    }
                }
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
