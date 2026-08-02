<?php

use App\Http\Controllers\CurrentUserController;
use Aws\Sqs\SqsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;

Route::get('/health', static fn (): array => [
    'service' => 'api',
    'status' => 'ok',
]);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', [CurrentUserController::class, 'show']);
    Route::patch('/user/profile', [CurrentUserController::class, 'update']);
});

if (app()->environment(['local', 'testing'])) {
    Route::post('/observability/synthetic-upload', static function (Request $request): JsonResponse {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $tracer = Globals::tracerProvider()->getTracer('fambam-api');
        $span = $tracer->spanBuilder('synthetic-upload.request')
            ->setSpanKind(SpanKind::KIND_PRODUCER)
            ->startSpan();
        $scope = $span->activate();

        try {
            $spanContext = $span->getContext();
            $carrier = [
                'traceparent' => sprintf(
                    '00-%s-%s-%02x',
                    $spanContext->getTraceId(),
                    $spanContext->getSpanId(),
                    $spanContext->getTraceFlags(),
                ),
            ];

            $client = new SqsClient([
                'version' => 'latest',
                'region' => config('queue.connections.sqs.region'),
                'endpoint' => config('queue.connections.sqs.endpoint'),
                'credentials' => [
                    'key' => config('queue.connections.sqs.key'),
                    'secret' => config('queue.connections.sqs.secret'),
                ],
            ]);
            $queueUrl = config('queue.connections.sqs.prefix').'/'.config('queue.connections.sqs.queue');
            $messageAttributes = [];

            foreach ($carrier as $name => $value) {
                $messageAttributes[$name] = ['DataType' => 'String', 'StringValue' => $value];
            }

            $client->sendMessage([
                'QueueUrl' => $queueUrl,
                'MessageBody' => json_encode([
                    'type' => 'SyntheticUploadRequested',
                    'correlation_id' => $request->header('X-Correlation-ID'),
                ], JSON_THROW_ON_ERROR),
                'MessageAttributes' => $messageAttributes,
            ]);

            return response()->json([
                'status' => 'queued',
                'trace_id' => $span->getContext()->getTraceId(),
            ]);
        } finally {
            $scope->detach();
            $span->end();
        }
    });
}
