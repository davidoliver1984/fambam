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

## Local benchmark identity annotation

FPA-P10-S07 uses a standalone localhost-only helper; it is not part of the
FastAPI production application and never accesses PostgreSQL. The existing
benchmark did not retain face boxes, so first prepare stable display indices
once with the pinned local model:

```bash
uv run python -m scripts.annotate_face_benchmark \
  --benchmark-root ../../.local/benchmarks/face-analysis \
  prepare --insightface-root ../../.local
```

Then launch the private annotation UI, open the printed localhost URL, and use
anonymous `person-*` labels consistently across images and ages:

```bash
uv run python -m scripts.annotate_face_benchmark \
  --benchmark-root ../../.local/benchmarks/face-analysis serve
```

Additional private recognition-calibration images can be placed in the local
`incoming/` directory, deduplicated into the benchmark, and prepared with the
same local model before relaunching the helper:

```bash
uv run python -m scripts.annotate_face_benchmark \
  --benchmark-root ../../.local/benchmarks/face-analysis \
  import-assets --incoming ../../.local/benchmarks/face-analysis/incoming
uv run python -m scripts.annotate_face_benchmark \
  --benchmark-root ../../.local/benchmarks/face-analysis \
  prepare --insightface-root ../../.local
```

Imported images are auxiliary recognition-calibration material. Their detected
face count is recorded explicitly as provisional rather than presented as
independent human ground truth for detection accuracy.

Assignments extend each existing private annotation with stable
`face_regions` and one `anonymous_identity_groups` entry per face; the latter
is mirrored into the existing manifest field. Writes are atomic per JSON file
and the original files receive one local `.pre-identity-annotation.bak` copy.
Only boxes and human labels are retained—never embeddings or landmarks.

Validate the private files or view progress and remaining S07 coverage gaps:

```bash
uv run python -m scripts.annotate_face_benchmark \
  --benchmark-root ../../.local/benchmarks/face-analysis validate
uv run python -m scripts.annotate_face_benchmark \
  --benchmark-root ../../.local/benchmarks/face-analysis summary
```

Add `--require-complete` to `validate` only after every detected face has been
labelled, marked unknown, or deliberately skipped. Everything under `.local/`
remains gitignored and must never be committed.

## Private recognition calibration

After identity annotation is complete, run the production-semantic calibration
harness directly against the private corpus and pinned local provider:

```bash
uv run python -m scripts.calibrate_face_recognition \
  --benchmark-root ../../.local/benchmarks/face-analysis \
  --insightface-root ../../.local \
  --output ../../.local/benchmarks/face-analysis/results/recognition-calibration.json
```

The harness uses leave-one-scene-group-out evaluation, mirrors the API's
best-match-per-Person aggregation over the top 100 trusted references, and
calibrates strong, shortlist, ambiguity, minimum-reference and deterministic
complete-link clustering settings. It retains only private aggregate evidence;
embeddings, per-face scores, images and anonymous identity labels are never
written to the report or repository.
