"""Operational health endpoint tests."""

import pytest
from httpx import ASGITransport, AsyncClient

from app.main import app


@pytest.fixture
def anyio_backend() -> str:
    return "asyncio"


@pytest.mark.anyio
async def test_health_reports_service_ready() -> None:
    transport = ASGITransport(app=app)

    async with AsyncClient(transport=transport, base_url="http://test") as client:
        response = await client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"service": "image-ai", "status": "ok"}
    assert response.headers["x-request-id"]
    assert response.headers["x-correlation-id"] == response.headers["x-request-id"]


@pytest.mark.anyio
async def test_request_context_preserves_supplied_identifiers() -> None:
    transport = ASGITransport(app=app)

    async with AsyncClient(transport=transport, base_url="http://test") as client:
        response = await client.get(
            "/health",
            headers={"X-Request-ID": "request-123", "X-Correlation-ID": "family-456"},
        )

    assert response.headers["x-request-id"] == "request-123"
    assert response.headers["x-correlation-id"] == "family-456"
