# Local face-analysis provider benchmark

## Scope

FPA-P09-S04 exercised the pinned provider directly against the private,
gitignored 38-image benchmark assembled in S01. No queue, Laravel process,
application database or family identity was involved. The private result files
remain under `.local/benchmarks/face-analysis/results/` and are not committed.

This is provider-foundation evidence, not face-recognition accuracy evidence.
The annotations contain expected visible-face counts but no identity labels or
per-face boxes, so an excess count is a review signal rather than proof of a
specific false-positive location.

## Pinned configuration

- Provider: `insightface-onnxruntime`.
- Model: `buffalo_l-v0.7`.
- Model-pack SHA-256:
  `80ffe37d8a5940d59a7384c201a2a38d4741f2f3c51eef46ebb28218a7b0ca2f`.
- Detection input: 640 × 640.
- Detection threshold: 0.6.
- Embedding: L2-normalised float32, 512 dimensions.
- Landmarks: `5-point`.
- Logical configuration SHA-256:
  `9311c040d0047b0f3568371d6a1b4b0213a37c631906276e6120de95af9b5964`.

Threshold calibration compared 0.5, 0.6 and 0.7 against the same private
count annotations. Threshold 0.6 retained 30 exact-count images while reducing
aggregate absolute count error from 24 at 0.5 to 22. Threshold 0.7 reduced the
exact-count images to 26 and produced aggregate absolute count error 23. The
0.6 setting is therefore the bounded starting configuration; later evidence
may create a new `config_hash` rather than silently changing existing runs.

## Apple Silicon CPU evidence

The final run used Python 3.13.7 and ONNX Runtime 1.29.0 on arm64 macOS:

| Measurement | Result |
| --- | ---: |
| Images | 38 |
| Annotated visible faces | 79 |
| Provider detections | 93 |
| Exact-count images | 30 |
| Under-count images | 2 |
| Over-count images | 6 |
| Model construction | 365.652 ms |
| First-inference warm-up | 173.802 ms |
| Hot median latency | 66.856 ms |
| Hot p95 latency | 132.988 ms |
| Hot maximum latency | 141.732 ms |
| RSS after load | 845,103,104 bytes |
| Peak observed RSS | 1,093,976,064 bytes |
| Maximum serialized result | 67,869 bytes |
| p95 serialized result | 67,754.25 bytes |

The known difficult cases include two under-count images. Those limitations are
recorded rather than hidden. Phase 9 persists versioned machine observations;
it does not assert identity or overwrite human truth. Phase 10 owns recognition
and human-review calibration.

## Backend parity

Three representative private assets (single face, group and high-detection
case) were run through CPU and CoreML-with-CPU-fallback sessions. Both sessions
used the identical logical `config_hash`, produced the same face counts and
passed the recorded numerical tolerances:

- maximum bounds delta: `0.0001068115234375` pixels;
- maximum landmark delta: `0.0001220703125` pixels;
- maximum confidence delta: `0.00000035762786865234375`;
- minimum embedding cosine: `0.9992794022265646`.

Execution backend therefore remains excluded from the logical configuration
identity. If a future backend exceeds these tolerances, it must be treated as a
logical configuration change or rejected, not silently reused.

## Bound calibration

The largest observed result contained six detections and serialized to 67,869
bytes. The per-face representation is approximately 11.3 KiB at the pinned
512-dimensional float32 contract. Keeping the architectural 256-face ceiling
would extrapolate to roughly 2.9 MiB before envelope overhead. The result-
artifact byte ceiling is therefore calibrated from the S03 placeholder of 8
MiB to 4 MiB, retaining meaningful headroom without accepting an unnecessarily
large untrusted artifact. The 256-face, 512-dimension, float32 and five-landmark
bounds remain unchanged.

The S04 evidence calibrates these initial S05 transport/worker values:

- request-queue visibility timeout: 30 seconds;
- completed/failed queue visibility timeout: 30 seconds;
- `maxReceiveCount`: 5 for each of the three queues;
- worker inference timeout: 20 seconds;
- stale dispatched-attempt window: 5 minutes, checked every minute;
- maximum attempts per run: 3;
- service-audience canonical/result authority TTL: 60 minutes;
- image-analysis worker memory: at least 2 GiB for one inference at a time.

Thirty seconds is more than 200 times the measured hot p95 while still giving a
poison request bounded progress to its DLQ. Five 30-second delivery windows fit
inside the five-minute stale-attempt window. The worker timeout preserves a
large allowance for network/decode variability without approaching queue
visibility. The one-hour authority TTL addresses queue backlog separately from
execution time. The 2 GiB worker baseline leaves material headroom above the
roughly 1.1 GB observed process peak.

S05 owns applying and verifying these settings end to end. If its real S3/SQS
exercise disproves an assumption, it must record the adjusted value against
that new evidence rather than changing it silently.
