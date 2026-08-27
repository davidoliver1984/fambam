# ADR-0011: Local Face Analysis Foundation

- Status: Accepted
- Date: 2026-08-27
- Decision owners: David
- Related stages: FPA-P09-S02 (accepting this ADR completes that stage),
  implemented by FPA-P09-S03, FPA-P09-S04, FPA-P09-S05, FPA-P09-S06

## Context

Phase 8 (ADR-0010) closed with the Photo domain still carrying no
machine-derived identity concept of any kind — its one piece of automated
image processing, perceptual hashing, is deliberately non-ML, deterministic
Laravel-owned work, explicitly distinguished there from "genuine AI
inference from Phase 9 onward" (ADR-0010 §8). Phase 9 is therefore the
first place this product actually runs a machine-learning model against
family photographs, and the first place `apps/image-ai` does anything
beyond report a health check.

Three earlier ADRs already fixed load-bearing constraints this ADR builds
on rather than reopens:

- **ADR-0001** fixed Laravel as the sole owner of business data and the
  sole PostgreSQL writer; Python as a stateless inference boundary with no
  database access of any kind; and communication as asynchronous,
  message-mediated, never HTTP request/response and never shared-database.
  It further specified that the request message already carries "a signed
  reference to the canonical image," and explicitly named three things it
  was deliberately leaving for this ADR to decide: **production service
  identity, message-transport authentication/authorization between
  Laravel and Python, and compute deployment for the Python service.**
  Separately, ADR-0001 §Risks describes an "oversized result payload" risk
  and a deliberate inline-first default for completion messages, "adopted
  only if measured payload sizes justify it" otherwise — §6 below
  explicitly and narrowly revises that default for this message type,
  with its own honest rationale, rather than silently ignoring it.
- **ADR-0007 §9** fixed the canonical asset — not the original, not a
  variant — as "the sole permitted future AI-analysis input," written with
  this exact phase in mind ("Phase 9's face recognition"), and carries its
  own review trigger to reconfirm that boundary once Phase 9 is actually
  scoped. §16 below reconfirms it; nothing found during this ADR's
  preparation justifies reopening it.
- **ADR-0008 §5** fixed `PhotoPerson` as a human-confirmed table with an
  explicit non-overwrite guarantee: "a machine suggestion may become a
  *proposed* `PhotoPerson` row for a human to confirm — never silently
  written as confirmed," naming Phases 9/10 as the future source of such
  suggestions. This ADR does not touch `PhotoPerson` at all; the proposal
  mechanism belongs entirely to Phase 10 (ADR-0012).
- **ADR-0005 §9** fixed three distinct RLS treatments for tenant registry,
  access-control, and ordinary tenant-owned content tables. Every table
  this ADR introduces is the third kind, on the same already-proven
  pattern `duplicate_candidates`/`duplicate_decisions` already use — §8
  below additionally strengthens the biometric-specific tables with
  relational integrity beyond that precedent.

`PROJECT_ROADMAP.md`'s Phase 9 objective is "introduce replaceable,
self-hosted face detection and embedding generation," scoped to a provider
interface, the Python inference boundary, detection, landmarks/alignment,
embedding generation, analysis jobs, model/configuration versioning,
source-checksum validation, provider-specific analysis storage, and a
local benchmark harness. Phase 10 owns everything that turns an embedding
into an identity — clustering, suggested matches, confirmation, merge/
split, consent, and calibration — entirely and exclusively. `tasks.json`
already reflects this split concretely: `FPA-P10-S02`, gated by ADR-0012,
is named "Implement embedding storage and similarity queries" — meaning
the searchable/indexed form of an embedding, and the engine that indexes
it, is already assigned to Phase 10, not this ADR.

**Benchmark evidence (`FPA-P09-S01`, private, never committed to git,
`.local/benchmarks/face-analysis/`).** 38 uploads were inspected, all
containing at least one visible face and judged useful; 29 form
independent core benchmark scenes, with 9 further repeated/format-
conversion inputs retained for repeatability testing. The core set
carries roughly 61 visible faces (up to 79 across the full set),
including 10 single-face and 19 group images (up to 4 faces per image),
with recorded coverage of frontal, angled/profile, small/distant,
partially occluded, poorly lit, blurred, low-resolution, bespectacled,
bearded, grayscale, and rotated conditions. This is sufficient evidence
that a candidate provider can be exercised against realistic detection
conditions before committing to it. It is explicitly **not** a
recognition-accuracy benchmark, and — worth stating precisely, since §6
depends on the distinction — it is **not** a measurement of serialized
result-payload byte sizes either. Age-progression, sibling/parent
resemblance, damaged scans, larger groups and heavier occlusion are all
named gaps reserved for Phase 10's own calibration, and this ADR does not
claim otherwise.

**Current repository state.** `apps/image-ai` is a genuine skeleton: a
FastAPI app exposing only `/health`, OpenTelemetry wiring, and a synthetic
SQS message consumer used solely to prove trace propagation
(`scripts/consume_synthetic.py`). `pyproject.toml` carries no ML
dependency of any kind. `contracts/events/` is empty. Three dedicated SQS
queues — `image-analysis-requested`, `image-analysis-completed`,
`image-analysis-failed` — already exist in local dev bootstrap
(`infrastructure/docker/localstack/init/10-bootstrap.sh`), created plain,
with **no dead-letter/redrive policy configured on any queue in this
repository**, including the pre-existing general-purpose `fambam-jobs`
queue. `infrastructure/terraform/` contains no production infrastructure
yet. This ADR is being written into genuinely empty implementation space.

**Production target: this ADR selects containerised ECS/Fargate** for
`apps/image-ai`, independently deployable and scalable from Laravel —
closing the compute-deployment question ADR-0001 §"Implementation notes"
explicitly deferred to this ADR, not a decision made ahead of or outside
it. Lambda is explicitly rejected (§ Alternatives): an ONNX Runtime
inference workload benefits from a long-lived process holding a loaded
model in memory across requests, which a Fargate-style worker gives
naturally and a cold-starting function does not, alongside more
predictable control over package/image size for model-weight
distribution.

This ADR decides: the face-analysis provider contract; the production and
local Laravel↔Python transport, signing-audience, and canonical/result
delivery mechanisms; the `FaceAnalysisRun`/`FaceAnalysisAttempt`/
`FaceObservation` persistence model, its tenant-consistent integrity, and
its identity/idempotency rules; result-artifact safety bounds; the scope
(not the engine) of embedding storage; reprocessing semantics; queue,
dead-letter, and stale-attempt reconciliation conventions; the audit-
versus-telemetry boundary; tenancy scoping and Family Space teardown
implications; benchmark-harness discipline; and the initial model and
licensing record. It deliberately decides **nothing** about face
clustering, identity suggestion, human confirmation, merge/split, Person
matching, recognition consent or calibration, or the ANN/vector-search
engine used to query embeddings — all of that is ADR-0012's (Phase 10's)
concern, gated by `FPA-P10-S01`/`FPA-P10-S02`.

## Decision

### 1. Scope: foundation only

Phase 9 detects faces, computes landmarks/alignment, generates embeddings,
and persists all of that safely, retryably and versioned. It assigns no
identity to any face, suggests no `Person`, writes no `PhotoPerson` row of
any kind (proposed or confirmed), performs no similarity comparison
between two `FaceObservation`s, and builds no clustering, matching or
review surface. Everything after "a face was detected here, with this
embedding" belongs to ADR-0012.

### 2. Provider contract: a stable domain shape, provider diagnostics quarantined

The application-facing contract between Laravel and any current or future
provider is deliberately narrow and provider-agnostic:

```text
DetectedFace
- bounds                  (bounding box in canonical-asset pixel space)
- landmarks               (array of points)
- landmark_scheme         (identifier for the point layout, e.g. "5-point")
- detection_confidence    (float, provider's own confidence score)
- embedding               (vector)
- embedding_dimension     (integer)
- embedding_dtype         (identifier, e.g. "float32")
- quality_signals         (small, named, provider-supplied signals judged
                            stable and useful enough to standardise, e.g.
                            pose angle or blur score, if a provider offers
                            them — absent otherwise)
```

together with, at the run level, `contract_version` (the wire-schema
version this message/artifact conforms to — §7), `provider`,
`model_identifier`, `model_weight_checksum`, and `config_hash` (§10). This
is the entire stable contract. Anything else a specific provider emits —
InsightFace-specific diagnostic fields, internal model-stage scores,
alignment matrices, or any other provider-native structure — is captured,
if at all, in a single opaque `provider_diagnostics` field that Laravel
stores verbatim and never branches application logic on. This mirrors the
discipline ADR-0010 §8 already applied to perceptual hashing (a stable
`(algorithm, processing_version)` identity, not the hash implementation's
internal structure) and directly satisfies `docs/IMPLEMENTATION_GUIDE.md`'s
own instruction for `FPA-P09-S03`: "express detection and embedding
capabilities without leaking one provider's schema." **A `DetectedFace`
list is transported as the body of the result artifact §6 defines — never
as inline fields on the `ImageAnalysisCompleted` SQS message itself**;
this contract fixes the shape of that content, not the transport it
travels over.

The provider abstraction must be callable directly, as a plain function or
class, independent of the FastAPI request-handling path — §17's benchmark
discipline depends on this.

### 3. Laravel ↔ Python transport

Communication uses the three dedicated SQS queues already provisioned in
local dev infrastructure, matching a strict directional shape with
least-privilege IAM on both ends:

- `image-analysis-requested` — Laravel sends; the `apps/image-ai` ECS/
  Fargate worker receives and deletes. Laravel holds no receive/delete
  permission on this queue; the worker holds no send permission on it.
- `image-analysis-completed` / `image-analysis-failed` — the worker
  sends; a dedicated Laravel-side consumer (below) receives and deletes.
  The worker holds no receive/delete permission on either; Laravel holds
  no send permission on either.

No shared queue with a type discriminator is used. Python never calls
Laravel over HTTP for any purpose, including result delivery, exactly as
ADR-0001 §4 already fixed. This closes the two things ADR-0001 explicitly
left open: **production service identity** is the `apps/image-ai`
ECS/Fargate task's own IAM task role, scoped to exactly these three
queues and nothing else in the account; **message-transport
authentication/authorization** is that same IAM-role-based, per-queue,
per-direction access — no shared secret, no bespoke token.

**This task role's credentials cover its permitted SQS operations only —
it is never granted standing S3 object read/write permissions of any
kind.** All object access the worker ever performs — the canonical GET
(§5), the result-artifact PUT (§6) — is granted exclusively through the
narrowly scoped, single-object, per-request presigned authorities carried
in each message, signed for the service audience (§4). "The worker has
AWS credentials" and "the worker can read or write arbitrary S3 objects"
are deliberately different statements; only the first is true.

`ImageAnalysisRequested` carries: the attempt identifier (§8, serving as
`request_id`); `contract_version`; `family_space_id`; `media_upload_id`;
the canonical asset's checksum (§5); a narrowly scoped, service-audience
presigned GET URL for the canonical asset (§4, §5); a narrowly scoped,
service-audience, write-once presigned PUT URL for the result artifact
(§4, §6); and the requested analysis identity — `provider`,
`model_identifier`, `model_weight_checksum`, `config_hash` (§10) — plus
`correlation_id`/`traceparent` for tracing, exactly the same operational
fields already threaded through every existing job in this codebase.
`ImageAnalysisCompleted`/`ImageAnalysisFailed` are defined in full in §6.

**Laravel does not consume `image-analysis-completed`/`-failed` through
its ordinary `php artisan queue:work`/`ShouldQueue` pipeline.** That
pipeline expects Laravel's own job-envelope format (produced by Laravel's
own `SqsQueue::push()`), not arbitrary externally-produced JSON, and
nothing in this repository today lets a foreign message enter it.
Instead, a dedicated, long-running Laravel-side consumer — the smallest
adapter this needs, not a new event platform — polls these two queues
directly via the AWS SDK, the same shape `apps/image-ai/scripts/
consume_synthetic.py` already uses on the Python side for the identical
reason. For each message it: validates the payload against the fixed
`ImageAnalysisCompleted`/`Failed` shape (§6); resolves it against a
pre-existing `FaceAnalysisAttempt` by `request_id` (§8) — never trusting
the message's own claimed identity fields until that lookup succeeds;
downloads, checksum-verifies, and bounds-validates the result artifact
when present (§6, §7); and calls directly into the same persistence logic
§8/§10/§11 already assume, under the existing claim-then-check-then-
persist discipline `MediaCanonicalManager` already uses elsewhere. The
SQS message is deleted only once persistence succeeds, so a crash between
receipt and persistence simply results in ordinary redelivery once the
visibility timeout elapses (§11, §12) — no second internal queue hop, no
generic event bus, one process, one validation step, one persistence
call.

### 4. Signing audience: worker-facing vs. browser-facing signed URLs

**The existing signer cannot be reused as-is.** `S3MediaDeliveryUrlSigner`
always signs against `media.upload.public_endpoint`
(`AWS_PUBLIC_ENDPOINT`) — in local development, `http://localhost:4570`,
reachable from the host/browser. `apps/image-ai` runs inside the Docker
network, where LocalStack is already configured (`compose.yaml`'s
`image-ai` service, `SQS_ENDPOINT`/`SQS_PREFIX`) as `http://localstack:4566`
— a different hostname, unreachable from inside that network via the
browser-facing one. A URL signed for the browser audience is therefore
not fetchable by the worker locally, and this ADR does not propose
changing Docker networking to paper over that, nor granting the worker
broad standing S3 permissions as a workaround (§3).

**The existing signing abstraction gains an explicit audience concept**,
rather than a duplicate, unrelated signer:

- **Browser audience** — unchanged existing behaviour, signs against
  `media.upload.public_endpoint`.
- **Service audience** — new, signs against the same internal endpoint
  (`filesystems.disks.s3.endpoint`/`AWS_ENDPOINT`) the API container's
  own `S3MediaObjectStorage::internalClient` already uses for its own
  Laravel-initiated reads and writes.

This mirrors a pattern already present in this codebase: `S3MediaObjectStorage`
already holds two separate S3 clients — an `internalClient` for Laravel's
own operations and a `signingClient` for browser-facing presigned URLs
(`app/Media/S3MediaObjectStorage.php`). `S3MediaDeliveryUrlSigner` is
extended to hold the equivalent second client, and both
`MediaDeliveryUrlSigner::authorizeRead()` and
`MediaObjectStorage::authorizeSingleWrite()` gain an explicit audience
parameter, so a caller states which audience a URL is for rather than one
always being assumed. Both audiences produce a genuine, narrowly scoped S3
presigned URL for exactly one object; the only difference is which network
hostname is embedded in the signature, matching whichever caller will
actually perform the HTTP request. Every URL Laravel generates for the
Python worker (§5, §6) uses the service audience; every URL Laravel
generates for a browser (existing media delivery, unchanged) uses the
browser audience.

**In production this distinction collapses to a no-op.**
`config/media.php`'s existing fallback —
`'public_endpoint' => env('AWS_PUBLIC_ENDPOINT', env('AWS_ENDPOINT'))` —
already means the two audiences resolve to the same real S3 endpoint
whenever only one is configured, which is the expected production shape.
The audience concept exists specifically to make local Docker-network
development work correctly, not to introduce a production-only mechanism.

### 5. Canonical asset delivery

The canonical asset (ADR-0007 §9 — reconfirmed, §16) is fetched by the
worker via a **narrowly scoped, single-object, GET-only presigned S3 URL,
signed for the service audience (§4)**, reusing the existing
`MediaDeliveryUrlSigner`/`S3MediaDeliveryUrlSigner` mechanism rather than
inventing a new signing path. This is not a callback into Laravel's
application: the worker performs a plain HTTPS `GET` directly against S3
(or LocalStack in development), never touching a Laravel route or
process.

Standing, bucket-wide read credentials for the worker are explicitly
rejected (§ Alternatives) in favour of this per-request, per-object
scoping: the media bucket holds every Family Space's original and
canonical assets, and a presigned URL scoped to exactly the one object
being analysed keeps the worker's actual reach exactly as narrow as the
one Photo it was asked to process, regardless of how many Family Spaces
exist — the worker never receives standing S3 object permissions of any
kind (§3).

The TTL for this URL is deliberately distinct from, and may exceed, the
15-minute cap already used for interactive browser delivery
(`media.delivery.authority_ttl_minutes`) — a queued analysis request can
sit behind a processing backlog for longer than an interactive session
reasonably should. The exact number is implementation-calibrated (§12),
not fixed here, but the ADR fixes the principle: it must be long enough
to survive realistic queue backlog, and "the URL expired before the
worker fetched it" must be treated as its own named, retryable failure
category (§12) — never conflated with "the object does not exist."

**Before running inference, the worker must independently verify that the
downloaded bytes hash to the checksum carried in the request message.** A
mismatch is a failure (§12: `checksum_mismatch`), never a silently
degraded or partial analysis. This mirrors `MediaCanonicalManager::generate()`'s
own `hash_equals($sourceSha256, $actualChecksum)` check when Laravel
itself reads the original, applied here across the service boundary.

### 6. Result-artifact transport — superseding ADR-0001's inline-first assumption

ADR-0001 §Risks describes an "oversized result payload" risk and a
deliberate default: results begin inline in the completion message, and
"the completion event can instead carry a private object-storage
reference and checksum... only if measured payload sizes justify it."

**This ADR explicitly supersedes that inline-first default for
`ImageAnalysisCompleted`, by architectural decision — not because
`FPA-P09-S01` measured serialized payload sizes.** S01 measured face
*counts* and image *conditions*; it did not measure serialized
`DetectedFace` payload bytes, and this ADR does not claim otherwise. The
decision rests on architectural reasoning available without that
measurement:

- an embedding is a variable-size biometric payload, and a
  `FaceAnalysisRun`'s result size scales with however many faces a photo
  happens to contain — a hidden per-photo face-count ceiling is exactly
  the kind of undocumented limit this project's ADR discipline (ADR-0007
  §1, ADR-0010 §3) already avoids elsewhere;
- landmarks and quality/diagnostic metadata scale per face on top of the
  embedding itself;
- SQS enforces a hard message-size ceiling regardless of how comfortably
  a *typical* result might fit within it;
- the repository already has a narrow, write-once, checksum-friendly
  signed-object primitive (`MediaObjectStorage::authorizeSingleWrite()`/
  `WriteOnceS3SignatureV4`) that makes the reference-based design nearly
  as cheap to build correctly as the inline one — the usual cost of the
  "more robust" option is largely absent here;
- a bounded, fixed-shape reference message gives stable, predictable
  transport semantics regardless of result cardinality, which a "usually
  small, occasionally large" inline payload does not.

As an **illustrative, non-measured sanity check only** — not a benchmark
claim — a small InsightFace-class embedding (roughly 512 float32 values,
~2KB) plus landmarks and JSON/encoding overhead is on the order of a few
kilobytes per face; a handful of faces stays comfortably inline, but a
large family gathering, a school photo, or a crowd scene plausibly would
not, and nothing in this ADR wants the contract's correctness to depend
on how many people happened to be in one photo. This reasoning, not a
measurement, is why the reference design is chosen now rather than
waiting for S04/S06 to *prove* the inline default insufficient.

This is a narrow, explicit revision to ADR-0001's Risks section for this
one message type, in the same spirit ADR-0010 §6 narrowly revised
ADR-0008 §11/§12 for comment scoping — it does not reopen ADR-0001 §1-§4's
settled Laravel/Python ownership, statelessness, or asynchronous-
messaging decisions, all of which stand unchanged.

**Mechanism:**

- When dispatching `ImageAnalysisRequested`, Laravel additionally
  generates a **narrowly scoped, write-once, service-audience presigned
  PUT URL** (§4) — via `MediaObjectStorage::authorizeSingleWrite()`
  (`WriteOnceS3SignatureV4`'s `If-None-Match: *` collision protection) —
  targeting one result-artifact object key derived from the attempt's own
  identifier (§8): `FamilyStorageKey::for($family_space_id,
  "face-analysis/{attempt_id}/result.json")`, mirroring the existing
  `media/{media_upload_id}/canonical.{ext}` key shape.
- The worker serialises its full `DetectedFace` list (§2), tagged with
  `contract_version`, as the result artifact's body and `PUT`s it to that
  URL. Write-once semantics mean a second, racing attempt to write a
  different result to the same key cannot silently overwrite the first —
  and because a retry always uses a brand-new attempt identifier and
  therefore a brand-new key (§8), a write-once target is never contested
  across retries either.
- `ImageAnalysisCompleted` carries only: the attempt identifier
  (`request_id`), `contract_version`, `family_space_id`, `media_upload_id`,
  the canonical checksum, the full requested analysis identity (`provider`,
  `model_identifier`, `model_weight_checksum`, `config_hash` — §10), the
  result artifact's object key, the result artifact's own SHA-256, and the
  detected-face count. This keeps the SQS message itself small and
  bounded regardless of how many faces a photo contains.
  `ImageAnalysisFailed` carries the same identifying fields plus a small,
  closed set of failure categories (§12) and a short, non-biometric
  failure detail string — never a stack trace containing image data,
  never an embedding, never a face crop — and writes no result artifact
  at all.
- Laravel's consumer (§3) downloads the artifact (`MediaObjectStorage::downloadTo`),
  independently recomputes its SHA-256, and rejects it — category
  `result_checksum_mismatch` — if it does not match the value declared in
  the message, the same discipline §5 requires in the opposite direction
  for the canonical asset. Only after this verification, and §7's bounds
  validation, does it parse and persist the `DetectedFace` entries as
  `FaceObservation` rows (§8, §10).
- The result artifact is a **transient hand-off artifact, not a durable
  derived asset** — `FaceObservation` rows in Postgres (§8) are Phase 9's
  actual durable derived data. Laravel deletes the artifact
  (`MediaObjectStorage::delete`) once persistence succeeds; as a backstop
  against a failed or abandoned attempt leaving artifacts behind, the
  `face-analysis/` prefix also carries a short S3 lifecycle-expiry rule,
  mirroring ADR-0007 §18's bounded retention of `quarantine/` objects —
  this backstop is distinct from, and does not substitute for, explicit
  Family Space teardown (§19). The exact retention window is
  implementation detail (`FPA-P09-S05`), not fixed here.

### 7. Result-artifact safety bounds and validation

The result artifact is an external service boundary — Laravel treats it
as untrusted input, not as a trusted extension of its own process,
regardless of the checksum and write-once protections §6 already provides
against tampering. Before any `DetectedFace` entry is persisted, Laravel
validates the downloaded artifact against closed, contract-level bounds:

- **`contract_version`** must be one of a known, closed set of supported
  versions; an unrecognised version is rejected outright, never
  best-effort parsed.
- **Total artifact byte size** must not exceed a configured hard maximum.
- **Detected-face count** must not exceed a configured hard maximum per
  run.
- **Embedding dimension and dtype** must exactly match the closed, known
  set of dimensions/dtypes the run's declared `provider`/
  `model_identifier`/`model_weight_checksum` is expected to produce; an
  embedding vector whose actual length does not equal its own declared
  `embedding_dimension` is rejected.
- **Landmark count/scheme** must match one of a closed, enumerated set of
  supported `landmark_scheme` identifiers (§2); an unrecognised scheme is
  rejected.
- **`provider_diagnostics` size** is bounded per entry, independent of the
  rest of the payload, so one verbose field cannot itself defeat the
  overall artifact-size bound.
- **JSON structure** is validated against the fixed contract shape (§2)
  with a bounded nesting depth consistent with that shape being naturally
  shallow (run → faces\[] → a handful of scalar/array fields); an
  artifact nested deeper than the contract could ever legitimately
  require is rejected as malformed, not parsed defensively.
- **Numeric validity**: every numeric field must be finite. The artifact
  is parsed with a strict, RFC 8259-compliant JSON decoder, which cannot
  represent `NaN` or `±Infinity` as valid tokens at all; each numeric
  value is additionally checked as finite during validation as
  defence-in-depth against a provider that serialises a non-standard
  token as a string.
- **Geometry validity**: a bounding box must lie within `[0, 0]` to the
  canonical asset's own persisted `pixel_width`/`pixel_height` (already
  recorded on `MediaUpload` by `MediaCanonicalManager`), must not be
  inverted or negative, and landmark coordinates must satisfy the same
  bound.

**A message on `image-analysis-completed` whose artifact fails any of
these checks is recorded as a failed attempt (§8, §11), never persisted as
succeeded, regardless of what the worker itself believed.** Laravel's own
validation, not Python's self-reported success, is what makes a run
`succeeded`. The failure category is `result_artifact_invalid` for a
structural/geometry/numeric validation failure, and
`result_artifact_oversized` specifically for exceeding a size or
face-count bound, extending §12's closed failure-category set;
`result_checksum_mismatch` (§6) is recorded separately, and both are
distinct from `checksum_mismatch` (§5's canonical-input check).

**The presigned PUT URL itself does not enforce a byte-size ceiling.**
SigV4 presigned `PUT` requests, unlike presigned POST policies, have no
native content-length-range mechanism the existing signing abstraction
(§4) can express, and this ADR does not pretend otherwise. The size bound
above is therefore enforced by Laravel, after download, before parsing —
not preventively at the point of write. This is an accepted, explicitly
stated limitation, not a silent gap.

Every limit above is a **configured, mandatory hard limit**, with a
conservative default fixed at implementation time. `FPA-P09-S03`
implements the validation and rejection mechanism itself, against
conservative placeholder defaults; `FPA-P09-S04` supplies calibrated real
values once the actual chosen provider's (§18) typical and worst-case
output is measured. This ADR fixes the *requirement* that such limits
exist and are enforced before S04 selects their values — never "unlimited
until calibrated" — not a specific number invented without provider
evidence.

### 8. Persistence model: run, dispatch attempt, and observation

```text
FaceAnalysisRun
    ↓ (one logical identity, one-or-more dispatch attempts over its lifetime)
FaceAnalysisAttempt(s)
    ↓ (the one attempt that succeeds, zero or more observations)
FaceObservation(s)
```

Two closely related but genuinely distinct concepts are separated on
purpose:

- **`FaceAnalysisRun`** is the *logical analysis identity* (§10's tuple) —
  "this `MediaUpload`, this checksum, this provider/model/config, has this
  one outcome, ever." It is what §10's idempotency and §13's immutability
  rules describe, and it is keyed to a `MediaUpload`, not a `Photo` — the
  same choice ADR-0007/ADR-0010 already made for canonical generation and
  perceptual hashing, both of which run against a `ready` `MediaUpload`'s
  canonical asset regardless of whether it has yet been promoted to a
  `Photo`. It records the identity tuple, aggregate status, and
  timestamps.
- **`FaceAnalysisAttempt`** is *one concrete dispatch of that run to
  Python* — a distinct row **created and persisted by Laravel before
  `ImageAnalysisRequested` is ever published**, carrying its own
  identifier (which becomes the message's `request_id`), the expected
  result-artifact object key derived from that identifier (§6), and its
  own status (`dispatched` → `succeeded` | `failed` | `superseded`). A
  retry is always a **new** `FaceAnalysisAttempt` row with a new
  identifier and a new expected artifact key — never a reused one, so a
  write-once result target is never contested by a retry. It records:
  `face_analysis_run_id`, `family_space_id`, its own id, the expected
  result-artifact key, status, `dispatched_at`, `resolved_at`, and a
  failure category when applicable.

`FaceObservation` belongs to the **run**, not to any individual attempt,
since only the one attempt that actually succeeds ever produces
observations — recording them against the attempt would need re-pointing
them at the run afterward for no benefit. It records one detected face:
bounds, landmarks and landmark scheme, detection confidence, the embedding
plus its dimension and dtype, and any `quality_signals`/
`provider_diagnostics` (§2). A `FaceAnalysisRun` that completes
successfully with **zero** `FaceObservation` children is a valid,
meaningful outcome — "this image was analysed and no face was found" —
and must remain distinguishable both from "analysis failed" and from
"never analysed."

**Laravel never trusts a message's self-reported identity fields on their
own.** `family_space_id`, `media_upload_id`, and the analysis-identity
fields Python echoes back on `ImageAnalysisCompleted`/`Failed` (§6) are
cross-checked against the attempt's own Laravel-persisted state, looked
up **by `request_id` first** — a message whose `request_id` does not
match a currently-`dispatched` attempt is never trusted to establish
anything about which run or Family Space it concerns, regardless of what
its own body claims. §12 defines exactly what happens to such a message.

**Tenant-consistent relational integrity, not RLS alone.**
`FaceObservation`, `FaceAnalysisAttempt`, and `FaceAnalysisRun` itself
must each be structurally incapable of referencing a parent record in a
different Family Space, even by accident — RLS (§19) prevents a *query*
from crossing that boundary, but does not, by itself, prevent a *row*
from being written with an inconsistent foreign key in the first place.
This applies to all three tenant-crossing relationships this ADR
introduces, not only the two between its own new tables:

- `face_analysis_runs` carries a secondary `UNIQUE (id, family_space_id)`
  constraint alongside its primary key, and both
  `face_observations.(face_analysis_run_id, family_space_id)` and
  `face_analysis_attempts.(face_analysis_run_id, family_space_id)` are
  declared as **composite foreign keys** against that pair — not
  independent single-column foreign keys to `face_analysis_runs.id`
  alone.
- **`FaceAnalysisRun` itself references a `MediaUpload`, and that
  relationship requires the identical treatment.** The existing
  `media_uploads` table gains a supporting secondary
  `UNIQUE (id, family_space_id)` constraint, and
  `face_analysis_runs.(media_upload_id, family_space_id)` is declared as
  a composite foreign key against that pair — not an independent
  single-column foreign key to `media_uploads.id` alone. **This
  supporting constraint on `media_uploads` is purely additive**: `id`
  remains `media_uploads`' sole primary key and sole identity, exactly as
  ADR-0007 already fixed; the new constraint adds no column, changes no
  existing tenancy or state-machine semantics, and is compatible with
  every existing reference to `media_uploads.id` — it exists solely to
  give this one new composite foreign key something to reference.

A write attempting to pair a valid `face_analysis_run_id` (or
`media_upload_id`) with the *wrong* `family_space_id` is rejected by the
database itself in every one of these three relationships, not merely
hidden from cross-tenant queries. **A `FaceAnalysisRun` in one Family
Space is therefore structurally unable to reference a `MediaUpload`
belonging to a different Family Space, exactly as `FaceObservation`/
`FaceAnalysisAttempt` are structurally unable to reference a
`FaceAnalysisRun` in a different Family Space.** This goes beyond the
single-column-FK-plus-RLS shape `duplicate_candidates`/
`duplicate_decisions` (ADR-0010) already use — a deliberate,
phase-specific strengthening for biometric data, not a correction of
ADR-0010's own reasonable choice for Photo-pair relationships.

Neither `FaceAnalysisRun`/`FaceAnalysisAttempt` nor `FaceObservation` has
any relationship to `PhotoPerson`, and no code path introduced by this ADR
may write to `PhotoPerson` (§14).

### 9. Embedding storage: scope, not engine

Phase 9 stores each `FaceObservation`'s embedding as an ordinary,
versioned column on that row — ordinary PostgreSQL storage (e.g. a binary
or fixed-length array column, tagged with its own dimension/dtype),
**not** a vector-indexed or ANN-searchable store of any kind. Nothing in
Phase 9 performs a similarity query, so nothing in Phase 9 needs one.

This ADR **explicitly declines to choose between pgvector, Qdrant, or any
other option** for that reason — the choice belongs to ADR-0012, already
named by `tasks.json`'s `FPA-P10-S02 — "Implement embedding storage and
similarity queries"` as a Phase 10 stage in its own right. Deciding it
here would have Phase 9 quietly doing Phase 10's job on the strength of
convenience rather than an actual query requirement, exactly the kind of
premature commitment this project's ADR discipline exists to avoid. The
current stack has neither option available for free in any case: the
development database is plain `postgres:17.6-alpine` with no vector
extension installed, and no Qdrant service exists anywhere in this
repository's infrastructure.

Phase 9's only obligation here is that whatever raw form the embedding is
stored in must be sufficient for Phase 10 to read it back and index it
into whatever store ADR-0012 selects, without needing to re-run inference.

### 10. Analysis identity and idempotency

A `FaceAnalysisRun`'s identity is the tuple:

```text
family_space_id
+ media_upload_id
+ canonical_sha256          (the checksum actually analysed)
+ provider
+ model_identifier
+ model_weight_checksum
+ config_hash
```

**The weight checksum, not a human-assigned version label, is the
authoritative identity component for the model.** A label like `"v2"` is
useful for humans and is stored alongside the checksum for readability,
but is never trusted as identity on its own — a mislabelled or silently
re-exported weight file must still be detected as a different model,
consistent with this project's existing checksum-over-self-reported-
metadata posture (`original_sha256`, `canonical_sha256`,
`(algorithm, processing_version)` for perceptual hashing).

**`config_hash` covers the logical inference configuration only** —
provider, model, weights, preprocessing, detection thresholds, and any
other parameter capable of actually changing the output — and
deliberately **excludes hardware/execution-provider choice** (CPU vs. a
platform-specific accelerator such as CoreML or CUDA): a worker's
execution backend is deployment/operational detail, not an analysis
input, and treating it as part of identity would fabricate a "new run"
purely from which machine happened to process the request. Execution
backend is instead recorded as OpenTelemetry span/metric metadata (§20),
never as part of the persisted analysis identity. This separation holds
unless benchmark evidence later shows a given execution backend changes
embeddings beyond an accepted numerical tolerance, in which case that
divergence is a named review trigger (§ Review triggers), not a silent
exception folded in here.

**Exactly one `FaceAnalysisRun` row exists per identity tuple, ever.** A
request whose full identity already has a **succeeded** row is idempotent:
no new row, no re-analysis, the existing result stands. A request whose
identity already has a **failed** row is retried via a new attempt (§8)
of the same run — the run row's status and attempt history are updated,
not a second run row created. Once a run reaches `succeeded`, it is
terminal: nothing may move it back to `processing`, `pending`, or
`failed`, and none of its `FaceObservation` children may be altered
(§13). **Any change to any component of the identity tuple — a different
provider, model, weight checksum, config, or a changed canonical
checksum — is unconditionally a new, independent run**, never a mutation
of an existing one. This is a fixed rule, not a configurable option:
making it configurable would let two otherwise-identical requests diverge
in behaviour depending on an environment flag, exactly the kind of
ambiguity ADR-0010 §9 already rejected for duplicate decisions.

**At the attempt level**, exactly one terminal outcome is ever accepted
per attempt, guarded by an explicit state transition rather than a bare
update: a `FaceAnalysisAttempt` moves from `dispatched` to `succeeded` or
`failed` only via a compare-and-set performed under `lockForUpdate` inside
a transaction — "transition this row from `dispatched` to X, but only if
it is still `dispatched`." Whichever valid terminal event reaches that
transition first wins — a genuine completion/failure message, or §12's
own timeout reconciliation; anything else referencing the same attempt
afterward (a redelivered duplicate, a genuinely late arrival, or
reconciliation racing a late success) finds the row already resolved and
no-ops. This is the same claim-then-check-then-persist discipline
`MediaCanonicalManager` already uses elsewhere, applied at the attempt
granularity. Duplicate/redelivered messages for an already-resolved
attempt are therefore always idempotent by construction, and completed/
failed queues never need to be ordered relative to each other, since at
most one of them is ever meaningfully acted on per attempt.

If a later attempt for the same run has already succeeded by the time an
earlier attempt's outcome arrives, that earlier attempt (if still
`dispatched`) transitions to `superseded` rather than being left
permanently `dispatched` — informational bookkeeping only, since the run
itself is already terminal and nothing further happens as a result.

### 11. Reprocessing and invalidation

**Phase 9 has exactly one automatic dispatch trigger.** An ordinary
`FaceAnalysisRun` (and its first `FaceAnalysisAttempt`) is requested
automatically, once, whenever a `MediaUpload`'s canonical asset becomes
available, under whatever provider/model/config identity is currently
configured as the default — the same automatic-dispatch shape already
used for `GeneratePresentationMediaVariants` (ADR-0007) and
`GeneratePerceptualSimilarity` (ADR-0010). This is the **only** automatic
trigger this ADR defines; §15's reprocessing mechanism is the only other
way a `FaceAnalysisRun` is ever requested, and it is never automatic.

The table below describes what a **subsequent, separately-triggered**
request would resolve to given various changes in state — it does not
itself describe anything self-triggering. A changed canonical checksum,
provider, model, or configuration only changes what identity a future
request would carry; none of these changes, by themselves, cause a new
`FaceAnalysisRun`/`FaceAnalysisAttempt` to be dispatched.

| State change | What a subsequent request would resolve to |
|---|---|
| Canonical asset regenerated, checksum unchanged | The same identity as before — idempotent reuse of the existing succeeded run (§10), not a new one. |
| Canonical checksum changes | A different identity tuple (§10) — *if* an analysis is subsequently requested for it, that request resolves to a new, independent run; the checksum change itself dispatches nothing. |
| Model, provider, or config upgrade | Likewise a different identity — reachable only via §15's explicit, bounded, audited reprocessing trigger, never dispatched automatically merely because a new model/config value exists. |
| Attempt times out or fails | Retried via a new `FaceAnalysisAttempt` (§8, §12) under the same run identity, up to a configured maximum attempts-per-run, then the run is left `failed` pending deliberate reprocessing or investigation. |
| Duplicate message delivery (either direction) | Attempt dispatch is unique per identifier (§8); persistence is idempotent per attempt (§10), since SQS delivers at least once regardless of dispatch-side dedup. |
| A message referencing a `MediaUpload`/Photo whose state has since changed | The persisting consumer re-verifies current state before writing, under the same claim-then-check-then-persist pattern `MediaCanonicalManager` already uses. |
| Photo soft-deleted | `FaceAnalysisRun`/`FaceAnalysisAttempt`/`FaceObservation` history is left untouched — never purged — mirroring ADR-0010 §9's treatment of `DuplicateDecision` under soft deletion. |
| Photo restored | The same untouched history simply becomes reachable again; nothing is resurrected because nothing was removed. |

### 12. Queueing, dead-letter handling, and stale-attempt reconciliation

**Phase 9 is the first place this repository actually enables SQS's
dead-letter/redrive capability**, despite ADR-0003 having chosen SQS
specifically for this capability from the start. Each of the three
dedicated queues (§3) gets its own `RedrivePolicy` pointing at its own
dead-letter queue. This is deliberately **not** retrofitted onto the
pre-existing `fambam-jobs` queue as part of this ADR — that queue predates
Phase 9, is out of this ADR's scope, and is noted instead as a review
trigger (§ Review triggers).

**None of the three queues is consumed through Laravel's ordinary
`ShouldQueue`/`queue:work` pipeline** — `image-analysis-requested` is
consumed by a plain Python process (§3), and `image-analysis-completed`/
`-failed` are consumed by the dedicated raw-SQS adapter §3 defines, not a
framework-managed job. Neither Laravel's `tries = 3` job-retry mechanism
nor the generic `failed_jobs` table (`config/queue.php`) applies to any
of the three, since both are specific to messages that entered the
framework's own job pipeline. **The queue-level `RedrivePolicy`/
dead-letter queue is therefore each of the three queues' primary
protection against a poison message, uniformly** — not a backstop behind
a framework-level layer that doesn't exist here. The adapter and the
Python worker are each responsible for their own bounded in-process retry
behaviour before simply not deleting a message and letting SQS's own
redelivery/`maxReceiveCount` take over; persistence or inference failures
on this path are surfaced through structured logging and OpenTelemetry
(§20), not `failed_jobs`.

A message exceeding its queue's `maxReceiveCount` lands on that queue's
dead-letter queue; it must never be silently dropped or retried forever.
Failure categories carried on `ImageAnalysisFailed`, or recorded by
Laravel against an attempt after its own validation, are a closed, named
set — at minimum `checksum_mismatch` (§5), `canonical_unavailable`
(including a URL that expired before fetch, §5), `decode_error`,
`inference_error`, `timeout`, `result_checksum_mismatch` (§6),
`result_artifact_invalid`, `result_artifact_oversized` (§7), and
`attempt_timed_out` (below) — never a raw exception message that might
embed image data.

**`VisibilityTimeout` and `maxReceiveCount` for each queue are not fixed
by this ADR.** They must exceed realistic worst-case inference latency —
sized too short, a slow-but-healthy run gets treated as failed and
redelivered mid-processing, wasting compute without causing incorrect
data (§10's idempotent persistence still prevents a duplicate result, but
the waste is real). These numbers are implementation-calibrated from
`FPA-P09-S04`'s real measured latency, the same way ADR-0010 §8 deferred
the perceptual-hash Hamming threshold to empirical calibration ahead of
implementation rather than freezing a guessed number here.

**Transport state and database state must never diverge indefinitely.**
An SQS message reaching its queue's dead-letter queue is a
*transport*-level event; it says nothing on its own about whether the
`FaceAnalysisAttempt` (§8) it concerns is still meaningfully `dispatched`
in Laravel's own database — the two must be reconciled, not left to drift
apart silently.

- **An attempt is stale** once it has remained `dispatched` longer than a
  configured staleness window — implementation-calibrated from real
  inference latency alongside `VisibilityTimeout`/`maxReceiveCount`
  above, not fixed here, but always at least as generous as the queue's
  own worst-case redelivery timing.
- **A dedicated, scheduled Laravel command** — mirroring the existing
  "scheduler discovers due work" shape already used by
  `DispatchDueAbandonedMediaUploads`/`DispatchDueMediaQuarantinePurges`/
  etc. (`app/Console/Commands`), run via the existing `schedule:work`
  process (`compose.yaml`) — periodically finds `dispatched` attempts
  past their staleness window and transitions each, under the same
  guarded compare-and-set as §10, to `failed` with category
  `attempt_timed_out`.
- **The parent `FaceAnalysisRun`** then follows exactly §11's "attempt
  times out or fails" row: retried via a fresh attempt up to a configured
  maximum attempts-per-run — consistent with, though not necessarily
  numerically identical to, this codebase's existing `tries = 3`
  convention — after which the run is left terminally `failed`, pending
  §15's deliberate reprocessing trigger or direct investigation.
- **This reconciliation command is the sole place a `dispatched` attempt
  is ever moved out of that state by anything other than a genuine,
  validated completion/failure message.** It never needs to inspect the
  DLQ directly — DB-side staleness alone is sufficient and robust even if
  the underlying transport-level DLQ event is never separately observed.
- **A stale or manually redriven DLQ message is always safe, never
  corrupting.** Because every completion/failure message is accepted
  only by `request_id` lookup against a currently-`dispatched` attempt
  (§8), and because attempts are single-use and never reused across
  retries, redelivering or manually redriving an old message for an
  attempt that reconciliation has already resolved simply finds that
  attempt no longer `dispatched` and no-ops — the same guard that makes
  ordinary redelivery idempotent (§10) makes a stale DLQ redrive
  idempotent too, with no separate mechanism required.
- **Operator recovery never mutates database state directly.** An
  operator inspecting a DLQ can correlate a message back to its attempt
  and run via `request_id`, read that attempt/run's current status
  through ordinary means, and — if genuine recovery is warranted —
  invoke §15's bounded, audited reprocessing trigger to create a fresh
  run and attempt. No Phase 9 mechanism requires, or should be exercised
  via, hand-editing a status column; this holds without needing Phase
  15's polished admin UI, since the reconciliation command and the
  reprocessing trigger together are already sufficient for Phase 9's own
  operational needs.

### 13. `FaceObservation` immutability and forward-compatibility

Every `FaceObservation` row, once written, is **immutable and permanent**
for the lifetime of its parent `FaceAnalysisRun` (subject only to the
same soft-delete/restore treatment as the run itself, §11). It is never
updated in place and never reused across runs or attempts — a new run,
however similar its results, always creates entirely new rows. This gives
Phase 10 a stable, permanent anchor: a `FaceObservation` row's identity
(its own primary key) is safe to reference from a future human-
confirmation mechanism without that mechanism needing to know or care
that the underlying model might be superseded later, because superseding
it never mutates or removes what was already there.

Deliberately **not** decided here, because it is Phase 10's problem to
solve, not Phase 9's: whether two observations from different runs
represent "the same physical face region" over time (bounding-box drift,
a different model detecting a different face count in the same image), or
how a future confirmed identity eventually references one or many
observations across a person's appearance over time. Phase 9's only
obligation is that the anchors it hands to Phase 10 never move or vanish
underneath it.

### 14. Human truth vs. machine-derived data

```text
machine (this ADR):
- FaceAnalysisRun       — one logical analysis identity and its terminal outcome
- FaceAnalysisAttempt   — one concrete dispatch of a run to Python (§8)
- FaceObservation       — one detected face: bounds, landmarks, embedding

human (ADR-0006 / ADR-0008, unchanged):
- Person
- PhotoPerson        — "this is William" — proposed and confirmed
- family relationship facts
```

No schema introduced by this ADR references, is referenced by, or
resembles `PhotoPerson`. Replacing the provider, model, or configuration
never touches — and structurally cannot touch — anything a human has
confirmed, because the two live in entirely separate tables with no
foreign key between them. The bridge between the two — a machine
observation becoming a *proposed* `PhotoPerson` candidate for a human to
confirm — is exactly the boundary ADR-0008 §5 already fixed and exactly
the mechanism ADR-0012 will need to design; this ADR builds the machine
side only and leaves that bridge entirely unbuilt.

### 15. Reprocessing trigger: explicit, bounded, audited

A backend-operator-triggerable mechanism (an Artisan command or an
internal, authenticated endpoint — no polished administration UI is
required now) allows deliberately requesting a new `FaceAnalysisRun`
(with a fresh `FaceAnalysisAttempt`, §8) under a new provider/model/config
identity for an explicitly bounded set of `MediaUpload`s (a specific
list, or one Family Space — never an unscoped, platform-wide action).
**No code path may automatically dispatch reprocessing across existing
analysis merely because a new model or configuration becomes available**
— that is a standing, latent "automatic rediscovery silently overriding
history" risk of exactly the shape ADR-0010 §9 already rejected for
duplicate decisions, applied here to model upgrades instead.

Invoking this mechanism is the one deliberate, privileged human action in
an otherwise fully-automatic phase, and is recorded as an `AuditEvent`
(§20) — who triggered it, its scope, and the requested identity — even
though Phase 9 defines no polished caller-authorization surface for it
yet. Exactly which role or identity is authorized to invoke it in
production, and whether that eventually runs through Family Space
Owner/Administrator authority or through the separate Platform
Administrator role Phase 15/ADR-0020 will define, is **not decided here**
(§ Deferred concerns) — Phase 15's roadmap scope already names
"face-analysis... processing health" as its own concern, and this
mechanism is a natural fit for that surface once it exists.

### 16. Canonical asset — reconfirmed

ADR-0007 §9 carries an explicit review trigger to reconfirm, at Phase 9
scoping, that the canonical asset remains the sole permitted analysis
input. It is reconfirmed here: the canonical is full-resolution, correctly
oriented, sRGB-normalized, and checksum-addressed, all of which are
properties that help rather than hinder face detection and landmarking,
and none of which was found to justify introducing a new,
permanently-stored analysis-specific variant. The original is rejected as
an analysis input because it retains sensitive metadata and heterogeneous
formats the canonical was built specifically to normalise away;
presentation variants are rejected because they may crop or downsize
faces below what detection needs.

### 17. Benchmark harness discipline

The private, non-repository benchmark corpus (`.local/benchmarks/face-
analysis/`, already gitignored) is exercised by invoking the provider
**directly** — a standalone script analogous in shape to
`scripts/consume_synthetic.py`, calling the provider abstraction (§2) as a
plain function against the local asset files — with **no SQS traffic, no
Laravel involvement, and no application-database persistence of any
kind.** Results are written to a private, gitignored local summary file,
never into `face_analysis_runs`/`face_analysis_attempts`/
`face_observations`. This keeps calibration evidence reproducible and
inspectable without ever risking a benchmark artefact being mistaken for
real family data in a production or shared development database.

`FPA-P09-S05`'s job is a different exercise entirely: proving the real
`SQS → provider → SQS → Laravel persistence` pipeline end-to-end against
ordinary, throwaway development Photos, which needs no relationship to
the benchmark corpus at all.

### 18. Initial model selection and licensing record

Consistent with `docs/IMPLEMENTATION_GUIDE.md`'s requirement that
`FPA-P09-S02` resolve "initial local model and licensing" before
implementation begins, this ADR names the intended initial candidate now,
closing that gate, rather than deferring the choice itself to
`FPA-P09-S04` — while the operational act of fetching, hashing, and
directly confirming that specific file's licence text remains, as it
always was, something only S04 can actually do.

**Intended initial candidate:** InsightFace's `buffalo_l` model pack
(RetinaFace-based detection, ArcFace-based recognition embeddings),
distributed through the InsightFace project's own model zoo and run
locally through ONNX Runtime — the same "InsightFace-compatible... through
ONNX Runtime" candidate `PROJECT_ROADMAP.md` and
`docs/IMPLEMENTATION_GUIDE.md` already name for Phase 9. This is named as
the intended starting point for `FPA-P09-S04`, not a claim that it has
already been benchmarked or downloaded; if S04 finds it unsuitable,
swapping to a different InsightFace-compatible pack within the same
provider abstraction (§2) is an ordinary provider/model change under §10
— a new analysis identity, never an invisible overwrite of anything this
ADR already decided.

**Current assumption, recorded plainly, not as a legal opinion:**

- ONNX Runtime's own licence (MIT) is independent of model choice and not
  in question.
- InsightFace's own source-code licence must be confirmed by direct
  inspection of the specific pinned package version `FPA-P09-S04` actually
  vendors, before that version is vendored — this ADR does not assert a
  specific licence identifier as fact without having read it.
- The `buffalo_l` pack's pretrained weights, like InsightFace's
  model-zoo packs generally, are treated as licensed for
  **non-commercial research use only** as the working assumption,
  independent of how permissively the surrounding library code is
  licensed — to be directly reconfirmed against the actual licence text
  accompanying the specific downloaded pack in `FPA-P09-S04`, not assumed
  forever on the strength of this ADR alone.
- Current fambam use is private, non-commercial development and
  evaluation, which this assumption is sufficient for.
- **Commercialisation review trigger, standing and unchanged:** before
  any commercial fambam deployment, or before any commercial distribution
  or use of these pretrained weights, the exact applicable licence must
  be re-reviewed and commercial permission or terms obtained where
  required. No commercial licence or quote is obtained now, and none is
  required to proceed with Phase 9.

`FPA-P09-S04` must record, once the pinned package/weight files are
actually fetched: the exact model/weight identifier and checksum (feeding
§10's `model_weight_checksum`), direct confirmation of both licences above
against their actual text, and any deviation from the `buffalo_l`
candidate named here with its own reasoning.

### 19. Tenancy, biometric privacy, and Family Space teardown

`face_analysis_runs`, `face_analysis_attempts`, and `face_observations`
are ordinary Class C tenant-owned content tables under ADR-0005 §9: an
explicit `family_space_id` column on all three (not reachable only by
join), `FORCE ROW LEVEL SECURITY`, and the same non-superuser, non-
`BYPASSRLS` runtime role already governing every other tenant-owned
table. Every query path touching any of them must filter on
`family_space_id` from the moment these tables exist, even though nothing
in Phase 9 itself performs a similarity query — the discipline must be in
place before Phase 10 starts writing nearest-neighbour queries against
this data, not retrofitted once it does. **No mechanism in Phase 9
compares, matches, or ranks a `FaceObservation` against any other Family
Space's data, ever.**

Embeddings, face crops, landmark coordinates, and any other biometric
payload must never appear in an OpenTelemetry span attribute, a log line,
a metric label, or an SQS message body — only checksums, IDs, counts, and
provider/model/config labels are safe there (§20). This extends, for
biometric data specifically, the same privacy posture ADR-0007 already
applies to GPS metadata: present in the durable record where it belongs,
deliberately withheld from operational surfaces that don't need it.

**Database rows need no new teardown mechanism.** `face_analysis_runs`,
`face_analysis_attempts`, and `face_observations` are ordinary
tenant-owned Postgres tables, and ADR-0005's already-authorised Family
Space deletion lifecycle already covers removing every such table's rows
idempotently as part of deleting the tenant, exactly as ADR-0007 §18
already established for Phase 5's own tables — no exception here.

**Transient result artifacts in object storage are a genuine, small
addition to that lifecycle, not covered automatically.**
`S3FamilyMediaStorageCleaner` walks a fixed, named set of per-Family-Space
object-storage prefixes on deletion — `media-staging`, `media`,
`quarantine`, `event-exports`
(`apps/api/app/Media/S3FamilyMediaStorageCleaner.php:34`); `face-analysis/`
is a new prefix this ADR introduces (§6) and is not in that list today.
**Explicit Family Space deletion must add `face-analysis` to that
existing, already-generalised prefix walker** — a one-line addition to an
already-correct mechanism, not a new teardown concept, and not a redesign
of ADR-0005. §6's S3 lifecycle-expiry rule on that prefix is a bounded
safety backstop for artifacts a normal run/attempt lifecycle failed to
clean up promptly; it is explicitly **not** a substitute for this — a
family that deletes their Family Space must not have to wait out an
expiry window for their result artifacts to be gone, any more than they
would for their media.

### 20. Observability: telemetry, persisted data, and audit

Following the pattern already established twice — ADR-0007 §17
("processing durations, decoder failures, retry counts... are
OpenTelemetry spans and metrics... not archival provenance") and
ADR-0010 §11 ("automatic `DuplicateCandidate` generation is not itself an
audited event... the moment it becomes meaningful is the moment a human
acts on it"):

- **OpenTelemetry spans and metrics**: analysis and queue latency,
  inference duration, detected-face count, no-face-detected count,
  provider/model/config/execution-backend as span attributes, failure
  category, retry/attempt count, canonical image dimensions, memory where
  measurable.
- **Persisted analysis data** (Postgres, not telemetry): everything on
  `FaceAnalysisRun`/`FaceAnalysisAttempt`/`FaceObservation` — identity,
  status, timestamps, bounds, landmarks, embeddings, confidence, quality
  signals. This is derived data, not audit history.
- **`AuditEvent`**: ordinary, automatic per-Photo face analysis generates
  **no** audit rows — there is no human decision anywhere in that path,
  including the stale-attempt reconciliation command (§12), which is
  automatic bookkeeping, not a human decision. The one exception is §15's
  deliberate reprocessing trigger, which is audited exactly because it is
  a deliberate, privileged human action, not an automatic background
  process.

## Alternatives considered

- **HTTP request/response between Laravel and Python, or a Python-to-
  Laravel completion callback** — already rejected by ADR-0001 §4;
  reaffirmed here for the same reasons (Laravel-availability coupling,
  weaker retry/idempotency semantics than a consumed message).
- **Standing, bucket-wide IAM read/write credentials for the worker**,
  instead of per-request presigned URLs — rejected: the media bucket
  spans every Family Space, and standing broad access would give the
  worker a far larger blast radius than the one object it was actually
  asked to process, directly working against §19's tenancy discipline.
- **Changing local Docker networking so the worker can reach
  `localhost:4570` directly**, avoiding the need for a signing-audience
  distinction — rejected, per explicit product direction: the network
  topology is not the problem to solve here, and the audience concept is
  small, reusable, and collapses to a no-op in production anyway (§4).
- **Signing worker-facing and browser-facing URLs identically**, ignoring
  the Docker-network reachability difference — not a real alternative so
  much as a latent local-development bug: the resulting URL would simply
  be unfetchable by the worker, discovered only at runtime.
- **Embedding raw image bytes, or the `DetectedFace` list (including
  embedding vectors), inline in the SQS message body** — rejected: SQS's
  message-size ceiling makes this impractical for the request direction
  regardless of preference, and for the response direction it would
  impose a hidden, undocumented cap on how many faces one photo could
  ever report — exactly the "oversized result payload" risk ADR-0001 §
  Risks already named. §6's result-artifact-plus-reference mechanism is
  the adopted alternative.
- **Waiting for `FPA-P09-S04`/`S06` measured payload-size evidence before
  adopting the result-artifact design**, per ADR-0001's original
  inline-first default — considered, and rejected: §6's architectural
  reasoning is available now without that measurement, and shipping a
  contract designed around an unmeasured inline assumption risks
  discovering the hidden face-count ceiling only after real family photos
  exercise it.
- **Splitting a multi-face result across several smaller SQS messages**
  instead of one result artifact — rejected: still couples message
  transport to face count, just at a different threshold, and adds
  partial-delivery/reassembly-ordering complexity the artifact approach
  avoids entirely, since object storage has no comparable per-item
  ceiling.
- **Consuming Python-published result messages through Laravel's ordinary
  `php artisan queue:work`/`ShouldQueue` pipeline** — rejected: that
  pipeline expects Laravel's own job-envelope format, not arbitrary
  externally-produced JSON, and this repository has no existing
  convention for injecting a foreign message into it. A dedicated raw-SQS
  consumer (§3), mirroring the Python side's own
  `consume_synthetic.py`-shaped approach, is the smallest adapter that
  actually fits.
- **A second internal Laravel queue hop between the raw-SQS adapter and
  persistence** — rejected as unnecessary indirection: ADR-0001 §4
  already described this as one step ("consumes the result message,
  validates it, and persists"), and the adapter validating and persisting
  directly, in one process, is the smaller design.
- **A single shared queue with a type discriminator** (mirroring the
  existing synthetic-message pattern) — rejected in favour of three
  dedicated, directional queues: the least-privilege IAM split this ADR
  requires is materially simpler to express and to reason about per-queue
  than per-message-type on one queue.
- **AWS Lambda for the Python inference compute** — rejected: an
  ONNX-Runtime-plus-pretrained-weights workload benefits from a long-lived
  process holding a loaded model in memory, which Lambda's cold-start
  model actively works against, alongside Lambda's tighter package-size
  constraints for model-weight distribution; ECS/Fargate gives an
  independently scalable, always-warm worker instead.
- **Choosing pgvector or Qdrant now for embedding storage** — rejected:
  no similarity query exists in Phase 9 to justify either, and
  `tasks.json`'s own `FPA-P10-S02` already assigns this decision to
  ADR-0012. Deciding it here would have Phase 9 doing Phase 10's job on
  convenience rather than a real requirement.
- **A single flat table instead of `FaceAnalysisRun` → `FaceObservation`**
  — rejected: collapses "zero faces detected" (a real, meaningful
  successful outcome) into the same shape as "never analysed," and forces
  run-level fields (provider/model/config/status) to either duplicate
  across every observation row or be modelled awkwardly around a
  variable-cardinality result.
- **No separate dispatch-attempt concept — retrying a run by mutating a
  single row's attempt count in place, with `request_id` equal to the
  run's own id** — rejected: cannot express "a write-once result target
  is never reused across retries" (a retry would collide with the prior
  attempt's own artifact key), cannot safely distinguish a stale message
  from a prior attempt from a current one by identity alone, and cannot
  give reconciliation (§12) a clean compare-and-set target independent of
  the run's own longer-lived lifecycle.
- **Independent single-column foreign keys plus RLS only**, matching the
  precedent `duplicate_candidates`/`duplicate_decisions` (ADR-0010)
  already use — considered, and rejected specifically for Phase 9's
  biometric tables: RLS prevents a cross-tenant *query*, not a
  cross-tenant *write*, and this ADR chooses to close that gap at the
  schema level for face data even though ADR-0010 reasonably did not for
  Photo-pair relationships.
- **Updating `FaceObservation` rows in place on reprocessing**, rather
  than always creating new ones — rejected: breaks the permanent-anchor
  guarantee §13 gives Phase 10, and violates the "must not invisibly
  overwrite" requirement this whole ADR is built around.
- **Making "reuse an identical result vs. always create a new run"
  configurable** — rejected: an environment-dependent idempotency policy
  would let two otherwise-identical requests behave differently depending
  on a flag, the same ambiguity ADR-0010 §9 already rejected for
  duplicate-decision suppression.
- **Automatic mass reprocessing whenever a new model or configuration is
  deployed** — rejected outright by product direction; reprocessing must
  always be an explicit, bounded, audited action (§15).
- **Relying solely on Laravel's own `tries`/`failed_jobs` for poison-
  message protection**, with no SQS-level DLQ — rejected: neither the
  request queue nor the result queues have a `ShouldQueue`-based consumer
  to provide that layer, and even a framework-managed consumer could be
  bypassed by a sufficiently malformed message.
- **Retrofitting a dead-letter policy onto the pre-existing `fambam-jobs`
  queue as part of this ADR** — rejected as out of scope; noted instead as
  a review trigger, since it predates Phase 9 and fixing it is an
  independent, general improvement, not something this ADR should bundle
  in.
- **Treating a message landing in a DLQ as itself sufficient to mark a run
  failed** — rejected: transport-level DLQ arrival and database-level
  attempt state are deliberately reconciled by a separate, DB-driven
  staleness check (§12) rather than trusting a transport signal Laravel
  might never directly observe.
- **Persisting benchmark-run `FaceAnalysisRun`/`FaceAnalysisAttempt`/
  `FaceObservation` rows into ordinary application tables for calibration
  evidence** — rejected: the
  benchmark harness invokes the provider directly instead (§17), keeping
  calibration entirely outside application persistence.
- **Freezing specific `VisibilityTimeout`/`maxReceiveCount`/staleness-
  window/artifact-bound numbers in this ADR** — rejected: these need real
  latency and payload evidence from `FPA-P09-S04`, which does not exist
  yet; guessing numbers now would be worse than deferring them to the
  same kind of empirical, pre-implementation calibration gate ADR-0010 §8
  already used for the perceptual-hash threshold.
- **Leaving the initial model/weights choice entirely to `FPA-P09-S04`,
  unnamed by this ADR** — rejected: `docs/IMPLEMENTATION_GUIDE.md`
  requires `FPA-P09-S02` to resolve the initial model and licensing;
  naming `buffalo_l` now (§18) closes that gate honestly, without
  requiring prior benchmarking to select it and without overclaiming
  licence text this ADR has not directly inspected.

## Consequences

### Positive

- Every risk ADR-0001 explicitly named as still open for this ADR to
  resolve (production service identity, transport auth, compute
  deployment) now has a concrete, named answer, closing that ADR's
  deferral cleanly.
- Reusing `MediaDeliveryUrlSigner` and `MediaObjectStorage::authorizeSingleWrite()`/
  `WriteOnceS3SignatureV4` for canonical/result-artifact delivery (§4-§6),
  extended with an explicit audience concept, means the cross-service
  asset-access mechanism is already implemented, already proven for
  local/production parity, and needs no unrelated new signing code — and
  the audience extension is itself reusable by any future cross-service
  asset-delivery need, not just Phase 9's.
- Routing detection results through a checksum-verified, bounds-validated
  object reference rather than an inline SQS payload (§6, §7) means a
  photo's face count can never silently hit a transport-imposed ceiling,
  and the worker still never holds standing S3 object permissions of any
  kind — only two narrowly scoped, single-object presigned URLs per
  request.
- Reconciling ADR-0001's inline-first default honestly (§6), rather than
  claiming measured evidence that doesn't exist, keeps this project's ADR
  trail trustworthy for future readers.
- Declining to choose an embedding-search engine (§9) keeps Phase 9's
  actual footprint honest: it only builds what it actually needs, and
  leaves ADR-0012 a completely clean decision uncomplicated by a Phase-9
  fait accompli.
- The run/attempt split (§8) closes a real async-correctness gap cheaply:
  write-once result targets are never contested across retries,
  Python-supplied identity fields never establish authority on their own,
  and stale or redriven messages are safe by construction rather than by
  convention.
- The `FaceAnalysisRun`/`FaceObservation` identity and immutability rules
  (§10, §13) mean a future model upgrade is safe by construction — old
  results are never silently lost or mutated, and Phase 10 gets a
  permanent, stable set of anchors to build human confirmation on top of.
- Turning on SQS's dead-letter capability for the first time (§12), paired
  with DB-side staleness reconciliation, closes both the transport-level
  gap that has existed since ADR-0003 chose SQS specifically for this
  reason and the database-level "stuck forever" gap a DLQ alone would not
  have closed, without requiring this ADR to take on fixing every other
  queue in the system.

### Negative

- Three new dedicated queues, each with its own dead-letter queue and
  IAM policy, is more infrastructure to declare and operate than a single
  shared queue would have been.
- `FaceAnalysisRun`, `FaceAnalysisAttempt`, and `FaceObservation` are
  three genuinely new tables with a non-trivial identity/versioning/state-
  machine rule set, on top of everything Phase 8 already introduced —
  real, if justified, additional schema surface.
- The tenant-consistent composite foreign key (§8) is more schema ceremony
  than the single-column-FK-plus-RLS precedent Phase 8 established,
  deliberately accepted for biometric data specifically.
- The reprocessing trigger (§15) exists with no polished authorization
  surface yet, meaning until Phase 15 exposes it properly, invoking it
  safely in production depends on operational discipline (who has server/
  deploy access) rather than an in-app permission check.
- The result-artifact mechanism (§6) and the signing-audience extension
  (§4) are genuinely new, bespoke pieces — a Laravel-side raw-SQS
  consumer, a transient object-storage lifecycle, and a second signing
  audience on a shared abstraction other future callers will need to be
  aware of — real added complexity over a hypothetical simpler design that
  this repository has no safe existing way to build.

### Risks

- If the worker is ever given standing bucket-wide S3 object permissions
  instead of per-request presigned URLs (§5), the "worker's reach is
  scoped to exactly what it was asked to process" guarantee this ADR
  relies on for tenancy safety would be silently broken — worth a direct
  test asserting the worker's IAM identity cannot read or write an object
  it was not explicitly handed a URL for.
- If `config_hash` is implemented to include hardware/execution-provider
  details, spurious "new runs" could be created purely from which machine
  processed a request, inflating `face_analysis_runs` without any real
  change in analysis — worth a direct test that identical logical config
  on two different execution backends produces the same `config_hash`.
- If the Laravel-side raw-SQS adapter (§3) is not built with the same
  claim-then-check-then-persist discipline `MediaCanonicalManager` already
  uses, at-least-once SQS delivery could create duplicate
  `FaceObservation` rows for a single successful run — worth a direct
  redelivery test.
- If the result artifact's SHA-256 or bounds are not independently
  verified by Laravel before parsing (§6, §7), or if the presigned PUT is
  ever implemented without write-once (`If-None-Match: *`) semantics, a
  stale, corrupted, oversized, or racing artifact write could be silently
  persisted as though it were the genuine result of a given run — worth a
  direct test asserting a checksum-mismatched or bounds-violating artifact
  is rejected as a failure, never partially persisted.
- If any of the three tenant-consistent composite foreign keys (§8) —
  `FaceObservation`/`FaceAnalysisAttempt` → `FaceAnalysisRun`, or
  `FaceAnalysisRun` → `MediaUpload` — is implemented as an independent
  single-column FK "for simplicity," the cross-tenant relational-integrity
  guarantee this ADR relies on is silently lost for that relationship —
  worth a direct test for each, inserting a mismatched-tenant row and
  asserting a database-level rejection, not merely an RLS-query-level one.
- If the stale-attempt reconciliation command's compare-and-set (§12) is
  not correctly guarded, a race between reconciliation and a genuinely
  late success message could leave an attempt or its parent run in a
  contradictory state — worth a direct concurrent test racing a late
  completion against reconciliation for the same attempt.
- If `maxReceiveCount`/`VisibilityTimeout`/the staleness window are tuned
  too aggressively relative to real inference latency (§12), healthy-but-
  slow runs could be redelivered or reconciled-as-failed mid-processing
  repeatedly, wasting compute without necessarily causing incorrect data
  (idempotent persistence still holds) — worth measuring real S04 latency
  before finalising these values rather than guessing.
- If the reprocessing trigger (§15) is ever implemented without the
  explicit scope bound this ADR requires, an accidental platform-wide
  reprocess could impose a very large, unbudgeted compute cost — worth a
  direct test that the mechanism rejects an unscoped invocation.
- If benchmark-harness code (§17) is ever accidentally pointed at the
  production/application persistence path instead of invoking the
  provider directly, private calibration imagery or its derived data
  could leak into ordinary application tables — worth a direct check that
  the benchmark script has no code path capable of writing to
  `face_analysis_runs`/`face_analysis_attempts`/`face_observations`.
- If Family Space deletion is implemented without adding `face-analysis`
  to `S3FamilyMediaStorageCleaner`'s prefix list (§19), transient result
  artifacts for a deleted Family Space could persist until the backstop
  lifecycle rule eventually expires them, rather than being removed
  immediately as part of deletion like the rest of that Family Space's
  media.

## Implementation notes

- **`FPA-P09-S03` — provider and transport contracts only.** Implements
  §2 (the stable provider contract, `contract_version`, and the
  `contracts/events` wire shapes for `ImageAnalysisRequested`/
  `Completed`/`Failed`), §4 (the signing-audience extension to
  `MediaDeliveryUrlSigner`/`MediaObjectStorage`), §6's result-artifact
  schema and reference mechanism, §7's bounds/validation rules (against
  conservative placeholder defaults), and the `FaceAnalysisRun`/
  `FaceAnalysisAttempt`/`FaceObservation` schema and tenant-consistent
  composite-FK design (§8) — including the additive
  `UNIQUE (id, family_space_id)` migration on the existing `media_uploads`
  table, which must land before, and in the same migration sequence as,
  the `face_analysis_runs.(media_upload_id, family_space_id)` composite
  foreign key that depends on it. It does **not** require
  production-shaped queue infrastructure, calibrated
  `VisibilityTimeout`/`maxReceiveCount`/staleness values, or a real
  provider — those are S04/S05's concern.
- **`FPA-P09-S04` — local provider.** Implements the actual InsightFace-
  compatible provider (§18: intended candidate `buffalo_l`) through ONNX
  Runtime, callable directly per §2's requirement; completes the
  licensing record (§18) against the specific pinned package/weight files
  fetched; runs the private benchmark harness (§17) to produce real
  latency, memory, and result-size evidence; and calibrates §7's bound
  defaults and the `config_hash` exclusion's cross-backend assumption
  (§10) against that evidence.
- **`FPA-P09-S05` — queued analysis pipeline.** Implements: the queue/IAM
  topology of §3/§12 (queue creation, per-direction least-privilege
  policies, dead-letter queues) using real production-shaped
  infrastructure; the Laravel-side raw-SQS adapter's persistence logic
  (§3, calling into §8/§10's model); worker-facing signed URL delivery in
  practice (§4-§6); durable dispatch-attempt creation before dispatch
  (§8); idempotent, tenant-consistent Laravel persistence; automatic
  per-upload dispatch (§11); the stale-attempt reconciliation command
  (§12) and its scheduled invocation; `VisibilityTimeout`/
  `maxReceiveCount`/staleness values informed by S04's measurements; the
  bounded, audited reprocessing trigger (§15); and the `face-analysis`
  addition to `S3FamilyMediaStorageCleaner`'s teardown prefix list (§19).
  Proves the pipeline against ordinary development Photos, never the
  benchmark corpus (§17).
- **`FPA-P09-S06` — operational benchmark and metrics.** Implements the
  final end-to-end measurement and operational acceptance work: the
  direct-invocation benchmark script's final calibrated run (§17) and the
  operational telemetry set (§20).
- **Required regression tests**: (1) two requests with an identical full
  analysis identity must produce exactly one `FaceAnalysisRun` row, the
  second treated as idempotent reuse; (2) a changed provider, model,
  weight checksum, config, or canonical checksum must always produce a
  new, independent run, never a mutation of an existing successful one;
  (3) a failed attempt must be retryable via a new attempt under the same
  run identity and must not produce a second run row once the run
  succeeds; (4) two Family Spaces sharing an identical canonical checksum
  must never expose, reference, or leak each other's
  `FaceAnalysisRun`/`FaceAnalysisAttempt`/`FaceObservation` data; (5)
  redelivering the same `ImageAnalysisCompleted` message must not create
  duplicate `FaceObservation` rows; (6) a completion/failure message
  referencing a `MediaUpload`/Photo whose state has since changed must not
  corrupt or wrongly persist data; (7) soft-deleting a Photo must not
  delete its analysis history, and restoring it must not fabricate or
  reinterpret that history; (8) the worker must reject and report as
  `checksum_mismatch` any canonical download that fails to verify against
  the requested checksum, never silently analysing it; (9) the
  reprocessing trigger must reject an unscoped/platform-wide invocation
  and must record an `AuditEvent` including its actual scope; (10) no
  embedding, face crop, or landmark coordinate may appear in any log line,
  span attribute, metric label, or SQS message body emitted by the
  analysis path — only a result-artifact reference and checksum may
  appear on `ImageAnalysisCompleted`; (11) a message exceeding
  `maxReceiveCount` on any of the three Phase 9 queues must land on that
  queue's dead-letter queue, not be dropped or retried indefinitely; (12)
  no code path introduced by this ADR may write to `PhotoPerson`; (13) a
  result artifact whose downloaded SHA-256 does not match the value
  declared on `ImageAnalysisCompleted` must be treated as a failure, never
  parsed or partially persisted; (14) the raw-SQS adapter must reject a
  message that does not validate against the fixed
  `ImageAnalysisCompleted`/`Failed` shape, or an unsupported
  `contract_version`, without crashing the consumer process; (15)
  `config_hash` computed for the same logical configuration on two
  different execution backends (e.g. CPU vs. an available accelerator)
  must be identical; (16) a result artifact exceeding the configured
  byte-size, face-count, embedding-dimension, or geometry bounds must be
  rejected with the appropriate `result_artifact_invalid`/
  `result_artifact_oversized` category, never partially persisted; (17)
  an `ImageAnalysisCompleted`/`Failed` message whose `request_id` does not
  match a currently-`dispatched` `FaceAnalysisAttempt` must be rejected
  without trusting any of its other claimed fields; (18) inserting a
  `FaceObservation` or `FaceAnalysisAttempt` whose `family_space_id` does
  not match its referenced `FaceAnalysisRun`'s `family_space_id` must be
  rejected at the database level; (19) inserting a `FaceAnalysisRun`
  whose `family_space_id` does not match its referenced `MediaUpload`'s
  `family_space_id` must likewise be rejected at the database level; (20)
  a `dispatched` attempt past its staleness window must be transitioned
  to `failed`/`attempt_timed_out` by the reconciliation command, and a
  genuine late completion racing that transition must resolve to exactly
  one winner, never both; (21) redriving a stale DLQ message for an
  attempt already resolved by reconciliation must no-op safely, never
  reopening or corrupting the
  run's terminal state.
- §19's tenancy scoping and §13's immutability guarantee are cross-
  cutting: every stage above should be checked against both during
  review.

## Review triggers

- **When Phase 10 (ADR-0012) is scoped**: confirm every reference to a
  `FaceObservation` is by stable identity only, never by mutating it, and
  confirm the machine-to-proposed-`PhotoPerson` bridge (§14) is built as a
  genuinely new mechanism rather than relaxing `PhotoPerson`'s existing
  non-overwrite guarantee.
- **When Phase 10 selects an embedding-search engine (ADR-0012)**: decide
  whether Postgres-stored embeddings (§9) are read once and indexed
  elsewhere, or whether Postgres storage is retired in favour of the new
  store as source of truth — not decided here.
- **When Phase 15 (Platform Administration) is scoped**: expose §15's
  reprocessing trigger, and §12's reconciliation/DLQ inspection, through
  an authorized, audited admin surface, using Platform Administrator
  authorization (ADR-0020) rather than Family Space Owner/Administrator,
  consistent with Phase 15's own roadmap scope naming "face-analysis...
  processing health" explicitly.
- **When Phase 13 (export, portability, backup and recovery) is scoped**:
  confirm `FaceAnalysisRun`/`FaceAnalysisAttempt`/`FaceObservation` are
  treated as recognition data excluded from export by default, consistent
  with Phase 13's already-fixed roadmap exit criterion ("Recognition-data
  exclusion by default") — not decided further here.
- **When Phase 16 (security/privacy hardening) is scoped**: revisit
  whether biometric data specifically (as distinct from ordinary Class C
  tenant content) warrants additional encryption-at-rest or a bounded
  retention period beyond what ordinary tenant-owned tables already get.
- **If real `FPA-P09-S04`/`S06` measurements make the calibrated
  `VisibilityTimeout`/`maxReceiveCount`/staleness-window/artifact-bound
  values unsuitable**: recalibrate directly; this is not an ADR-level
  change.
- **If benchmark evidence ever shows a given execution backend (e.g. a
  platform accelerator vs. CPU) changes embeddings for the same logical
  configuration beyond an accepted numerical tolerance**: revisit §10's
  exclusion of execution backend from `config_hash` — that finding would
  mean the assumption underpinning the exclusion no longer holds for that
  backend, not merely an operational curiosity.
- **If a second Python-side analysis capability is added later** (e.g.
  Phase 18 semantic embeddings): give it its own dedicated queues, dead-
  letter queues, and signing-audience usage rather than folding it into
  Phase 9's, consistent with the least-privilege reasoning in §3/§4/§12.
- **If the pre-existing `fambam-jobs` queue is ever given a dead-letter
  policy**: note that Phase 9 was the precedent that proved the pattern,
  not an obligation this ADR created for that queue.
- **If `FPA-P09-S04` finds `buffalo_l` (§18) unsuitable**: record the
  replacement candidate and reasoning in the Implementation Guide/journal
  at that time; this is an ordinary provider/model change under §10, not
  an ADR-level reconsideration.

## Deferred concerns

- Exact physical schema, column types, and indexing for
  `face_analysis_runs`/`face_analysis_attempts`/`face_observations` — the
  shape (§8, §10, §13) is fixed, the columns are not.
- The ANN/vector-search engine for embeddings (pgvector, Qdrant, or
  otherwise) — ADR-0012/`FPA-P10-S02`, explicitly not this ADR.
- Exact `VisibilityTimeout`/`maxReceiveCount`/backoff/staleness-window
  values for each queue and attempt (§12) — `FPA-P09-S04`/`S05`
  calibration, not fixed here.
- Exact result-artifact byte-size, face-count, and `provider_diagnostics`
  size limits (§7) — the requirement that bounds exist and are enforced
  is fixed; the numbers are `FPA-P09-S04` calibration.
- Exact result-artifact PUT-URL TTL and S3 lifecycle-expiry window for
  the `face-analysis/` prefix (§6) — the principle (bounded, backstop
  cleanup) is fixed, the numbers are `FPA-P09-S05` implementation detail.
- The exact interface for the reprocessing trigger (Artisan command vs.
  an internal endpoint) and its production caller-authorization mechanics
  — implementation-guide detail now, Phase 15/ADR-0020 concern for a
  polished surface (§15, § Review triggers).
- The exact set of `quality_signals` fields beyond the fixed contract in
  §2 — provider-diagnostic detail, not architectural.
- The exact `buffalo_l` weight-file checksum and direct confirmation of
  its licence text — `FPA-P09-S04`, once the pinned files are actually
  fetched (§18).

## Resolved decisions

1. **Scope** — Phase 9 detects, aligns, embeds, and persists; it assigns
   no identity, suggests no `Person`, and writes no `PhotoPerson` row.
2. **Provider contract** — a stable, provider-agnostic, versioned
   (`contract_version`) `DetectedFace` shape plus a run-level analysis
   identity, carried in the result artifact (§6) rather than inline on
   any SQS message; provider-specific diagnostics quarantined in one
   opaque field, never branched on.
3. **Transport** — three dedicated, directional SQS queues; ECS/Fargate
   selected by this ADR as the production compute target for
   `apps/image-ai`, never Lambda; least-privilege IAM per direction
   covering SQS operations only, never standing S3 object permissions; no
   Python-to-Laravel HTTP callback of any kind; a dedicated Laravel-side
   raw-SQS adapter, not `ShouldQueue`/`queue:work`, consumes the two
   result queues.
4. **Signing audience** — the existing `MediaDeliveryUrlSigner`/
   `MediaObjectStorage` signing abstraction is extended with an explicit
   browser-vs-service audience, mirroring `S3MediaObjectStorage`'s
   existing internal/signing-client split; every worker-facing URL uses
   the service audience (the internal `AWS_ENDPOINT`), collapsing to the
   same endpoint as the browser audience in production.
5. **Canonical delivery** — a narrowly scoped, single-object, GET-only,
   service-audience presigned S3 URL, reusing `MediaDeliveryUrlSigner`; no
   standing bucket-wide worker credentials; worker-side checksum
   verification before inference is mandatory.
6. **Result-artifact transport** — a narrowly scoped, write-once,
   service-audience presigned PUT delivers the full `DetectedFace` list
   to a per-attempt object; `ImageAnalysisCompleted` carries only a
   bounded reference (key, checksum, face count); this explicitly and
   narrowly supersedes ADR-0001's inline-first default for this message
   type, by architectural decision, not measured S01 evidence.
7. **Result-artifact bounds** — mandatory, configured hard limits on
   artifact size, face count, embedding dimension/dtype, landmark
   scheme, diagnostics size, and structural/numeric/geometric validity;
   conservative defaults now, calibrated values from `FPA-P09-S04`;
   Laravel's own validation, not Python's self-report, determines a run's
   success.
8. **Persistence model** — `FaceAnalysisRun` (logical identity, keyed to
   `MediaUpload`) → one-or-more `FaceAnalysisAttempt`s (one per dispatch,
   created before send, own `request_id` and result-artifact key) → the
   succeeding attempt's `FaceObservation` rows; a zero-observation
   successful run is a valid, distinct outcome from failure or "never
   analysed"; Python-supplied identity fields never establish authority —
   only a `request_id` match against a persisted, `dispatched` attempt
   does.
9. **Tenant-consistent integrity** — `face_observations` and
   `face_analysis_attempts` use composite `(face_analysis_run_id,
   family_space_id)` foreign keys against a secondary unique constraint on
   `face_analysis_runs`; `face_analysis_runs` itself uses a composite
   `(media_upload_id, family_space_id)` foreign key against a matching,
   purely additive `UNIQUE (id, family_space_id)` constraint added to the
   existing `media_uploads` table; all three relationships reject a
   cross-tenant reference at the database level — a `FaceAnalysisRun` in
   one Family Space cannot reference a `MediaUpload` in another, exactly
   as its own child rows cannot reference a `FaceAnalysisRun` in another —
   beyond the RLS-plus-independent-FK precedent Phase 8 used for Photo
   pairs.
10. **Embedding storage** — an ordinary versioned Postgres column for
    Phase 9; the searchable/ANN-indexed form and its engine are explicitly
    deferred to ADR-0012/`FPA-P10-S02`.
11. **Analysis identity** —
    `family_space_id + media_upload_id + canonical_sha256 + provider +
    model_identifier + model_weight_checksum + config_hash`, with the
    weight checksum, not a human label, as the authoritative model
    identity component; hardware/execution-provider excluded from
    identity unless benchmark evidence later shows material divergence.
12. **Idempotency** — exactly one `FaceAnalysisRun` row per identity,
    ever; a succeeded run is terminal and reused; retries occur via new
    `FaceAnalysisAttempt` rows under the same run; an attempt's terminal
    transition is a guarded compare-and-set, so redelivery, late
    arrivals, and reconciliation racing a late success are all safely
    idempotent; this rule is fixed, not configurable.
13. **Reprocessing/invalidation** — the only automatic dispatch trigger is
    the ordinary once-per-upload dispatch on canonical availability; a
    changed checksum, provider, model, or config only changes what
    identity a *future, separately-triggered* request would carry and
    never itself dispatches anything; reprocessing under a new identity is
    otherwise always explicit, bounded, and audited (§15), never
    automatic; soft deletion/restoration never touches analysis history.
14. **DLQ and stale-attempt reconciliation** — each of the three Phase 9
    queues gets its own `RedrivePolicy` and dead-letter queue, acting as
    each queue's primary poison-message protection since none goes
    through `ShouldQueue`/`failed_jobs`; a dedicated scheduled command
    independently reconciles stale `dispatched` attempts to `failed` in
    the database, so transport state and database state cannot diverge
    indefinitely; operator recovery is always through the reprocessing
    trigger, never direct status mutation; this is the first use of SQS
    dead-lettering in this repository and does not retrofit the
    pre-existing `fambam-jobs` queue.
15. **`FaceObservation` immutability** — permanent, never mutated or
    reused across runs or attempts, giving Phase 10 a stable reference
    anchor without Phase 9 needing to solve cross-run reconciliation.
16. **Human vs. machine boundary** — `PhotoPerson` is untouched by this
    ADR; the future proposal bridge is entirely ADR-0012's to build.
17. **Tenancy** — ordinary Class C tenant-owned tables under ADR-0005;
    `family_space_id` on every query path and every foreign key from day
    one; no cross-Family-Space comparison of any kind; explicit Family
    Space teardown must extend `S3FamilyMediaStorageCleaner`'s prefix list
    to include `face-analysis`, with lifecycle expiry as backstop only.
18. **Observability** — OpenTelemetry for operational metrics (including
    execution backend), Postgres for derived analysis data, `AuditEvent`
    only for the deliberate reprocessing trigger; no biometric payload in
    logs, spans, metric labels, or message bodies, ever.
19. **Benchmark discipline** — the private benchmark harness invokes the
    provider directly, with no SQS traffic and no application-table
    persistence; `FPA-P09-S05` proves real persistence separately, against
    ordinary development Photos.
20. **Initial model and licensing** — `buffalo_l` (InsightFace, ONNX
    Runtime) is named as the intended initial candidate now, closing
    `FPA-P09-S02`'s licensing-and-model gate; current use is private/
    non-commercial; source-code and model-weight licences are confirmed
    against actual text, and the exact weight checksum recorded, once
    `FPA-P09-S04` fetches the pinned files; a standing commercialisation
    review trigger applies before any commercial deployment or
    distribution.
21. **Canonical asset** — reconfirmed, unchanged, as the sole permitted
    analysis input (ADR-0007 §9).
