# Face-analysis operational acceptance

## Scope and privacy boundary

FPA-P09-S06 completed the final direct-provider benchmark against the private,
gitignored 38-image corpus. The run used the same standalone harness and pinned
provider as S04. It generated no SQS traffic, involved no Laravel process and
wrote no application-database rows. Detailed local results remain under
`.local/benchmarks/face-analysis/results/` and are not committed.

The count annotations do not contain per-face boxes. Count differences can
therefore measure exact-count coverage, a lower bound on missed faces and
excess detections requiring review, but cannot truthfully prove that any one
excess detection is a false positive. This stage records that limitation rather
than converting machine output into identity truth.

## Final calibrated run

The final run used Python 3.13.7 and ONNX Runtime 1.29.0 on arm64 macOS with
`CPUExecutionProvider`, `buffalo_l-v0.7`, threshold 0.6 and logical
configuration SHA-256
`9311c040d0047b0f3568371d6a1b4b0213a37c631906276e6120de95af9b5964`.

| Measurement | Result |
| --- | ---: |
| Images completed | 38 / 38 |
| Provider failures | 0 |
| Failure rate | 0% |
| Annotated visible faces | 79 |
| Provider detections | 93 |
| Exact-count images | 30 / 38 |
| Count-derived matched upper bound | 75 / 79 (94.94%) |
| Count-derived missed lower bound | 4 |
| Excess detections requiring review | 18 |
| Model construction | 630.557 ms |
| First-inference warm-up | 409.359 ms |
| Hot median latency | 69.569 ms |
| Hot p95 latency | 132.495 ms |
| Hot maximum latency | 134.045 ms |
| Sequential hot throughput | 13.068 images/second |
| Peak observed RSS | 973,864,960 bytes |
| Maximum serialized result | 67,869 bytes |
| Detection-confidence median | 0.774931 |

The results remain consistent with S04's calibration evidence. They retain
material headroom inside the 20-second inference timeout, 2 GiB single-worker
memory limit and 4 MiB transient result-artifact ceiling adopted by S05. The
known detection limitations remain inputs to later human review; they do not
change provider identity or silently rewrite successful analysis history.

## Operational telemetry

The production-shaped worker now emits one trace-context-linked consumer span
per valid request and low-cardinality OpenTelemetry metrics for:

- SQS publication-to-receive latency, end-to-end analysis duration and receive-
  attempt count;
- provider inference duration;
- completed and failed request counts with a bounded failure category;
- detected-face and no-face-result counts;
- canonical width and height;
- provider, model, logical configuration and execution backend; and
- peak resident memory where the runtime exposes it.

Telemetry deliberately excludes Family Space IDs, MediaUpload IDs, image
bytes, signed URLs, checksums derived from family media, face bounds,
landmarks, embeddings and provider diagnostics. Durable observations remain in
PostgreSQL, while deliberate reprocessing remains the only Phase 9 action that
creates an audit event.

## Acceptance

The private benchmark completed without provider failure, the calibrated
operational bounds remain supported, and worker telemetry covers ADR-0011
section 20 without crossing its persisted-data or audit boundaries. This is
Phase 9 detection-foundation evidence only. Similarity search, clustering,
identity suggestions and human-review behaviour remain Phase 10 work.
