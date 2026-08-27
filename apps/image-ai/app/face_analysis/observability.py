"""Low-cardinality operational telemetry for the face-analysis worker."""

from __future__ import annotations

from collections.abc import Iterator
from contextlib import contextmanager
from typing import Any

from opentelemetry import metrics, trace
from opentelemetry.trace import Span, SpanKind, Status, StatusCode
from opentelemetry.trace.propagation.tracecontext import TraceContextTextMapPropagator

from app.face_analysis.contracts import AnalysisIdentity

TRACER = trace.get_tracer("fambam-image-ai.face-analysis")
METER = metrics.get_meter("fambam-image-ai.face-analysis")

REQUESTS = METER.create_counter(
    "face_analysis.requests",
    description="Face-analysis requests reaching a terminal worker outcome.",
)
FAILURES = METER.create_counter(
    "face_analysis.failures",
    description="Face-analysis terminal failures by bounded failure category.",
)
DETECTED_FACES = METER.create_counter(
    "face_analysis.detected_faces",
    description="Faces detected by successful analysis requests.",
)
NO_FACE_RESULTS = METER.create_counter(
    "face_analysis.no_face_results",
    description="Successful analysis requests with no detected faces.",
)
QUEUE_LATENCY = METER.create_histogram(
    "face_analysis.queue_latency",
    unit="ms",
    description="Time from SQS publication to worker receive.",
)
ANALYSIS_DURATION = METER.create_histogram(
    "face_analysis.analysis_duration",
    unit="ms",
    description="End-to-end worker processing duration for a received request.",
)
INFERENCE_DURATION = METER.create_histogram(
    "face_analysis.inference_duration",
    unit="ms",
    description="Provider inference duration.",
)
ATTEMPT_COUNT = METER.create_histogram(
    "face_analysis.attempt_count",
    unit="{attempt}",
    description="SQS receive attempt count for a request.",
)
CANONICAL_WIDTH = METER.create_histogram(
    "face_analysis.canonical_width",
    unit="px",
    description="Canonical image width presented to the provider.",
)
CANONICAL_HEIGHT = METER.create_histogram(
    "face_analysis.canonical_height",
    unit="px",
    description="Canonical image height presented to the provider.",
)
PROCESS_MEMORY = METER.create_histogram(
    "face_analysis.process_memory_peak",
    unit="By",
    description="Worker peak resident memory measured after a terminal outcome.",
)


def identity_attributes(
    identity: AnalysisIdentity, execution_backend: str
) -> dict[str, str]:
    """Return the approved non-family operational identity dimensions."""
    return {
        "face_analysis.provider": identity.provider,
        "face_analysis.model": identity.model_identifier,
        "face_analysis.config_hash": identity.config_hash,
        "face_analysis.execution_backend": execution_backend,
    }


@contextmanager
def observe_request(
    traceparent: str,
    identity: AnalysisIdentity,
    execution_backend: str,
    *,
    queue_latency_ms: float | None,
    attempt_count: int,
) -> Iterator[Span]:
    """Create one consumer span without attaching tenant or biometric values."""
    attributes: dict[str, Any] = {
        **identity_attributes(identity, execution_backend),
        "messaging.system": "aws_sqs",
        "messaging.operation.name": "process",
        "face_analysis.attempt_count": attempt_count,
    }
    if queue_latency_ms is not None:
        attributes["face_analysis.queue_latency_ms"] = queue_latency_ms

    parent = TraceContextTextMapPropagator().extract(
        carrier={"traceparent": traceparent}
    )
    with TRACER.start_as_current_span(
        "face-analysis.request process",
        context=parent,
        kind=SpanKind.CONSUMER,
        attributes=attributes,
    ) as span:
        metric_attributes = identity_attributes(identity, execution_backend)
        ATTEMPT_COUNT.record(attempt_count, metric_attributes)
        if queue_latency_ms is not None:
            QUEUE_LATENCY.record(queue_latency_ms, metric_attributes)
        yield span


def record_inference(
    span: Span,
    identity: AnalysisIdentity,
    execution_backend: str,
    *,
    duration_ms: float,
    width: int,
    height: int,
) -> None:
    """Record bounded image and inference measurements."""
    attributes = identity_attributes(identity, execution_backend)
    INFERENCE_DURATION.record(duration_ms, attributes)
    CANONICAL_WIDTH.record(width, attributes)
    CANONICAL_HEIGHT.record(height, attributes)
    span.set_attributes(
        {
            "face_analysis.inference_duration_ms": duration_ms,
            "face_analysis.canonical_width": width,
            "face_analysis.canonical_height": height,
        }
    )


def record_success(
    span: Span,
    identity: AnalysisIdentity,
    execution_backend: str,
    *,
    detected_face_count: int,
    duration_ms: float,
    memory_bytes: int | None,
) -> None:
    """Record a successful terminal outcome using counts only."""
    attributes = {
        **identity_attributes(identity, execution_backend),
        "face_analysis.outcome": "completed",
    }
    REQUESTS.add(1, attributes)
    ANALYSIS_DURATION.record(duration_ms, attributes)
    DETECTED_FACES.add(detected_face_count, attributes)
    if detected_face_count == 0:
        NO_FACE_RESULTS.add(1, attributes)
    if memory_bytes is not None:
        PROCESS_MEMORY.record(memory_bytes, attributes)
        span.set_attribute("face_analysis.process_memory_peak_bytes", memory_bytes)
    span.set_attribute("face_analysis.detected_face_count", detected_face_count)
    span.set_attribute("face_analysis.no_face_detected", detected_face_count == 0)
    span.set_attribute("face_analysis.analysis_duration_ms", duration_ms)
    span.set_status(Status(StatusCode.OK))


def record_failure(
    span: Span,
    identity: AnalysisIdentity,
    execution_backend: str,
    *,
    category: str,
    duration_ms: float,
    memory_bytes: int | None,
) -> None:
    """Record a bounded failure category without exception or asset material."""
    attributes = {
        **identity_attributes(identity, execution_backend),
        "face_analysis.outcome": "failed",
        "face_analysis.failure_category": category,
    }
    REQUESTS.add(1, attributes)
    FAILURES.add(1, attributes)
    ANALYSIS_DURATION.record(duration_ms, attributes)
    if memory_bytes is not None:
        PROCESS_MEMORY.record(memory_bytes, attributes)
        span.set_attribute("face_analysis.process_memory_peak_bytes", memory_bytes)
    span.set_attribute("face_analysis.failure_category", category)
    span.set_attribute("face_analysis.analysis_duration_ms", duration_ms)
    span.set_status(Status(StatusCode.ERROR, category))
