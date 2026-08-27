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

## Local face-analysis provider

FPA-P09-S04 pins InsightFace `1.0.1`, ONNX Runtime `1.29.0` and the official
`buffalo_l` v0.7 pack. The weights are non-commercial research assets and must
remain under the repository-ignored `.local/models/buffalo_l/` directory; they
must never be committed or distributed with the application. See
`docs/face-analysis/MODEL_LICENSING.md` for the recorded source, licence scope
and exact checksums.

The provider checks every model-pack file before loading and fails closed on a
missing or changed file. Run the private benchmark directly, without queues or
database persistence, from `apps/image-ai`:

```bash
uv run python -m scripts.benchmark_face_analysis \
  --benchmark-root ../../.local/benchmarks/face-analysis \
  --insightface-root ../../.local \
  --output ../../.local/benchmarks/face-analysis/results/provider-benchmark.json
```

The summary remains private and contains operational measurements and anonymous
benchmark identifiers only. It does not persist image bytes, face crops,
landmarks or embeddings.
