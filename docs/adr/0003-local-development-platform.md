# ADR-0003: Local Development Platform

- Status: Accepted
- Date: 2026-08-01
- Decision owners: David
- Related stages: FPA-P01-S01 (accepting this ADR completes that stage;
  see Implementation notes for how this interacts with the git tagging
  convention)

## Context

ADR-0001 established the service boundaries (Laravel as sole business-data
writer; Python as a stateless, async-only inference boundary) and requires
asynchronous, message-mediated communication between them
(`ImageAnalysisRequested` / `ImageAnalysisCompleted` / `ImageAnalysisFailed`),
while deliberately deferring concrete broker choice and queue topology to
this ADR. ADR-0002 selected the primary application technology baseline and
deliberately deferred five things to this ADR: local container strategy,
the concrete local object-storage provider, queue transport and
background-job ownership, local email tooling, and the local observability
baseline. `PROJECT_ROADMAP.md`'s Phase 1 objective is "create a
reproducible local environment for the web application, API, database,
queues, object storage and image-analysis service."

This ADR decides all five deferred concerns together, since they form one
coherent local-platform decision rather than five independent ones.

## Decision

1. **Docker Compose** as the local container strategy. It's the established
   pattern this repository's own `docs/IMPLEMENTATION_GUIDE.md` "Proposed
   repository shape" already assumes (`compose.yaml`), and fits a small
   number of cooperating services (web, api, image-ai, postgres, LocalStack,
   Collector, Grafana `otel-lgtm`, mail) without needing a heavier
   orchestration layer this project has no current need for.
2. **Mailpit** for local email testing (captures outgoing mail — invitation
   emails, notifications — without sending it anywhere real).
3. **LocalStack** as the concrete local provider for both:
   - S3-compatible object storage, and
   - SQS-compatible asynchronous message transport.

   ADR-0001 already requires asynchronous, message-mediated communication
   between Laravel and the image-analysis service. Developing against SQS
   semantics from Phase 1 preserves the actual delivery model this
   architecture depends on — visibility timeouts, retries, redelivery,
   dead-letter handling, and idempotent consumers — rather than developing
   against a simplified local substitute and discovering delivery-semantics
   gaps only once the real analysis pipeline exists. LocalStack gives the
   local platform one AWS-compatible boundary for the two infrastructure
   capabilities most likely to be AWS-hosted in production, while the
   application-owned storage and queue abstractions (ADR-0001, ADR-0002)
   keep the concrete production provider replaceable regardless of what
   LocalStack emulates locally.
4. **Redis remains scoped to caching and ephemeral application state only**,
   as established by ADR-0002. Redis is not used as the queue transport.
5. **A Phase 1 OpenTelemetry observability baseline**, consisting of:
   - OpenTelemetry instrumentation in application services where supported.
   - An OpenTelemetry Collector as the application-facing telemetry
     boundary — services emit telemetry to the Collector rather than
     directly to a backend, keeping the backend replaceable.
   - Trace-context propagation across both HTTP requests and asynchronous
     messages (including the `ImageAnalysisRequested` /
     `ImageAnalysisCompleted` / `ImageAnalysisFailed` contract), as an
     architectural requirement from the start rather than a later retrofit.
   - Structured JSON logs carrying correlation, trace and request
     identifiers.
   - Baseline traces and metrics covering application requests, queue
     operations and background jobs, using automatic instrumentation where
     supported and focused manual instrumentation where required.
   - A local Grafana `otel-lgtm` backend for viewing traces, logs and
     metrics locally.

   Custom domain spans and detailed business-level metrics are explicitly
   **not** part of this baseline — those are added incrementally as real
   upload, queue and inference workflows are implemented, not fabricated
   ahead of the code they would describe. The objective here is
   establishing the pipeline, service identities and propagation
   conventions early, so later flows inherit observability rather than
   requiring it to be retrofitted.

## Alternatives considered

**Object storage + queue transport:**
- *MinIO (S3) + Redis queue driver* — considered and rejected. Reusing
  Redis for queueing would have added no new local service, but it would
  mean developing against Redis's list-based queue semantics, which do not
  carry the visibility-timeout, redelivery and dead-letter model that
  ADR-0001's message contract is actually designed around. That gap would
  most likely surface late — once the real analysis pipeline exists and
  its retry/idempotency behaviour is exercised for the first time — rather
  than being caught from Phase 1 onward.

**Observability depth:**
- *Structured logs only in Phase 1, OpenTelemetry deferred* — considered
  and rejected. ADR-0001's message contract (`ImageAnalysisRequested` /
  `Completed` / `Failed`) is defined in this phase; deciding how trace
  context rides alongside that contract now avoids retrofitting
  propagation into a message envelope and HTTP layer that would otherwise
  already be frozen in later phases.

## Consequences

### Positive

- Developing against real SQS delivery semantics from Phase 1 surfaces
  delivery-model issues (retries, redelivery, dead-letter handling,
  idempotent consumption) early, rather than after the queue-based
  analysis pipeline is built in Phase 9.
- Establishing OpenTelemetry propagation conventions alongside the message
  contract avoids retrofitting trace context into an already-frozen
  contract later.
- LocalStack gives one AWS-compatible local boundary for both storage and
  queue capabilities, while ADR-0001's and ADR-0002's application-owned
  abstractions keep the concrete production provider replaceable
  regardless of what is emulated locally.

### Negative

- LocalStack is heavier and slower to start locally than a minimal
  MinIO-plus-Redis-queue setup would have been — a real added local
  resource and startup cost, notable given later phases (Phase 9 local
  face-recognition inference) will already be resource-intensive on the
  developer's machine.
- Standing up an OpenTelemetry Collector and Grafana `otel-lgtm` stack in
  Phase 1, before any real business flow exists, adds infrastructure and
  maintenance surface (containers to keep running, dashboards to maintain)
  ahead of the code that will make that data meaningful.

### Risks

- If LocalStack's SQS or S3 emulation diverges from real AWS behaviour in
  some edge case (redelivery timing quirks, presigned-URL differences),
  that gap might still only surface once a real production provider is
  chosen, despite the intent to catch it early.
- An OpenTelemetry Collector/Grafana stack is itself infrastructure that
  can fail or silently drop data; if its own health isn't checked, a
  broken telemetry pipeline could mask real application issues instead of
  surfacing them.

## Implementation notes

- Per `docs/IMPLEMENTATION_GUIDE.md`'s git tagging convention, `FPA-P01-S01`'s
  entire scope is accepting this ADR, so the ADR-acceptance commit is that
  stage's completion commit and receives the `phase-1-s01` tag directly.
- `FPA-P01-S02` (application scaffolding), `FPA-P01-S03` (local
  infrastructure services) and `FPA-P01-S04` (baseline observability)
  implement this decision; none of them re-open it.

## Review triggers

- When the future Phase 15 Production Deployment and Family Pilot ADR
  settles production hosting, revisit whether LocalStack's emulated S3/SQS
  behaviour matched the chosen production provider closely enough, or
  whether gaps found there need addressing.
- If the OpenTelemetry Collector/Grafana `otel-lgtm` stack proves too heavy
  for routine local development (startup time, resource usage), revisit
  trimming it back until a phase with real cross-service flows makes the
  full stack's value clearer.
