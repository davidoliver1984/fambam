# ADR-0002: Primary Technology Stack

- Status: Accepted
- Date: 2026-08-01
- Decision owners: David
- Related stages: FPA-P00-S03 (a required Phase 0 decision; accepting this
  ADR does not itself complete or tag a roadmap stage — see Implementation
  notes)

## Context

`PRODUCT_VISION.md`'s "Suggested technical architecture" sketches
React-or-Next.js → Laravel → PostgreSQL(+pgvector)/Redis/S3 → Python, but
leaves the concrete language/framework/tooling choices open. ADR-0001
fixed the service boundaries (Laravel is the sole business-data writer;
Python is a stateless, async-only inference boundary; no HTTP callback, no
shared database). `PROJECT_ROADMAP.md` requires this decision before
Phase 0 can close, and `docs/IMPLEMENTATION_GUIDE.md`'s "Proposed
repository shape" already assumes `apps/web`, `apps/api`, `apps/image-ai`.

Every choice in this ADR is justified solely by fambam's own product
requirements and engineering goals — a private, invite-only, authenticated
family archive that must remain simple enough for non-technical and
elderly relatives to trust, built on the service boundaries ADR-0001
established.

## Decision

1. **Frontend: React + TypeScript + Vite** (not Next.js). Fambam is
   primarily an authenticated application; Laravel is the sole backend and
   business authority; there is currently no material SEO, SSR, ISR or
   React Server Components requirement. Next.js would add capabilities and
   a second server-side application layer that this product does not
   currently need.
2. **Laravel** as the sole business API and authority (the boundary itself
   was already settled by ADR-0001; this names the framework and language).
3. **Python + FastAPI** for the image-analysis service.
4. **PostgreSQL** as the primary datastore. Vector storage is explicitly
   **not** selected by this ADR — see "Explicitly out of scope for this
   ADR" below.
5. **Redis**, scoped explicitly to caching and ephemeral application
   state only. Redis is **not** selected as the queue transport by this
   ADR; that decision is deferred to the Phase 1 Local Development
   Platform ADR.
6. **An application-owned storage abstraction targeting an S3-compatible
   interface**, allowing the concrete storage provider to vary
   independently of application code. The concrete local provider remains
   the Phase 1 Local Development Platform ADR's decision.
7. **Pint and PHPStan/Larastan** for PHP quality, giving early
   static-analysis coverage for the Laravel codebase and catching type and
   contract errors before they reach runtime or tests.
8. **ESLint, strict TypeScript, automated formatting, and Vitest** for the
   frontend.
9. **Ruff, mypy, pytest** for Python.

**Explicitly out of scope for this ADR** (deferred elsewhere, not decided
here):

- Vector storage and indexing — deferred to the later roadmap phase that
  actually introduces a concrete vector-search requirement (face or
  semantic embeddings), where the concrete indexing and similarity
  strategy can be decided together with a real workload in view.
- Local container strategy, the concrete local object-storage provider,
  queue transport and background-job ownership, local email tooling, and
  the local observability baseline — deferred collectively to the Phase 1
  Local Development Platform ADR, which decides these together as one
  coherent local-platform decision.
- Specific major version numbers for Laravel, PostgreSQL, Python, Node —
  see "Version pinning" below.

## Version pinning

This ADR does not hard-code specific major framework/database versions.
Supported stable versions will be selected and explicitly pinned (in
`composer.json`, `package.json`, container manifests, CI configuration,
etc.) during Phase 1 implementation, not fixed permanently in this
long-lived architectural record. Re-pinning to a newer stable major
version later is an ordinary implementation decision, not an ADR-worthy
one, unless it changes a boundary this ADR or ADR-0001 established.

## Alternatives considered

**Frontend framework:**
- *Next.js* — matches `PRODUCT_VISION.md`'s original suggestion; would
  give a path to SSR/ISR and file-based routing if a public-facing
  surface is ever needed.
- *React single-page application built with Vite (selected)* — avoids
  introducing server-rendering and hydration complexity that the current
  product does not require, and fits a purely authenticated application
  with no SEO or public-page requirement. Chosen deliberately given
  fambam's own scope, not as a default.

## Consequences

### Positive

- The selected technologies favour mature ecosystems, strong tooling and
  clear service boundaries, helping keep the system maintainable over the
  long term.
- Keeping Redis's role scoped, vector storage unselected, and local-
  platform decisions entirely out of this ADR preserves the Phase 1 Local
  Development Platform ADR as a genuine decision rather than a
  rubber-stamp.
- The frontend architecture remains intentionally simple, matching the
  application's current requirements while leaving future migration to
  Next.js possible should those requirements materially change.

### Negative

- If fambam's scope later grows a public or indexable surface, the
  React+Vite choice will need revisiting, and that migration is more work
  than if Next.js had been chosen up front.
- Leaving vector storage and local-development-platform concerns fully
  open means Phase 1 scaffolding cannot start on those fronts until the
  Phase 1 Local Development Platform ADR is accepted.

### Risks

- If Redis's cache-only scope doesn't survive into Phase 1 stage text and
  the Phase 1 Local Development Platform ADR itself, a future implementer
  could default to Redis queues and silently decide that ADR's
  queue-transport question by omission.
- Deferring vector storage means the face/semantic-search phases must
  budget time to evaluate it against alternatives from a colder start,
  rather than inheriting a decision made here.

## Implementation notes

- This decision establishes the primary application technology baseline
  required before the Phase 1 Local Development Platform ADR defines the
  local development infrastructure.
- Accepting this ADR does not complete `FPA-P00-S03` and creates no git
  tag (per `docs/IMPLEMENTATION_GUIDE.md`'s tagging convention).
- Framework/database version pins are an implementation-time decision, not
  recorded in this ADR (see "Version pinning" above).

## Review triggers

- **If a substantial public, indexable, or server-rendered experience is
  introduced later**, revisit the React+Vite decision against Next.js.
- **When a roadmap phase first requires vector search** (face or semantic
  embeddings), evaluate vector-storage options at that time with a real
  workload in view, rather than assuming one was pre-selected here.
