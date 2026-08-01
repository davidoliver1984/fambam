<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use Symfony\Component\HttpFoundation\Response;

class RequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->headers->get('X-Request-ID') ?? (string) str()->uuid();
        $correlationId = $request->headers->get('X-Correlation-ID') ?? $requestId;
        $carrier = array_map(
            static fn (array $values): string => $values[0],
            $request->headers->all(),
        );
        $parent = Globals::propagator()->extract($carrier);
        $span = Globals::tracerProvider()
            ->getTracer('fambam-api')
            ->spanBuilder($request->method().' '.$request->path())
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent($parent)
            ->startSpan();
        $scope = $span->activate();
        $spanContext = Span::getCurrent()->getContext();

        Log::withContext([
            'service' => 'fambam-api',
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'trace_id' => $spanContext->isValid() ? $spanContext->getTraceId() : null,
        ]);

        try {
            $response = $next($request);
            $response->headers->set('X-Request-ID', $requestId);
            $response->headers->set('X-Correlation-ID', $correlationId);
            Globals::meterProvider()
                ->getMeter('fambam-api')
                ->createCounter('http.server.requests')
                ->add(1, ['http.request.method' => $request->method()]);
            Log::info('request completed', ['http_status' => $response->getStatusCode()]);

            return $response;
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
