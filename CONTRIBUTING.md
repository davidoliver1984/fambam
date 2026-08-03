# Contributing

## Purpose and audience

This guide is for human contributors and AI-assisted engineering sessions working
within the Family Photo Archive's established architecture, engineering workflow
and documentation system. It explains how to make a bounded, reviewable change
without silently changing the platform's design or duplicating its existing
documentation.

The documentation authorities are:

| Document | Responsibility |
|---|---|
| `PRODUCT_VISION.md` | What the product is, its philosophy and non-goals |
| `PROJECT_ROADMAP.md` | What will be built and in what order |
| `docs/IMPLEMENTATION_GUIDE.md` | How stages are implemented, verified and committed |
| `docs/ENGINEERING_METHODOLOGY.md` | The engineering process, roles and lifecycle followed across all projects |
| `docs/FRONTEND_ENGINEERING_STANDARDS.md` | Durable frontend engineering conventions for `apps/web` — structure, data flow, testing, accessibility |
| `tasks.json` | Which planned stage is current, and the git-tag delivery log |
| `docs/adr/` | Why durable architecture decisions were made |
| `docs/journal/` | What happened during individual engineering sessions |
| `CLAUDE.md` | Claude's specific role and boundaries on this project |
| `README.md` | Repository introduction and normal operating instructions |

Do not reproduce those documents here. If an authority is missing, exists at a
different path, or contradicts another authority, stop and reconcile the conflict
with the human developer before treating either version as canonical.

## Engineering methodology

This repository follows the engineering process defined in:

`docs/ENGINEERING_METHODOLOGY.md`

All contributors (human and AI) should follow this methodology before making
architectural or implementation changes.

## Engineering Philosophy

The objective of this repository is not merely to build a working family photo
archive.

It is to build a production-quality system whose architecture, documentation,
engineering decisions and commit history demonstrate senior software engineering
practice, while genuinely serving a real family's need for a private, trusted
place to share and preserve their photographs.

Every change should optimise for:

- clarity over cleverness
- explicit architecture
- maintainability
- repeatability
- observability
- security and privacy
- teaching value

## AI Behaviour

AI tools should:

- inspect before modifying
- explain before implementing
- preserve existing conventions
- stop when architecture decisions are required
- never fabricate verification
- never silently broaden scope
- prefer improving existing code over introducing parallel patterns

Claude's specific role on this project (architectural review rather than
implementation) is defined in `CLAUDE.md` and does not relax any rule in this
section.

## Repository structure

| Path | Responsibility |
|---|---|
| `apps/web` | Frontend browser interface and presentation logic |
| `apps/api` | Laravel system of record for the domain, authentication, authorisation, family-space tenancy and relational data |
| `apps/image-ai` | Python AI/ML inference workloads behind the Laravel boundary. Stateless: no database access of any kind. Communicates only via the asynchronous, versioned `ImageAnalysisRequested` / `ImageAnalysisCompleted` / `ImageAnalysisFailed` contract (ADR-0001) |
| `contracts` | Explicit, language-neutral HTTP and event contracts shared between services |
| `docs` | ADRs, engineering journals and supporting technical documentation |
| `infrastructure` | Local container and future production infrastructure configuration |
| `scripts` | Repeatable repository automation that does not belong in an application |
| `tests` | Cross-service and end-to-end tests; service-owned tests remain with their applications |

Preserve these responsibilities unless an agreed ADR changes them.

`apps/image-ai` (rather than a shorter `apps/ai`) is the accepted path per
ADR-0001's Decision section — not open.

## Local development principles

- Prefer the repository's container platform and root Make commands over
  host-installed Node.js, PHP or Python runtimes, per ADR-0003 (local
  development platform).
- Do not introduce hidden manual setup. Encode repeatable work in the Makefile,
  container configuration or a reviewed script.
- Keep setup and provisioning commands idempotent where practical.
- Add every new required environment variable to the appropriate `.env.example` in
  the same change, with a safe example value or an explanation.
- Never commit real secrets, tokens, credentials, personal data or populated local
  environment files — and never commit real family photographs or exported archive
  data as local fixtures.
- Treat any destructive reset command as requiring deliberate confirmation.

Specific Compose service names and setup commands are established by ADR-0003 and
`docs/IMPLEMENTATION_GUIDE.md`; do not invent a substitute here.

## Engineering workflow

This follows the working cycle defined in `PRODUCT_VISION.md`. In summary, a
planned session:

1. Reviews the roadmap and current stage in `tasks.json`.
2. Discusses the concept and architecture with an architecture reviewer.
3. Confirms or updates the relevant ADR(s) before implementation begins.
4. Implements one bounded stage. AI assistants may be used where appropriate, but
   the human developer remains responsible for the resulting code.
5. Runs the required stage-specific and repository-wide verification.
6. Resolves review findings.
7. Updates `docs/IMPLEMENTATION_GUIDE.md` with the actual commands, changes and
   verification evidence.
8. Writes a session journal entry in `docs/journal/`.
9. Commits at the agreed stage boundary and tags it (see "Git and commit
   conventions" below).
10. Updates `tasks.json`, including the `delivery_log`, in the same commit.

Minor maintenance changes and documentation corrections outside a planned stage do
not require a new session record, but must still remain focused, reviewable and
subject to the relevant verification and commit standards.

AI implementation assistants must not make significant architectural decisions
independently. Where an architectural decision is required, they should identify
the decision, present viable options and trade-offs, recommend the option that
best fits the existing architecture, and stop and obtain agreement before an ADR
is finalised or implementation begins.

## Commit message conventions

Use clear, imperative commit messages.

Examples:

- Accept ADR-0007: Multi-tenant storage strategy
- Implement workspace membership policies
- Add OpenTelemetry collector configuration
- Refactor embedding provider abstraction
- Update implementation guide for Phase 12

Do not use Conventional Commit prefixes (`feat:`, `fix:`, `docs:`, `chore:` etc.)
unless the repository explicitly adopts that convention. Fambam has not.

### Architecture & Documentation

Architecture-only commits should document accepted decisions and should **not**
introduce application code.

Preferred commit subjects:

```text
Document ...
Define ...
Record ...
Clarify ...
Accept ADR-XXXX ...
```

Where appropriate, include:

```text
No application code changed.
```

### Implementation

Implementation commits should implement previously accepted architectural
decisions and remain within the scope of the accepted ADR. Architectural redesign
belongs in a separate architecture session and ADR where required.

Preferred commit subjects:

```text
Implement ...
Add ...
Refactor ...
Remove ...
Replace ...
Fix ...
```

Where an ADR exists, reference it in the commit body, for example
`Implements ADR-0001.`

### Engineering principle

Architecture sessions produce **decisions**. Implementation sessions produce
**software**. Keep those responsibilities separate wherever practical.

## Change discipline

- Keep changes inside the agreed stage scope and do not silently begin the next
  stage.
- Inspect existing code, tests, contracts, ADRs and documentation before
  introducing a new pattern.
- Prefer small changes that can be reviewed and verified independently.
- Avoid unrelated cleanup, formatting churn or dependency updates in a feature
  commit.
- Preserve stable service, domain, security and family-space ownership
  boundaries.
- Do not bypass repository commands merely to make a local check pass.
- Stop and raise contradictions between code, contracts, ADRs, roadmap and
  implementation guide.
- Leave no unexplained temporary files, commented-out workarounds or disabled
  tests.

## Laravel application style

- Keep controllers thin. A controller should coordinate the HTTP request, invoke
  an application operation and return a response; it should not contain domain
  workflows, storage logic or complex queries.
- Use Form Requests for input validation and request-level authorisation. Pass
  validated data into the application layer rather than passing an entire
  request.
- Use policies and Laravel's authorisation facilities for resource access. Never
  treat a controller, route name or frontend guard as sufficient authorisation.
- Put a state-changing use case in a focused Action, grouped by domain under
  `app/Actions/`. Prefer a descriptive verb-based class name and a typed
  `handle()` method.
- Put reusable or non-trivial read logic in a Query, grouped by domain under
  `app/Queries/`. Query classes must apply family-space scope explicitly and
  should return a deliberate model, collection or paginator type.
- Use Services for external or infrastructure capabilities such as object
  storage, rather than as a generic home for unrelated business logic.
- Use API Resources to define stable response shapes instead of returning
  accidental model serialisation.
- Inject Actions, Queries, Services and Reports through Laravel's container
  rather than constructing them in controllers.
- Wrap multi-record state changes in a database transaction when partial
  completion would violate an invariant.
- Scope ownership and family-space access at the query boundary, even when a
  policy also checks access. Add feature tests proving that another family space
  cannot list, read, change or delete the resource.

Do not create a layer merely to rename a one-line framework call. Apply this
pattern when it makes responsibility, reuse, testing or transaction boundaries
clearer, and inspect the surrounding code before introducing a new abstraction.

## React frontend data access

- React pages and presentational components must not call the shared HTTP client
  directly; feature endpoints belong in typed, feature-specific API modules.
- Use TanStack Query for server-owned state rather than fetching it through raw
  `useEffect` calls. Keep the shared API client limited to transport concerns.

## Architecture decisions

An ADR is normally appropriate when a decision:

- affects multiple services;
- establishes or changes a durable system boundary;
- has meaningful alternatives or trade-offs;
- changes security, tenancy, data ownership or operational behaviour; or
- would be difficult, risky or expensive to reverse.

Ordinary implementation details, commands, bug fixes and easily reversible
choices belong in the implementation guide or code review, not in an ADR.
Architecture decisions must be agreed with the human developer before an AI
assistant prepares the final ADR. Follow the ADR template in
`docs/IMPLEMENTATION_GUIDE.md` for numbering and required sections. Do not
rewrite an accepted ADR to change history; add a superseding ADR.

## Implementation evidence and learning records

`docs/IMPLEMENTATION_GUIDE.md` records what actually happened. A completed stage
entry must include its objective and rationale, commands actually executed,
files or observable behaviour changed, verification performed and its result,
meaningful problems and corrections, and the intended commit boundary. Never
record a planned command as though it succeeded.

A session journal in `docs/journal/` is a factual, reflective account of the
work, lessons and next steps, following the template in
`docs/IMPLEMENTATION_GUIDE.md`. An ADR in `docs/adr/` records a durable
decision, alternatives and consequences — the two record types have different
jobs and should not be merged.

## Quality and verification

The Make targets listed under "Standard verification categories" in
`docs/IMPLEMENTATION_GUIDE.md` are the canonical developer interface. They are
established during Phase 1 (Local Development Platform); until then, use the
native service commands documented by each application rather than inventing a
substitute. Do not report an unrun check as passing.

## Database and migration rules

- Make migrations forward-safe, focused and reviewable.
- Do not edit an already-shared migration merely to hide a later schema change.
  Create a new migration that makes the transition explicit.
- Provide a safe rollback where practical, and explain an intentionally
  irreversible migration.
- Ensure family-space-owned data follows the accepted tenancy architecture,
  including keys, constraints, queries and tests.
- Consider existing data, backfills, locking and deployment order rather than
  assuming an empty database.
- Keep destructive reset commands clearly named and intentionally invoked. Never
  place data deletion behind an innocent-sounding setup command.
- Fixtures, factories and seed data must use synthetic values and must never
  contain secrets, real personal information or real family photographs.

## Contracts and cross-service changes

Per ADR-0001, Laravel and the Python image-analysis service communicate only
through the versioned `ImageAnalysisRequested` / `ImageAnalysisCompleted` /
`ImageAnalysisFailed` message contract — never a direct HTTP callback, and never
a shared database. A contract change must:

- update every affected producer and consumer;
- add or update relevant contract, producer and consumer tests;
- preserve traceability of the originating family space and request;
- update the contract and implementation documentation; and
- consider backward compatibility, in-flight messages, redelivery and
  idempotency.

The frontend does not call the image-analysis service directly; the accepted
request path and service ownership remain authoritative unless an ADR changes
them.

## Security and privacy

- Never put secrets in source control, logs, fixtures, screenshots, journals or
  copied verification output.
- Never log passwords, session values, CSRF tokens, API tokens, cookies or
  credentials.
- Authentication and authorisation remain Laravel responsibilities unless an
  accepted ADR changes that boundary.
- Frontend guards improve user experience but are not security boundaries.
- Enforce family-space isolation server-side on every read, write, job, event
  and storage operation; fail closed.
- Add negative tests for security-sensitive changes, including unauthenticated,
  unauthorised, unverified and cross-family-space cases as applicable.
- Face embeddings and other recognition data require particular care: respect
  explicit recognition consent and exclusion, never enable public or
  cross-family face search, and never use family photographs for external model
  training (see `PRODUCT_VISION.md`, "Privacy and security").
- Report a discovered vulnerability and gather only the evidence needed to
  demonstrate it; do not exploit it unnecessarily.

## Git and commit conventions

- Keep each commit focused, understandable and aligned with the agreed stage
  boundary.
- Run and record the required verification before committing.
- Tag commits using the `phase-<n>` / `phase-<n>-s<NN>` convention defined in
  `docs/IMPLEMENTATION_GUIDE.md` ("Git tagging convention") — `<n>` and `<NN>`
  map directly onto the roadmap phase and stage identifiers (`P##` /
  `FPA-P##-S##`). ADR numbers never determine git phase numbers. Only a
  completed planned stage's completion commit receives a stage tag; record
  every tag produced in `tasks.json`'s `delivery_log`.
- Push approved commits and their tags to the configured remote
  (`git@github.com:davidoliver1984/fambam.git`) only after the human developer
  has approved the change.
- Review generated files, lock files, migration output and schema or contract
  artefacts before including them.
- Inspect `git status` and the staged diff so unrelated local or ignored files
  are not committed accidentally.
- Do not rewrite shared history or accepted tags to conceal a correction.
- The human developer owns the repository history. AI tools may prepare
  commits, suggest commit messages or assist with git operations, but commit
  boundaries, repository history and releases remain the responsibility of the
  human developer.

## Definition of done

A stage is complete only when:

- the agreed scope is implemented and no next-stage work has been started;
- all stage acceptance checks pass;
- all relevant repository-wide checks pass;
- the implementation remains aligned with accepted ADRs and service boundaries;
- `docs/IMPLEMENTATION_GUIDE.md` reflects the commands, changes, problems,
  corrections and verification that actually occurred;
- any required ADR has been agreed and recorded;
- the factual session journal exists;
- the intended commit boundary is satisfied;
- `tasks.json` is updated to advance the stage and its `delivery_log`; and
- no unexplained temporary files, disabled tests or unfinished changes remain.
