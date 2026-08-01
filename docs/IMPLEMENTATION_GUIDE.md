# Family Photo Archive — Implementation Guide

> **Purpose:** This is the durable build guide for the Family Photo Archive.
>
> `PROJECT_ROADMAP.md` defines what is built and in what order.
> This document defines how each stage should be implemented, verified and committed.
> Architectural justification belongs in `docs/adr/`.
> Current execution state belongs in `tasks.json`.

## Document ownership

- `PROJECT_ROADMAP.md` — product phases, outcomes and release boundaries.
- `docs/IMPLEMENTATION_GUIDE.md` — implementation and verification instructions.
- `tasks.json` — canonical current stage and task completion state.
- `docs/adr/` — architectural decisions and trade-offs.
- `docs/journal/` — session records and lessons learned.
- Application READMEs — commands and operation specific to that application.
- `CONTRIBUTING.md` — repository-wide engineering and commit conventions.
- `CLAUDE.md` — AI collaboration guidance without duplicating authoritative documents.

## Working conventions

Every implementation stage must contain:

1. Objective
2. Engineering rationale
3. Prerequisites
4. Expected changes
5. Commands
6. Verification
7. Risks and edge cases
8. Documentation updates
9. Commit boundary

General rules:

- Run commands from the repository root unless stated otherwise.
- Keep commits aligned with one stage or one explicit documentation decision.
- Do not combine unrelated cleanup with feature work.
- Do not mark a stage complete until verification passes.
- Update `tasks.json` in the same commit that completes a stage.
- Write an ADR before implementing a material architectural choice.
- Treat AI-generated code and documentation as drafts requiring repository-aware review.
- Prefer idempotent jobs and reproducible derivatives.
- Preserve originals and human-confirmed archive knowledge.
- Fail closed on family-space access.

## Git tagging convention

Git tags map directly onto this project's roadmap identifiers, matching the
convention already established and documented in the sibling `dolved`
project. `phase-n` is the roadmap phase number (`PROJECT_ROADMAP.md`'s
`P##`) and `phase-n-sNN` is the roadmap stage number within that phase
(`FPA-P##-S##`). **ADR numbers never determine git phase numbers.**

Rules:

- Only a **completed** planned roadmap stage receives a `phase-n-sNN` tag.
- Intermediate commits made while a stage is still in progress receive no
  stage tag.
- The stage tag is applied to the single commit that completes the planned
  roadmap stage.
- If a stage's entire scope is accepting an ADR (for example
  `FPA-P00-S02`), the ADR-acceptance commit is that stage's completion
  commit and receives the tag directly.
- Otherwise, an ADR-acceptance commit remains untagged until the
  authorised implementation stage that follows it is itself completed and
  accepted — accepting the ADR does not, by itself, complete an
  implementation stage.
- `phase-n` (the whole-phase tag, no stage suffix) is created only once,
  on the commit that completes that phase's final stage and passes the
  phase's full acceptance gate.

`PROJECT_ROADMAP.md` phase identifiers (`P##`) and this guide's stage
identifiers (`FPA-P##-S##`) are the direct source of the tag numbers — this
is the same numbering, not a parallel scheme. The explicit mapping between
roadmap stages and the git tags actually produced is recorded in
`tasks.json` under `delivery_log`.

Example: `FPA-P00-S01` was completed and tagged `phase-0-s01`.
`FPA-P00-S02`'s entire scope was accepting ADR-0001, so the ADR-acceptance
commit is tagged `phase-0-s02` directly. `FPA-P00-S03` is still in
progress; its intermediate commits are untagged, and `phase-0-s03` will be
created only on the commit that finishes it. `phase-0` will be created
only once `FPA-P00-S03` completes and Phase 0's acceptance gate passes.

## Proposed repository shape

```text
.
├── apps/
│   ├── web/
│   ├── api/
│   └── image-ai/
├── contracts/
│   ├── events/
│   └── http/
├── docs/
│   ├── adr/
│   ├── journal/
│   └── IMPLEMENTATION_GUIDE.md
├── infrastructure/
│   ├── docker/
│   └── terraform/
├── scripts/
├── tests/
│   └── end-to-end/
├── compose.yaml
├── Makefile
├── README.md
├── CONTRIBUTING.md
├── CLAUDE.md
├── PROJECT_ROADMAP.md
└── tasks.json
```

The exact topology must be confirmed by ADR-0001 before scaffolding.

## Standard verification categories

Each stage should select the relevant checks:

```bash
make format-check
make lint
make typecheck
make test
make test-api
make test-web
make test-ai
make test-e2e
make security-check
make docs-check
make contracts-check
```

These commands are targets to establish during foundation work. Until then, use the native service commands documented by each application.

---

# Phase 0 — Product and Repository Foundation

## FPA-P00-S01 — Accept project definition and boundaries

### Objective

Establish the product purpose, V1 boundary, non-goals and family-centred design principles.

### Engineering rationale

The data model and security model will drift if the project is treated as a generic social or gallery application. Product boundaries must be explicit before repository and schema decisions.

### Prerequisites

- Project definition reviewed.
- Initial V1 scope agreed.

### Expected changes

- Root README includes purpose and product principles.
- `PROJECT_ROADMAP.md` added.
- `docs/IMPLEMENTATION_GUIDE.md` added.
- `tasks.json` added.
- Non-goals documented.

### Verification

- Documents agree on the V1 release boundary.
- No public-social-network features appear in the committed V1 scope.
- `tasks.json` parses as valid JSON.

### Risks and edge cases

- Over-scoping V1.
- Conflating family relationships with permissions.
- Treating AI as the product rather than a supporting feature.

### Documentation updates

- Set project status to `FPA-P00-S02`.

### Commit boundary

```text
Define family photo archive product and delivery plan
```

---

## FPA-P00-S02 — Record repository and service topology ADR

### Objective

Decide whether the project uses a monorepo and define service ownership.

### Engineering rationale

Laravel should remain the business authority. Image inference should be isolated so model dependencies and compute requirements do not leak into the application domain.

### Prerequisites

- FPA-P00-S01 complete.

### Expected changes

- ADR-0001 accepted.
- Repository shape confirmed.
- Service responsibilities documented.
- Cross-service communication direction documented.

### Verification

- Every domain responsibility has one authoritative owner.
- Python is restricted to image-analysis responsibilities.
- Frontend does not bypass the Laravel API for business actions.

### Risks and edge cases

- Premature microservices.
- Duplicated domain rules.
- Tight coupling through shared databases.

### Documentation updates

- Update roadmap only if topology materially changes.
- Advance `tasks.json`.

### Commit boundary

```text
Accept ADR-0001: Repository and service topology
```

---

## FPA-P00-S03 — Scaffold repository documentation and command interface

### Objective

Create the initial repository structure and developer command surface.

### Expected changes

- Directory scaffold.
- `README.md`.
- `CONTRIBUTING.md`.
- `CLAUDE.md`.
- ADR template.
- Journal template.
- Makefile placeholders.
- Initial CI workflow.

### Verification

- Documentation links resolve.
- ADR and journal templates are usable.
- CI validates JSON and Markdown formatting.
- `make help` lists supported commands.

### Commit boundary

```text
Scaffold repository foundation
```

---

# Phase 1 — Local Development Platform

## FPA-P01-S01 — Scaffold web, API and image-analysis applications

### Objective

Create independently testable application skeletons.

### Expected changes

- Web application.
- Laravel API.
- Python image-analysis service.
- Health endpoints.
- Service-level tests.

### Verification

- Each application starts independently.
- Health tests pass.
- Formatting, linting and type checks pass.

### Commit boundary

```text
Scaffold application services
```

## FPA-P01-S02 — Add local infrastructure services

### Objective

Provide PostgreSQL, Redis, S3-compatible storage and local mail.

### Expected changes

- `compose.yaml`.
- Persistent local volumes.
- Environment templates.
- Health checks.
- Network definitions.
- Make targets.

### Verification

- `make up` starts the platform.
- Applications can reach required dependencies.
- Object upload and retrieval smoke test passes.
- Queue round-trip smoke test passes.

### Commit boundary

```text
Add reproducible local infrastructure
```

## FPA-P01-S03 — Add baseline observability

### Objective

Make requests, jobs and image-analysis failures diagnosable from the start.

### Expected changes

- Structured logs.
- Correlation/request identifiers.
- Queue-job identifiers.
- Basic metrics or OpenTelemetry integration.
- Local inspection interface if accepted by ADR.

### Verification

- One upload-related synthetic request can be traced across participating services.
- Failed jobs include actionable context without exposing sensitive media.

### Commit boundary

```text
Add local observability baseline
```

---

# Phase 2 — Identity, Authentication and Invitations

## FPA-P02-S01 — Accept authentication and invitation ADRs

Define session architecture, invitation lifecycle, recovery and MFA direction before implementation.

## FPA-P02-S02 — Implement account authentication

Implement registration acceptance, login, logout, current user, email verification and password reset.

## FPA-P02-S03 — Implement invitation lifecycle

Implement issue, resend, revoke, expire and accept flows with audit records.

## FPA-P02-S04 — Harden account security

Add rate limiting, session invalidation, password policy, security events and MFA-ready boundaries.

### Phase verification

- Feature tests cover valid and invalid invitation paths.
- Authentication cookies and CORS settings are production-safe.
- Revoked users cannot retain active sessions.
- Security-sensitive actions are audited.

---

# Phase 3 — Family Spaces and Tenancy

## FPA-P03-S01 — Accept tenancy and permission ADRs

Resolve tenant boundary, roles, active context, propagation and database RLS.

## FPA-P03-S02 — Implement family spaces and memberships

Create family spaces, ownership rules and membership lifecycle.

## FPA-P03-S03 — Implement route context, policies and explicit scoping

Every tenant route resolves a public family identifier and fails closed.

## FPA-P03-S04 — Add PostgreSQL row-level security

Apply non-bypass runtime roles, FORCE RLS where appropriate and integration tests.

## FPA-P03-S05 — Add tenancy audit and deletion foundations

Audit membership changes and define asynchronous deletion states.

### Phase verification

- Cross-tenant feature and database tests pass.
- A family cannot be left without an owner.
- Background jobs carry explicit tenant context.
- Storage keys include safe family partitioning.

---

# Phase 4 — People, Accounts and Relationships

## FPA-P04-S01 — Accept person and relationship ADRs

Resolve account/person separation, relationship representation and uncertain dates.

## FPA-P04-S02 — Implement person records

Support living, deceased and account-less people with controlled editing.

## FPA-P04-S03 — Link accounts to people

Prevent accidental or duplicate links and audit changes.

## FPA-P04-S04 — Implement relationships and family circles

Relationships are descriptive; circles are organisational; neither grants permission.

## FPA-P04-S05 — Implement person merge and correction workflow

Merges must be reversible or safely recoverable and preserve references.

### Phase verification

- Deceased people receive full archive pages.
- Relative labels can be presented from the current person's perspective.
- Cyclic or contradictory relationships are detected where practical.
- Person deletion does not erase historical provenance silently.

---

# Phase 5 — Media Storage and Upload Pipeline

## FPA-P05-S01 — Accept upload and asset-model ADRs

Resolve direct upload, object keys, supported formats, canonical assets and access strategy.

## FPA-P05-S02 — Implement upload initiation and completion

Use idempotency keys and explicit upload states.

## FPA-P05-S03 — Validate and preserve originals

Verify type, size and checksum; preserve original bytes; quarantine invalid files.

## FPA-P05-S04 — Extract metadata and generate canonical assets

Apply orientation correctly and preserve original EXIF separately.

## FPA-P05-S05 — Generate presentation variants

Create thumbnails and responsive variants through queued jobs.

## FPA-P05-S06 — Secure media delivery

Use authorised application checks and short-lived signed URLs or an equivalent accepted approach.

## FPA-P05-S07 — Add upload recovery and bulk upload

Support retries, partial failure reporting and duplicate-safe client behaviour.

### Phase verification

- Uploading the same completion event twice is safe.
- HEIC and required mobile formats are tested.
- Original checksums remain stable.
- Variants can be deleted and regenerated.
- Unauthorised media access fails.

---

# Phase 6 — Photo Domain, Provenance and Organisation

## FPA-P06-S01 — Accept provenance and organisation ADRs

Resolve photo ownership terms, albums, dynamic collections and historical confidence.

## FPA-P06-S02 — Implement photo and provenance records

Record uploader, photographer, scanner, source collection and original owner independently.

## FPA-P06-S03 — Implement family metadata and approximate dates

Support exact, month, year, decade and approximate values without inventing precision.

## FPA-P06-S04 — Implement albums and dynamic views

Albums are explicit collections; generated views query shared metadata.

## FPA-P06-S05 — Implement stories, comments and reactions

Add edit history and moderation boundaries appropriate for a private family environment.

## FPA-P06-S06 — Implement photo deletion and restoration

Use soft deletion, derivative cleanup jobs and clear permanent-deletion rules.

### Phase verification

- One photo can belong to several albums.
- Provenance remains intact after edits.
- Date sorting handles uncertain dates consistently.
- Photo restoration re-establishes valid asset references.

---

# Phase 7 — Events and Collaborative Sharing

## FPA-P07-S01 — Accept event and guest-access ADRs

## FPA-P07-S02 — Implement events and event albums

## FPA-P07-S03 — Implement event contributions

## FPA-P07-S04 — Implement restricted guest upload links

## FPA-P07-S05 — Implement event notifications and exports

### Phase verification

- Guest access cannot enumerate family members or unrelated photos.
- Expired event links stop working.
- Contributions retain uploader and source provenance.
- Event exports include originals and a metadata manifest.

---

# Phase 8 — Duplicate Detection

## FPA-P08-S01 — Implement exact duplicate detection

Use cryptographic hashes and family-aware duplicate policies.

## FPA-P08-S02 — Implement perceptual similarity analysis

Generate versioned perceptual hashes and candidate scores.

## FPA-P08-S03 — Implement duplicate review and consolidation

Never delete automatically. Preserve album, event, story and provenance associations.

### Phase verification

- Exact duplicate detection is deterministic.
- Similar candidates can be dismissed.
- Consolidation has audit records and safe conflict handling.

---

# Phase 9 — Local Face Analysis Foundation

## FPA-P09-S01 — Build representative local benchmark

Create a private, non-repository benchmark manifest covering recent photos, age variation, groups, scans, profiles, siblings and poor-quality images.

## FPA-P09-S02 — Accept provider, model, licensing and data-model ADRs

Do not implement a pretrained model until code and model-weight licensing are recorded separately.

## FPA-P09-S03 — Implement face-analysis provider contract

The application contract should express detection and embedding capabilities without leaking one provider's schema.

## FPA-P09-S04 — Implement local face-analysis provider

Initial candidate: InsightFace-compatible provider through ONNX Runtime.

## FPA-P09-S05 — Implement queued analysis pipeline

Store provider, model, version, configuration hash, source checksum and processing status.

## FPA-P09-S06 — Add benchmark and operational metrics

Measure detection coverage, false detections, execution time, memory and failure rates.

### Phase verification

- Local Apple Silicon development works using the documented runtime.
- Reprocessing is idempotent.
- Model changes create new analysis records rather than mutating history invisibly.
- Raw images are not committed to the repository.
- Benchmark findings are documented without exposing sensitive family media.

---

# Phase 10 — Face Clustering and Human Review

## FPA-P10-S01 — Accept threshold, clustering and consent ADRs

## FPA-P10-S02 — Implement embedding storage and similarity queries

## FPA-P10-S03 — Implement conservative face clustering

## FPA-P10-S04 — Implement identity suggestion and confirmation

## FPA-P10-S05 — Implement merge, split, reject and unknown workflows

## FPA-P10-S06 — Implement recognition consent and exclusion

## FPA-P10-S07 — Calibrate against the family benchmark

### Phase verification

- Confirmed assignments are separate from machine results.
- Re-clustering does not require image inference.
- False merges can be corrected safely.
- Excluded people do not receive new embeddings.
- No cross-family vector query is possible.

---

# Phase 11 — Search and Discovery

## FPA-P11-S01 — Accept search-indexing ADR

## FPA-P11-S02 — Implement metadata search

## FPA-P11-S03 — Implement person and relationship-aware search

## FPA-P11-S04 — Implement combined filters and saved views

## FPA-P11-S05 — Add performance and permission tests

### Phase verification

- Search queries are tenant-scoped.
- Approximate dates produce documented results.
- Search remains usable with the projected pilot library size.

---

# Phase 12 — Memories and Homepage

## FPA-P12-S01 — Accept memory-generation ADR

## FPA-P12-S02 — Implement recent family activity

## FPA-P12-S03 — Implement date-based memories

## FPA-P12-S04 — Implement person-centred memories

## FPA-P12-S05 — Implement user controls and exclusions

### Phase verification

- Memory rules are explainable.
- Hidden and excluded photos are respected.
- No engagement-ranking model is introduced.

---

# Phase 13 — Export, Backup and Recovery

## FPA-P13-S01 — Accept export and backup ADRs

## FPA-P13-S02 — Implement personal and family exports

## FPA-P13-S03 — Implement metadata manifest

## FPA-P13-S04 — Implement backup monitoring

## FPA-P13-S05 — Perform tested restore exercise

## FPA-P13-S06 — Implement deletion request lifecycle

### Phase verification

- Export opens without the application.
- Originals retain checksums and useful filenames.
- Restore succeeds in an isolated environment.
- Deletion behaviour is documented across backups.

---

# Phase 14 — Security, Privacy and Accessibility Hardening

## FPA-P14-S01 — Produce threat model and privacy data map

## FPA-P14-S02 — Review biometric and child-data controls

## FPA-P14-S03 — Perform application security hardening

## FPA-P14-S04 — Perform accessibility audit

## FPA-P14-S05 — Conduct non-technical family usability test

## FPA-P14-S06 — Resolve production blockers

### Phase verification

- Critical and high-risk issues are closed or explicitly accepted.
- Core workflows meet the chosen accessibility standard.
- Consent and exclusion controls are understandable to ordinary users.

---

# Phase 15 — Production Deployment and Pilot

## FPA-P15-S01 — Accept production architecture ADRs

## FPA-P15-S02 — Provision production infrastructure

## FPA-P15-S03 — Configure deployment, monitoring and rollback

## FPA-P15-S04 — Import initial curated archive

## FPA-P15-S05 — Invite pilot family members

## FPA-P15-S06 — Review pilot feedback and operating costs

## FPA-P15-S07 — Declare V1 or create remediation stages

### Phase verification

- Deployment is reproducible.
- Alerts and budget controls work.
- Pilot users complete core journeys.
- Support and recovery procedures are proven.

---

# Phase 16 — Semantic Image Search

This phase starts only after V1 unless explicitly promoted through roadmap review.

## FPA-P16-S01 — Build semantic-search benchmark

## FPA-P16-S02 — Accept model, licence and vector-store ADRs

## FPA-P16-S03 — Implement semantic embedding provider

## FPA-P16-S04 — Implement hybrid search

## FPA-P16-S05 — Add re-indexing and evaluation tooling

---

# Phase 17 — Advanced Archive Features

Each advanced feature must receive its own scoped stages and ADR review. Do not create a single unbounded “advanced features” implementation branch.

## Session journal template

```markdown
# Session YYYY-MM-DD — FPA-P##-S##

## Session objective

## Work completed

## How the system works

## What I learned

### Laravel and software engineering

### Python and image analysis

### Security, privacy and operations

## Decisions

## Verification

## Follow-up work

## AI-assisted development record
```

## ADR template

```markdown
# ADR-XXXX: Decision title

- Status: Proposed
- Date: YYYY-MM-DD
- Decision owners:
- Related stages:

## Context

## Decision

## Alternatives considered

## Consequences

### Positive

### Negative

### Risks

## Implementation notes

## Review triggers
```
