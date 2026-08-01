"""FastAPI application boundary for stateless image analysis."""

from typing import Final

from fastapi import FastAPI

SERVICE_NAME: Final = "image-ai"

app = FastAPI(
    title="Family Photo Archive Image Analysis",
    version="0.1.0",
)


@app.get("/health", tags=["operations"])
async def health() -> dict[str, str]:
    """Report that the stateless inference service is ready to accept work."""
    return {"service": SERVICE_NAME, "status": "ok"}
