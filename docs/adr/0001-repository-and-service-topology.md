# ADR-0001: Repository and service topology

- Status: Accepted
- Date: 2026-07-31
- Decision owners: David
- Related stages: FPA-P00-S02, FPA-P01-S02

## Context

The product vision specifies three runtime components — a frontend, a Laravel
API, and a Python image-analysis service — with Laravel as the business
authority and Python restricted to ML inference. Before scaffolding
(FPA-P00-S03), we need to settle repository topology and the inter-service
contract, since these are expensive to reverse once code exists.

## Decision

1. **Single monorepo** containing `apps/web`, `apps/api`, `apps/image-ai`,
   `contracts/`, `docs/`, `infrastructure/`, `scripts/`, `tests/e2e`, per the
   shape already sketched in IMPLEMENTATION_GUIDE.md.
2. **Laravel is the sole owner of business data and the sole PostgreSQL
   writer.** People, relationships, photos, albums, events, permissions,
   audit history, and all provider-specific derived analysis live in
   Postgres, written only by Laravel. Python has no database access of any
   kind — not even a restricted or analysis-specific schema. This keeps
   schema evolution, tenancy rules, auditing and lifecycle policy inside one
   authority.
3. **Python is a stateless inference boundary.** It holds no business-meaning
   data and no durable state of its own; it consumes a request, performs
   inference, and emits a result.
4. **Communication is asynchronous and message-mediated, not HTTP
   request/response, and not shared-database.** The flow is:

   1. Laravel creates an analysis request and publishes a versioned
      `ImageAnalysisRequested` message containing the request identifier, a
      signed reference to the canonical image, and the inference
      configuration.
   2. Python consumes the request, performs inference, and publishes a
      versioned `ImageAnalysisCompleted` or `ImageAnalysisFailed` message.
   3. A Laravel worker consumes the result message, validates it, and
      persists the provider-specific derived analysis.

   Python never calls back into Laravel over HTTP, and never writes to
   Postgres. Completion is observed by Laravel consuming its own queue, not
   by depending on Python reaching a Laravel endpoint.

## Alternatives considered

- **Polyrepo per service** — rejected for now: this is a solo/small-team
  project; polyrepo adds coordination overhead (versioning, cross-repo PRs)
  without a corresponding team-scaling benefit at this stage.
- **Python writes directly to Postgres**, including a restricted
  analysis-only schema — rejected: even scoped write access couples the
  Python service to Laravel-owned schema evolution and reintroduces a second
  writer to business data, which undermines "Laravel is the sole database
  writer."
- **Python-to-Laravel HTTP callback** — rejected: makes result delivery
  depend on Laravel's HTTP availability at the moment Python finishes, mixes
  a service-to-service auth concern into the request path, and gives weaker
  retry/idempotency semantics than a consumed message with an ack/redelivery
  model.
- **Frontend repo separate from backend monorepo** — rejected: no evidence
  yet of independent release cadences that would justify the split; revisit
  if the frontend gets its own deploy pipeline with different velocity.

## Consequences

### Positive

- One CI pipeline, one versioned history, atomic cross-service commits.
- Clear, enforceable service boundary: Postgres access is a Laravel-only
  concern, so "Python is inference-only" is structural, not just a
  convention.
- Message-mediated completion gives explicit retry and idempotency
  semantics (redelivery, dead-lettering, at-least-once processing) rather
  than relying on HTTP callback success.
- Laravel's availability at the moment Python finishes inference is no
  longer a dependency for job completion.
- Contract changes between API and Python are visible in the same diff.

### Negative

- Mixed toolchains (PHP, Python, JS/TS) in one repo increases CI and local
  dev-environment complexity (addressed in ADR-0004, local container
  strategy).
- Message-mediated results require Laravel-side consumers to handle
  out-of-order delivery, duplicate messages, and validation of
  externally-produced payloads before persistence.

### Risks

- **Oversized result payloads**: large inference results (e.g. many face
  embeddings per photo, or bulk batch jobs) may not fit comfortably in a
  queue message. The initial design keeps results inline in the completion
  message; if payloads later prove too large, the completion event can
  instead carry a private object-storage reference and checksum, with the
  full payload fetched by the Laravel consumer. This is not required for
  the initial design and should only be adopted if measured payload sizes
  justify it.
- **Message contract drift**: `ImageAnalysisRequested`/`Completed`/`Failed`
  must be versioned from the start, since Laravel and Python evolve on
  different release cadences and only share the wire contract, not code.

## Implementation notes

- `contracts/events` should define `ImageAnalysisRequested`,
  `ImageAnalysisCompleted`, and `ImageAnalysisFailed` explicitly and
  versioned, since this is the one place two different languages must agree
  on a wire format.
- Concrete broker choice, delivery guarantees, and queue topology are
  deliberately **not** decided here and are deferred to ADR-0003 (Local
  development platform), which covers queue transport and background-job
  ownership under the current one-ADR-per-phase methodology.
- Production service identity, message-transport authentication/authorization
  between Laravel and Python, and compute deployment for the Python service
  are deferred to ADR-0011 (Local face analysis foundation), which covers
  inference deployment, service identity and compute strategy for the Python
  service under the current one-ADR-per-phase methodology.

## Review triggers

- If the Python service ever needs sub-100ms synchronous inference latency
  at volumes where asynchronous message round-trips become unacceptable.
- If measured result-payload sizes make inline message bodies impractical,
  triggering the object-storage-reference pattern described above.
- If the frontend gains an independent deploy cadence from the API.
