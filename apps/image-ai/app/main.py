"""FastAPI application boundary for stateless image analysis."""

import logging
import uuid
from collections.abc import Awaitable, Callable
from typing import Final

from fastapi import FastAPI, Request, Response
from opentelemetry import metrics
from opentelemetry.instrumentation.fastapi import FastAPIInstrumentor

from app.telemetry import configure_telemetry

SERVICE_NAME: Final = "image-ai"
configure_telemetry()
logger = logging.getLogger("fambam.image_ai")
request_counter = metrics.get_meter("fambam-image-ai").create_counter(
    "http.server.requests"
)

app = FastAPI(
    title="Family Photo Archive Image Analysis",
    version="0.1.0",
)
FastAPIInstrumentor.instrument_app(app)


@app.middleware("http")
async def request_context(
    request: Request, call_next: Callable[[Request], Awaitable[Response]]
) -> Response:
    """Preserve request and correlation identifiers in logs and responses."""
    request_id = request.headers.get("x-request-id", str(uuid.uuid4()))
    correlation_id = request.headers.get("x-correlation-id", request_id)
    response = await call_next(request)
    response.headers["X-Request-ID"] = request_id
    response.headers["X-Correlation-ID"] = correlation_id
    request_counter.add(1, {"http.request.method": request.method})
    logger.info(
        "request completed",
        extra={"request_id": request_id, "correlation_id": correlation_id},
    )
    return response


@app.get("/health", tags=["operations"])
async def health() -> dict[str, str]:
    """Report that the stateless inference service is ready to accept work."""
    return {"service": SERVICE_NAME, "status": "ok"}
