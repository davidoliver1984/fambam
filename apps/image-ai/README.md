# Image-analysis service

This FastAPI application is the stateless inference boundary defined by
ADR-0001. It does not own business data and must never access PostgreSQL.

## Commands

```bash
uv sync
uv run --no-cache uvicorn app.main:app --reload
uv run --no-cache pytest
uv run --no-cache ruff check .
uv run --no-cache ruff format --check .
uv run --no-cache mypy
```

The health endpoint is available at `GET /health`.
