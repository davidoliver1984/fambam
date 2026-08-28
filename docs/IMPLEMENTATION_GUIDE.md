# fambam — Implementation Guide

> **Purpose:** This is the durable build guide for fambam.
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
Define fambam product and delivery plan
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

### Commands

```bash
make help
make foundation-check
make docs-check
make contracts-check
git diff --check
```

### Verification

- `make help` lists the foundation checks and reserved application targets.
- `make foundation-check` validates the required directory and file scaffold,
  JSON syntax, Markdown formatting and local documentation links.
- `make docs-check` validates JSON syntax, Markdown formatting and local links.
- `make contracts-check` confirms both reserved contract directories exist.
- `git diff --check` reports no whitespace errors.
- The initial GitHub Actions workflow runs `make foundation-check` on pushes and
  pull requests.

The first foundation check identified missing final newlines in
`PRODUCT_VISION.md` and `docs/ENGINEERING_METHODOLOGY.md`. Both were corrected,
and the complete verification set then passed locally on 2026-08-01.

### Risks and edge cases

- Application targets deliberately fail with a clear message until their
  services are introduced in Phase 1; placeholders must not report false
  success.
- The foundation validator uses only the Python standard library so it does not
  choose application dependencies ahead of ADR-0002.
- Phase 0's required architectural decisions are complete: ADR-0001 and
  ADR-0002 are accepted. The separately-planned documentation-conventions ADR
  (formerly ADR-0003) was removed as not meeting `CONTRIBUTING.md`'s own bar
  for when an ADR is warranted; documentation and task-state conventions are
  already fully specified in this guide's "Document ownership" section and in
  `CONTRIBUTING.md`.

### Documentation updates

- Added the root repository introduction and developer commands.
- Added reusable ADR and session-journal templates.
- Recorded the implementation session in
  `docs/journal/2026-08-01-FPA-P00-S03.md`.
- Advanced `tasks.json` to `FPA-P01-S01` after scaffold integration, final
  documentation reconciliation and the complete Phase 0 verification gate
  passed.

### Commit boundary

```text
Scaffold repository foundation
```

---

# Phase 1 — Local Development Platform

## FPA-P01-S01 — Accept local development platform ADR

### Objective

Decide container strategy, object-storage abstraction and local provider,
queue transport and background-job ownership, and the local observability
baseline, in one ADR (ADR-0003) before any Phase 1 implementation begins.

### Engineering rationale

These four concerns are tightly coupled in a local Docker Compose
environment — the compose topology, the storage provider and the queue
transport all have to agree with each other before `compose.yaml` can be
written. Deciding them together, once, avoids sequencing implementation
stages around partial decisions.

### Prerequisites

- FPA-P00-S03 complete.

### Expected changes

- ADR-0003 accepted.

### Verification

- ADR-0003 covers container strategy, object storage, queue transport and
  observability baseline explicitly.

ADR-0003 was accepted 2026-08-01: Docker Compose; LocalStack for local
S3-compatible storage and SQS-compatible queue transport; Redis scoped to
caching and ephemeral application state only, not queueing; Mailpit; and a
Phase 1 OpenTelemetry baseline (instrumentation, Collector, HTTP/message
trace-context propagation, structured logs, baseline traces and metrics,
local Grafana `otel-lgtm`), with custom domain spans and business-level
metrics deferred to the phases that implement real workflows. This stage's
entire scope was accepting the ADR, so the ADR-acceptance commit is this
stage's completion commit and received the `phase-1-s01` tag directly.

### Commit boundary

```text
Accept ADR-0003: Local development platform
```

## FPA-P01-S02 — Scaffold web, API and image-analysis applications

### Objective

Create independently testable application skeletons.

### Engineering rationale

Establish each accepted application boundary and its native quality tooling
before introducing Compose or shared infrastructure. Independent health checks
and tests keep application failures distinguishable from infrastructure failures
in the next stage.

### Prerequisites

- FPA-P01-S01 complete.
- ADR-0001, ADR-0002 and ADR-0003 accepted.

### Expected changes

- Web application.
- Laravel API.
- Python image-analysis service.
- Health endpoints.
- Service-level tests.

### Commands

```bash
npm create vite@latest apps/web -- --template react-ts
composer create-project laravel/laravel apps/api
uv init --app --name fambam-image-ai --python 3.13 apps/image-ai
npm install
composer require --dev larastan/larastan
uv add fastapi 'uvicorn[standard]'
uv add --dev ruff mypy pytest httpx
make format-check
make lint
make typecheck
make test
make foundation-check
make contracts-check
npm run build
composer validate --strict
uv lock --check
make security-check
git diff --check
```

### Verification

- React 19.2.8, Vite 8.2.0 and TypeScript 6.0.2 are locked for the web app;
  Laravel 13.23.0 is locked for the API; FastAPI 0.141.1 is locked for the
  image-analysis service.
- Node 24.7.0, PHP 8.4.5 and Python 3.13.7 are recorded in `.tool-versions`.
- The web application starts independently and serves `/health` over HTTP.
- Laravel starts independently and `GET /api/health` returns HTTP 200 with
  `{"service":"api","status":"ok"}`.
- FastAPI starts independently and `GET /health` returns HTTP 200 with
  `{"service":"image-ai","status":"ok"}`.
- Vitest passes 2 web tests; PHPUnit passes 2 API tests with 3 assertions;
  pytest passes 1 image-analysis test.
- Prettier, Pint and Ruff formatting checks pass.
- ESLint and Ruff linting pass.
- TypeScript, Larastan/PHPStan and mypy type checks pass.
- The web production build, Composer validation and uv lock validation pass.
- npm and Composer report no known dependency vulnerabilities.
- Foundation, documentation, contract, JSON and Git whitespace checks pass.

### Risks and edge cases

- Vite 8 initially generated Oxlint configuration; this was replaced with the
  ESLint baseline required by ADR-0002.
- The generated Laravel frontend assets and Vite integration were removed so
  Laravel remains an API rather than a second frontend application.
- The foundation JSON validator initially treated TypeScript's JSON-with-comments
  configuration as strict JSON and scanned ignored GPT draft files. It now
  delegates TypeScript configuration validation to `tsc` and excludes the
  ignored draft directory.
- Initial strict checks found a React non-null assertion, a PHPUnit-style
  mismatch and a Python import-path issue. Each underlying scaffold defect was
  corrected before the successful verification run.
- Local infrastructure, SQS/S3 integration and OpenTelemetry remain strictly in
  FPA-P01-S03 and FPA-P01-S04.

### Documentation updates

- Added service-specific READMEs with native commands and health endpoints.
- Updated the root README and Make command surface.
- Recorded the session in `docs/journal/2026-08-01-FPA-P01-S02.md`.
- Advanced `tasks.json` to `FPA-P01-S03` after review and the complete
  application-scaffold verification gate passed.

### Commit boundary

```text
Scaffold application services
```

## FPA-P01-S03 — Add local infrastructure services

### Objective

Provide a reproducible Docker Compose platform containing the applications,
PostgreSQL, Redis, S3-compatible storage, SQS-compatible messaging and local
mail.

### Engineering rationale

One Compose topology gives every contributor the same service names, dependency
ordering and safe local defaults. Health-gated application startup separates
infrastructure readiness from application faults. Named volumes preserve local
database, cache and object-storage state across ordinary restarts, while a
single smoke command proves both direct infrastructure access and Laravel's
configured Redis, S3 and SQS integrations.

### Prerequisites

- FPA-P01-S02 complete.
- Docker with Compose v2 available.
- ADR-0001, ADR-0002 and ADR-0003 accepted.

### Expected changes

- `compose.yaml`.
- Persistent local volumes.
- Environment templates.
- Health checks.
- Network definitions.
- Make targets.

### Commands

```bash
docker compose config --quiet
make up
make status
make infrastructure-smoke
make restart
make infrastructure-smoke
make format-check
make lint
make typecheck
make test
make foundation-check
make contracts-check
make security-check
git diff --check
```

### Verification

- `make up` starts the platform.
- Applications can reach required dependencies.
- Object upload and retrieval smoke test passes.
- Queue round-trip smoke test passes.

The platform was implemented and verified locally on 2026-08-01. Compose builds
and health-gates the React/Vite, Laravel and FastAPI containers, plus pinned
PostgreSQL 17.6, Redis 7.4.5, LocalStack 4.5.0 and Mailpit 1.27.7 services. The
Laravel container applies migrations before serving requests. LocalStack's
ready hook idempotently creates the media bucket and the requested, completed
and failed image-analysis queues.

`make up`, `make status` and `make infrastructure-smoke` passed. The smoke test
confirmed PostgreSQL readiness, Redis connectivity, direct S3 upload/download,
an SQS send/receive round trip, API migrations, and Laravel access to Redis, S3
and SQS. `make restart` followed by the same smoke test also passed, confirming
that an ordinary restart retains the named volumes. All application health
endpoints and the Mailpit and LocalStack health surfaces responded successfully.

### Risks and edge cases

- Common local development ports were already occupied by unrelated projects.
  fambam therefore defaults to a dedicated host-port range while retaining the
  services' conventional internal ports; every host port remains overridable
  through the root environment template.
- Compose defaults are development-only and contain no production credentials.
  Real family data must not be used in this environment or committed.
- Application images run as non-root users. Infrastructure images are pinned to
  exact versions to avoid silent local-environment drift.
- Redis remains limited to caching and ephemeral state. SQS is the accepted
  asynchronous transport.
- OpenTelemetry and the local observability interface remain strictly scoped to
  FPA-P01-S04 and are intentionally absent here.

### Documentation updates

- Documented the local commands, endpoints and environment override workflow in
  the root README.
- Added Dockerfiles, ignore files and environment templates for each service.
- Recorded implementation and verification in
  `docs/journal/2026-08-01-FPA-P01-S03.md`.
- Advanced `tasks.json` to FPA-P01-S04 after review and the complete local
  infrastructure verification gate passed.

### Commit boundary

```text
Add reproducible local infrastructure
```

## FPA-P01-S04 — Add baseline observability

### Objective

Make requests, jobs and image-analysis failures diagnosable from the start.

### Engineering rationale

Establish the vendor-neutral telemetry boundary and propagation conventions
before real upload and image-analysis workflows freeze their interfaces.
Request, correlation and trace identifiers make local failures joinable across
logs and spans. A synthetic message path proves the architectural mechanism
without pretending that Phase 9's image-analysis workflow already exists.

### Prerequisites

- FPA-P01-S03 complete.
- ADR-0003 accepted.

### Expected changes

- Structured logs.
- Correlation/request identifiers.
- Queue-job identifiers.
- Basic metrics or OpenTelemetry integration.
- Local inspection interface if accepted by ADR.

### Commands

```bash
docker compose config --quiet
make up
make observability-smoke
make infrastructure-smoke
make format-check
make lint
make typecheck
make test
make foundation-check
make contracts-check
make security-check
git diff --check
```

### Verification

- One upload-related synthetic request can be traced across participating services.
- Failed jobs include actionable context without exposing sensitive media.

The baseline was implemented and verified locally on 2026-08-01. Laravel and
FastAPI emit OTLP traces and metrics to a dedicated OpenTelemetry Collector,
which forwards them to the pinned Grafana LGTM backend. Both services emit
structured JSON application logs with service and trace identifiers; HTTP
responses preserve request and correlation identifiers.

The synthetic upload endpoint creates a producer span and publishes a W3C
`traceparent` message attribute to the accepted SQS queue. A one-shot stateless
image-analysis consumer extracts that context and creates a consumer span. The
automated smoke test compares their trace IDs, verifies both spans reached the
Collector and checks Grafana health. This is verification scaffolding, not a
premature implementation of the Phase 9 image-analysis worker.

### Risks and edge cases

- The initial smoke check proved that both spans existed but did not compare
  trace IDs; detailed inspection exposed broken propagation. The verifier now
  compares the producer and consumer trace IDs directly.
- Stale synthetic messages can remain after an interrupted run. Each verification
  message carries a correlation identifier, and the consumer discards unrelated
  synthetic messages before evaluating the current one.
- Custom domain spans and business metrics remain deferred until their owning
  workflows exist, as required by ADR-0003.
- The Grafana LGTM image is intentionally substantial; ADR-0003 records resource
  use as a review trigger.
- Phase-end review (run against a live stack, not just re-reading the diff)
  found that `scripts/smoke-infrastructure.sh` (from `FPA-P01-S03`) was not
  idempotent: it received an SQS message without ever deleting it, so
  leftover messages from earlier runs could cause a later run to read stale
  content and fail. Fixed by using a unique payload per run, deleting every
  message received, and looping until the current run's payload is found
  (confirmed idempotent across three consecutive runs). The same review also
  found `POST /api/observability/synthetic-upload` was reachable in any
  environment with no auth; it is now gated behind
  `app()->environment(['local', 'testing'])` both at route registration and
  inside the handler, and the synthetic SQS consumer's message-receive logic
  was extracted into a tested, protocol-typed function that no longer risks
  an unhandled `IndexError` on an empty poll response.

### Documentation updates

- Added the Grafana endpoint and observability smoke command to the root README.
- Recorded the implementation and corrections in
  `docs/journal/2026-08-01-FPA-P01-S04.md`.
- Phase-end review complete: all verification commands re-run against a live
  stack and passed, including three consecutive `make infrastructure-smoke`
  runs to confirm the idempotency fix. `FPA-P01-S04` and Phase 1 completed on
  the same completion commit.

### Commit boundary

```text
Add local observability baseline
```

---

# Phase 2 — Identity, Authentication and Invitations

## FPA-P02-S01 — Accept identity, authentication and invitations ADR

### Objective

Decide the authentication backend, session model, invitation lifecycle,
password policy, MFA foundation and account-security posture for Phase 2 in
one ADR (ADR-0004) before any Phase 2 implementation begins.

### Engineering rationale

Registration, session management, invitation issuance and acceptance, and
account-security hardening are tightly coupled: the session store choice
constrains how revocation works, the invitation-acceptance flow decides
what "email verified" means, and the password policy depends on whether MFA
is mandatory. Deciding them together, once, avoids sequencing
FPA-P02-S02 through FPA-P02-S04 around partial or contradictory decisions.

### Prerequisites

- FPA-P01-S04 complete.

### Expected changes

- ADR-0004 accepted.

### Verification

- ADR-0004 covers the authentication backend, session and CSRF strategy,
  invitation lifecycle and token safety, MFA foundation, account-security
  hardening (rate limiting, lockout, password policy) and
  email-verification/recovery scope explicitly.

ADR-0004 was accepted 2026-08-02: headless Laravel Fortify with Sanctum SPA
cookie-mode sessions on a database session store; a shared global
session/remember-me revocation operation; invitation-only account creation
with the stock Fortify registration endpoint disabled; invitation
acceptance treated as email verification, with the invited email address
authoritative and non-editable at acceptance; a 15-character minimum
password length with safeguarded HIBP compromised-password screening
(fail-open, no password material logged); optional TOTP MFA off by
default; throttling rather than hard account lockout; and no CAPTCHA or
social login. This stage's entire scope was accepting the ADR, so the
ADR-acceptance commit is this stage's completion commit and receives the
`phase-2-s01` tag directly.

### Commit boundary

```text
Accept ADR-0004: Identity, authentication and invitations
```

## FPA-P02-S02 — Implement account authentication

### Objective

Implement ADR-0004's account authentication foundation: headless Fortify and
Sanctum SPA cookie sessions, login, logout, current-user/profile access,
email-verification primitives and password reset. Keep open registration
disabled; invitation acceptance creates accounts in FPA-P02-S03.

### Engineering rationale

Authentication must exist before an invitation can establish a usable account,
but account creation cannot safely be exposed before the invitation lifecycle
exists. This stage therefore establishes the session and recovery boundary
without creating a temporary open-registration path that the next stage would
need to remove.

### Prerequisites

- FPA-P02-S01 complete and ADR-0004 accepted.

### Expected changes

- Install and configure headless Laravel Fortify and Sanctum stateful SPA
  middleware.
- Use database-backed, HTTP-only, SameSite session cookies with explicit
  stateful-domain and credentialed-CORS configuration.
- Add login, logout, current-user and basic display-name/timezone profile flows.
- Add enumeration-safe password-reset request and reset flows with reset links
  targeting the React application.
- Retain Laravel's email-verification contract and routes for later verified
  email workflows.
- Add the Fortify TOTP schema required by ADR-0004, while leaving the feature
  disabled until FPA-P02-S04.
- Add React login, password-recovery and account-profile surfaces with correct
  password-manager autocomplete behaviour.
- Keep Fortify registration, password change, MFA and passkey surfaces disabled.

### Verification

- Feature tests prove successful and failed login, logout, authentication
  enforcement, profile validation, enumeration-safe reset requests, password
  reset and email verification.
- Tests prove the stock registration route is absent and cannot create a user.
- Web tests cover the login surface and password-manager-compatible fields.
- CORS is credentialed only for configured origins and the Sanctum CSRF cookie
  is issued.
- SQLite and live PostgreSQL migrations succeed.
- The repository format, lint, type, test, security, infrastructure and
  observability gates pass.

### Documentation updates

- Update the README status and account-entry guidance.
- Record the implementation and verification in
  `docs/journal/2026-08-02-FPA-P02-S02.md`.
- Mark FPA-P02-S02 in progress until review and completion approval.

### Commit boundary

```text
Implement account authentication
```

FPA-P02-S02 completed on 2026-08-02 after the full verification gate and live
PostgreSQL-backed CSRF, login, current-user and logout flow passed. Open
registration remains absent; account creation stays reserved for the invitation
lifecycle in FPA-P02-S03.

## FPA-P02-S03 — Implement invitation lifecycle

### Objective

Implement ADR-0004's account-creation invitation lifecycle: first-account
bootstrap, issue, resend, revoke, expire-on-touch, secure token exchange and
transactional acceptance with an audit record for every transition.

### Engineering rationale

There is intentionally no open registration endpoint, so invitation acceptance
is the sole HTTP account-creation boundary. Email links contain sensitive bearer
material; the browser must remove it before rendering the password form, while
the server must prevent replay, resend/revoke races and duplicate account
creation.

### Prerequisites

- FPA-P02-S02 complete.

### Expected changes

- Add `can_invite` as the temporary Phase 2 invitation permission, defaulting to
  false.
- Add an interactive operator command that creates only the first verified,
  invitation-capable account and never accepts a password as a command argument.
- Add invitation, short-lived invitation-claim and generic audit-event records.
- Store only SHA-256 token hashes; send at least 128 bits of random bearer
  material through Mailpit/email.
- Put the emailed token in the web URL fragment, not its query string, exchange
  it once for a 15-minute opaque claim and immediately replace browser history
  with a clean acceptance URL.
- Make resend rotate the token on the same record and invalidate outstanding
  claims; make revoke invalidate both token and claims.
- Treat `expires_at` as acceptance authority, persisting and auditing `expired`
  when an expired invitation is touched.
- Lock invitation and claim rows during acceptance, creating the verified user,
  consuming the claim and accepting the invitation in one transaction.
- Take the account email exclusively from the invitation; prohibit an email
  field on acceptance.
- Add authenticated invitation management and public acceptance surfaces with
  named rate limits.

### Verification

- Feature tests cover bootstrap restrictions, permission denial, token hashing,
  lifecycle transitions, token/claim replay, expiry, authoritative email,
  password length, account verification and audit records.
- Frontend tests prove the acceptance form has no editable email, uses
  password-manager-compatible new-password fields, exchanges a fragment token
  once under Strict Mode and refreshes invitation lists through TanStack Query.
- SQLite and live PostgreSQL migrations pass.
- A live Mailpit message contains a fragment-based link and the complete
  CSRF/exchange/accept/login flow succeeds against the rebuilt stack.
- Repository quality, dependency, infrastructure and observability gates pass.

### Documentation updates

- Document first-account bootstrap and local invitation delivery in the README.
- Record the implementation and verification in
  `docs/journal/2026-08-02-FPA-P02-S03.md`.
- FPA-P02-S03 completed on 2026-08-02 after review approval and the full
  verification gate.

### Commit boundary

```text
Implement invitation lifecycle
```

## FPA-P02-S04 — Harden account security

### Objective

Complete ADR-0004's account-security boundary with reusable global access
revocation, operator account revocation, consistent password policy,
safeguarded compromised-password screening, optional TOTP and accessible
account-security controls.

### Engineering rationale

Deleting a database session does not invalidate a remember-me credential or
prevent a revoked account from signing in again. Password reset and password
change must therefore use one shared operation that removes every session,
rotates the remember token and records the cause. Optional MFA strengthens an
account without making first login inaccessible to less-technical relatives.

### Expected changes

- Add a shared access-revocation operation used by password reset, password
  change, user-requested "sign out everywhere" and operator revocation.
- Persist account revocation and reject future login with the same generic
  credential response used for an incorrect password.
- Apply a shared 15-to-255-character Unicode password rule to invitation
  acceptance, bootstrap, reset and password change.
- Use a 1.5-second padded HIBP range request that fails open without logging
  the password, full hash or SHA-1 prefix.
- Add a named email-and-IP password-reset limiter alongside the accepted login,
  invitation and two-factor limiters; do not add hard account lockout or CAPTCHA.
- Enable Fortify's optional, confirmed TOTP flow and audit security-sensitive
  MFA transitions.
- Add password change, sign-out-everywhere, authenticator setup and two-factor
  challenge UI through typed feature API modules and TanStack Query hooks.
- Apply the authoritative frontend standard, including strict TypeScript,
  cancellation-aware reads, React Hook Form/Zod security forms, MSW API tests,
  accessibility linting and a root error boundary.

### Verification

- Feature tests prove every revocation path deletes owned sessions, rotates the
  remember token and prevents a revoked account from signing in.
- Password tests cover minimum length, compromised rejection, padded range
  requests and safe fail-open behavior without password-derived logs.
- TOTP tests cover enable, confirmation, recovery-code login and audit events.
- Frontend tests cover cross-field password validation, Laravel field-error
  mapping, Strict Mode token exchange and root render-error recovery.
- The complete repository gate, live PostgreSQL migrations and browser flows
  pass before completion approval.

An independent review of the first implementation found two blocking gaps and
several important-before-closure findings: a revoked account could still
complete an already-pending two-factor challenge and obtain a session, because
neither the shared revocation service nor Fortify's own challenge-completion
path re-checked `revoked_at` after the first factor; bcrypt silently truncates
input at 72 bytes, undermining the advertised 15-to-255-character policy;
recovery codes were repeatedly retrievable in plaintext rather than shown once;
operator revocation recorded no actor; login exposed a timing distinction
between an unknown/revoked account and a wrong password on a live account; and
the local `.env.example` hardcoded `SESSION_SECURE_COOKIE=false`, capable of
overriding the new production-safe default if copied verbatim. The repository
had also been committed and locally tagged `phase-2-s04`/`phase-2` before that
review completed; the tags were removed and the stage returned to in-progress
pending correction.

The corrected implementation subscribes to Fortify's
`ValidTwoFactorAuthenticationCodeProvided` event -- fired before the challenge
controller logs the user in -- to re-verify `revoked_at` and force a generic
failure response if the account was revoked between the first factor and the
completed challenge; moves the default hashing driver to Argon2id (with
bcrypt verification and transparent rehash-on-login preserved for existing
hashes); restricts recovery-code disclosure to the one-time generation
response and 404s the previously repeatable read; requires and records an
operator reference on every console revocation; removes the short-circuit
that skipped password hashing for unknown or revoked accounts so login always
pays the same hashing cost; and removes the insecure local cookie default. A
focused independent re-review verified every fix directly against the code,
the full test suite (PHPUnit and Vitest), and a live reproduction of the
original revoked-mid-challenge exploit against the rebuilt stack, confirming
it is now rejected. The complete repository gate, live PostgreSQL migrations
and rebuilt local stack passed again after the corrections. FPA-P02-S04 and
Phase 2 completed on the same completion commit, following that review's
approval.

### Documentation updates

- Recorded the implementation, the independent review findings, the
  corrections and the re-review outcome in
  `docs/journal/2026-08-02-FPA-P02-S04.md`.
- Advanced `tasks.json` to `FPA-P03-S01` after FPA-P02-S04 and the Phase 2
  acceptance gate passed.

### Commit boundary

```text
Fix Phase 2 review findings: MFA-challenge revocation, Argon2id migration, recovery-code disclosure, audit attribution, login timing, secure-cookie default, test coverage
```

### Phase verification

- Feature tests cover valid and invalid invitation paths.
- Authentication cookies and CORS settings are production-safe.
- Revoked users cannot retain active sessions.
- Security-sensitive actions are audited.

---

# Phase 3 — Family Spaces and Tenancy

## FPA-P03-S01 — Accept family spaces and tenancy ADR

### Objective

Decide the family-space tenant boundary, the five-role membership model,
family-space creation authority, invitation and rejoin semantics, the
friendly-URL resolution chain, the defense-in-depth stack including
PostgreSQL RLS, asynchronous tenant-context propagation, audit coverage,
and the deletion lifecycle, in one ADR (ADR-0005) before any Phase 3
implementation begins.

### Engineering rationale

Every later Phase 3 stage — memberships, route resolution, RLS, audit and
deletion — depends on the same tenant model and the same resolution
sequence. Deciding the trust boundary and its required guarantees once,
before any table or policy is implemented, avoids each stage guessing at
decisions the others depend on.

### Prerequisites

- FPA-P02-S04 complete.

### Expected changes

- ADR-0005 accepted.

### Verification

- ADR-0005 covers the tenant model, roles, creation authority, invitation
  and rejoin semantics, friendly-URL resolution, defense-in-depth layering,
  PostgreSQL RLS treatment, async propagation, audit coverage and the
  deletion lifecycle explicitly.

ADR-0005 was accepted 2026-08-03: `family_spaces` and
`family_space_memberships` as the tenant model, with ULID identifiers and a
mutable presentation slug; all five roadmap-named roles (Owner,
Administrator, Member, Contributor, Guest) with a Phase 3 baseline
membership-administration meaning; family-space creation as a narrow,
platform-level capability (`users.can_create_family_spaces`) never implied
by any membership role and never auto-granted at bootstrap; one invitation
domain and acceptance flow covering both new-account and existing-account
joins, with an atomic, race-safe rejoin operation for previously removed
members; friendly-URL resolution where unknown and inaccessible tenants are
structurally indistinguishable and both return 404; ownership requiring at
least one active Owner per space, enforced at both the application layer
and a database constraint trigger; and PostgreSQL RLS treated as three
distinct policy classes — the `family_spaces` tenant registry, the
`family_space_memberships` access-control table, and ordinary tenant-owned
content tables — rather than one generic policy applied uniformly, since
the registry and membership tables must remain resolvable before tenant
context exists while ordinary content tables require it. The ADR records
durable trust boundaries and required guarantees, not a frozen PostgreSQL
implementation; the exact policy composition, migration order and
session-context mechanics are Phase 3 implementation decisions, verified
against the ADR's stated guarantees rather than fixed by it. This stage's
entire scope was accepting the ADR, so the ADR-acceptance commit is this
stage's completion commit and receives the `phase-3-s01` tag directly.

### Documentation updates

- Advanced `tasks.json` to `FPA-P03-S02` after FPA-P03-S01 completed.

### Commit boundary

```text
Accept ADR-0005: Family spaces and tenancy
```

## FPA-P03-S02 — Implement family spaces and memberships

Create family spaces, ownership rules and membership lifecycle.

FPA-P03-S02 completed on 2026-08-04 after the full repository gate and live
PostgreSQL migration verification passed. The implementation added ULID-backed
Family Spaces and memberships, capability-gated creation, application and
deferred database last-Owner safeguards, family-scoped invitations and atomic
reactivation of removed memberships. The approved persistent migration created
the fallback Family Archive, backfilled Owner/Member assignments and historical
invitations, expired pending Phase 2 invitations, removed their claims and
retired `can_invite`.

### Documentation updates

- Recorded implementation and verification in
  `docs/journal/2026-08-04-FPA-P03-S02.md`.
- Advanced `tasks.json` to FPA-P03-S03 after completion approval.

### Commit boundary

```text
Implement family spaces and memberships
```

## FPA-P03-S03 — Implement route context, policies and explicit scoping

Every tenant route resolves a public family identifier and fails closed.

FPA-P03-S03 completed on 2026-08-04 after the full repository gate and
rebuilt-stack HTTP checks passed. Active-membership slug resolution now runs
before implicit model binding, establishes and clears a request-scoped
`TenantContext`, and returns the same 404 for unknown, inaccessible and removed
Family Spaces. Role policies distinguish post-resolution 403 responses, while
explicit query classes constrain Family Spaces, memberships and invitations at
their call sites. Nested resources cannot be resolved across Family Spaces.

The frontend introduced the audit-clean direct `react-router` package for the
first protected tenant route. `/families/:familySlug` is authoritative for the
active Family Space; typed API modules and TanStack Query keys carry that slug
through Family Space and invitation reads and mutations.

### Documentation updates

- Recorded implementation, verification and deliberately deferred navigation
  debt in `docs/journal/2026-08-04-FPA-P03-S03.md`.
- Advanced `tasks.json` to FPA-P03-S04 after completion approval.

### Commit boundary

```text
Implement route context, policies and explicit scoping
```

## FPA-P03-S04 — Add PostgreSQL row-level security

Apply non-bypass runtime roles, FORCE RLS where appropriate and integration
tests. Per ADR-0005 §9, the chosen policy design for the `family_spaces`
tenant registry and the `family_space_memberships` access-control table
must be checked for recursive or circular policy evaluation directly
against PostgreSQL's actual behaviour before acceptance — not assumed
correct from documentation.

### Concrete policy design

- PostgreSQL is provisioned with a migration owner and a separate application
  runtime role. The runtime role is explicitly `NOSUPERUSER` and
  `NOBYPASSRLS`; only the migration command receives owner credentials.
- Every database-backed HTTP request runs in a transaction. Authentication
  establishes transaction-local `app.current_user_id`; successful Family
  Space resolution then establishes `app.current_family_space_id`. The local
  settings are explicitly cleared and PostgreSQL also discards them on commit
  or rollback, preventing pooled-connection leakage.
- `family_spaces` uses a registry policy: before tenant context exists, an
  authenticated user can see only spaces for which their own active membership
  is visible. Creation uses the new space's independently authoritative ULID;
  ordinary mutation requires matching tenant context; runtime hard deletion is
  not granted.
- `family_space_memberships` permits pre-context self-visibility. Tenant-wide
  visibility and writes require matching tenant context, and writes additionally
  require the resolved membership-management capability or the bounded Family
  Space creation/invitation-acceptance operation.
- No ordinary tenant-owned content table exists at this stage. The standard
  Class C pattern is nevertheless fixed and tested directly: enable and force
  RLS, use `family_space_id = app_current_family_space_id()` for both `USING`
  and `WITH CHECK`, and provide no context-free policy. This makes missing
  context, cross-tenant insertion and tenant reassignment fail closed. Future
  ordinary tenant tables must apply this complete pattern in their migration.

The registry policy's membership lookup terminates at the membership table's
self-or-context policy; that policy does not query the registry. The disposable
PostgreSQL 17.6 integration suite executes this resolution path directly and
would fail on PostgreSQL's recursive-policy error if the design became circular.
The same suite proves runtime-role attributes, forced RLS, pre-context
visibility, membership administration, Class C reads and writes, atomic
creation and invitation acceptance, and context cleanup after commit and
rollback. Run it with `make test-api-postgres-rls`.

FPA-P03-S04 completed on 2026-08-04 after the disposable PostgreSQL 17.6
integration suite, complete repository gate, approved persistent migration and
rebuilt-stack verification passed. The live API runs as the separately
provisioned non-superuser, non-`BYPASSRLS` role; both special tenant tables
force RLS; missing context fails closed; valid self and tenant context exposes
only authorised rows; and transaction-local settings clear after commit and
rollback. Existing Family Space, membership, Owner and invitation associations
were preserved.

### Documentation updates

- Recorded implementation, policy design and verification in
  `docs/journal/2026-08-04-FPA-P03-S04.md`.
- Advanced `tasks.json` to FPA-P03-S05 after completion approval.

### Commit boundary

```text
Add PostgreSQL row-level security
```

## FPA-P03-S05 — Add tenancy audit and deletion foundations

Audit membership changes and define asynchronous deletion states.

### Concrete implementation

- Every new audit row records an explicit nullable `family_space_id`, nullable
  `actor_user_id`, non-null `correlation_id` and non-null W3C `traceparent`.
  Historical audit rows are backfilled with their recoverable Family Space and
  stable legacy correlation identity. The runtime role may insert audit rows in
  the active tenant context but has neither audit read privilege nor a read
  policy; update and delete privileges and policies are also absent.
- `TenantOperationContext` is the single serializable asynchronous envelope:
  `family_space_id`, `actor_user_id`, `correlation_id` and `traceparent`. The
  scheduler creates a producer span, the deletion job restores the W3C parent
  into a consumer span, and the same context reaches database settings, logs
  and the completion audit record.
- Family Space deletion is Owner-only. Requesting it persists the configured
  grace deadline as an absolute timestamp (14 days by default); cancellation is
  possible only while the state is `deletion_requested`. Owners and
  Administrators can see the pending-deletion details; other roles cannot.
- The scheduler discovers only due deletion identifiers through a bounded
  PostgreSQL function and publishes unique teardown jobs to the separate
  `fambam-jobs` queue. Teardown is idempotent, resumes safely from `deleting`,
  exits after cancellation or completion, and sets `family_spaces.status` to
  `deleted` before soft-removing any active membership, including the final
  Owner, in the same transaction.
- `FamilyStorageKey` establishes the required `families/{family_space_id}/...`
  partition and rejects empty, traversal and NUL-bearing paths. No Phase 5
  media operation is introduced early. Likewise, the existing synthetic
  image-analysis message now carries the complete context field shape, but no
  speculative production image message is added.
- Compose includes idempotent LocalStack provisioning, a dedicated Laravel job
  queue, a queue worker and a scheduler. Application jobs no longer share the
  image-analysis request queue.

### Phase verification

- Cross-tenant feature and database tests pass.
- A family cannot be left without an owner.
- Background jobs carry explicit tenant context.
- Storage keys include safe family partitioning.

FPA-P03-S05 and Phase 3 completed on 2026-08-04 after the persistent migration,
rebuilt-stack smoke checks and full repository gate passed. The accepted
follow-up review added direct PostgreSQL proof for concurrent membership
reactivation, multi-space cross-tenant mutation rejection, and insert-only audit
access, plus frontend regressions for protected routing, cache separation when
switching Family Spaces, and graceful membership loss.

### Documentation updates

- Recorded implementation, migration and verification in
  `docs/journal/2026-08-04-FPA-P03-S05.md`.
- Completed Phase 3 in `tasks.json` and advanced the current stage to
  FPA-P04-S01.

### Commit boundary

```text
Add tenancy audit and deletion foundations
```

---

# Phase 4 — People, Accounts and Relationships

## FPA-P04-S01 — Accept people, accounts and relationships ADR

### Objective

Decide the Person identity model, User-to-Person linking, the relationship
model, Family Circles, a Phase 4 privacy/role baseline, an uncertain-date
semantic contract, and the merge/tombstone model, in one ADR (ADR-0006)
before any Phase 4 implementation begins.

### Engineering rationale

Person identity, account linking, relationships, circles, privacy defaults
and merge behaviour are tightly coupled: the User/Person separation
constrains how linking and self-claiming work, the proposed-versus-
authoritative distinction shapes both Person-detail and relationship
authority identically, and the merge model must already account for every
Phase-4-owned reference before FPA-P04-S02 through FPA-P04-S05 are
implemented. Deciding them together, once, avoids sequencing those stages
around partial or contradictory decisions -- the same reasoning ADR-0004
and ADR-0005 applied to their own phases.

### Prerequisites

- FPA-P03-S05 complete.

### Expected changes

- ADR-0006 accepted.

### Verification

- ADR-0006 covers Family-Space-local Person identity, the Person
  identity/lifecycle model, the uncertain-date semantic contract, the
  proposed-versus-authoritative distinction applied uniformly to Person
  details and relationships, User-to-Person cardinality and link
  authority, the relationship model and its proposal/approval validation,
  Family Circles, the Phase 4 privacy/role visibility baseline, tenancy
  inheritance under ADR-0005, the merge/tombstone/reversal model, route
  identity, the authorization baseline, audit coverage, and the
  access-loss/deletion lifecycle explicitly.

ADR-0006 was accepted 2026-08-05: Person identity is Family-Space-local,
with no cross-tenant identity bridge and no automatic or manual
cross-family matching -- the same real person represented in two Family
Spaces is two independent Person records; an immutable ULID Person
identity with mutable names, uncertain birth/death information via a
shared uncertain-date concept (exact, month-and-year, year-only, decade,
approximate, unknown), an independent deceased-state fact composed with
that concept rather than absorbed into it, and bounded biography/notes
treated as shared archival content under the same whole-record visibility
as the rest of the Person record; a single proposed-versus-authoritative
distinction applied uniformly to Person details and to relationships,
where Members may contribute or propose and Owner/Administrator confirm,
replace, remove or resolve as authoritative, with proposed relationships
excluded from the authoritative relationship graph until approved and
approval re-validating against current state rather than blindly
promoting the original proposal; one-to-one User-to-Person linking per
Family Space, with Member and reachable-Contributor self-claim proposals
requiring Owner/Administrator approval and Guest excluded from
self-claiming; one canonical relationship edge per concept with derived
inverse wording from a centrally-defined, additive V1 vocabulary, never a
permission signal; Family Circles as a real, flat, People-only,
presentation-only construct explicitly barred from ever being consulted
by authorization, policy or RLS; a Phase 4 visibility baseline giving
Owner/Administrator/Member default Person-directory access and giving
Contributor/Guest none, named explicitly as a placeholder for Phase
5/6/7's resource-grant model; full Class C tenant-owned-table inheritance
from ADR-0005 for Person, link, relationship, circle and
circle-membership data, with no RLS SQL frozen in the ADR; Person merge
via a redirect/tombstone record with atomic reconciliation of
Phase-4-owned references, structured merge provenance distinct from
ordinary AuditEvents, and reversal bounded to what Phase 4 itself can
unambiguously restore; ULID-only Person detail routes with no Person
slug; the full Owner/Administrator/Member/Contributor/Guest authorization
table across Person, relationship, link, circle, duplicate and merge
actions; explicit AuditEvent coverage for Person, relationship-proposal/
approval/rejection/dispute, link, circle, duplicate and merge actions;
and retention of the User-to-Person link through account revocation,
membership removal and deceased-status change, with only an explicit,
audited Owner/Administrator unlink or correction changing it, and full
Phase 4 participation in ADR-0005's Family Space deletion lifecycle. This
stage's entire scope was accepting the ADR, so the ADR-acceptance commit
is this stage's completion commit and receives the `phase-4-s01` tag
directly.

### Documentation updates

- Advanced `tasks.json` to `FPA-P04-S02` after FPA-P04-S01 completed.

### Commit boundary

```text
Accept ADR-0006: People, accounts and relationships
```

## FPA-P04-S02 — Implement person records

Support living, deceased and account-less People with an immutable ULID
identity, mutable presentation data, the shared uncertain-date concept for
birth/death information, and the Member-proposed / Owner-Administrator-
confirmed distinction for identity details, per ADR-0006 §§1-4, §10 and
§13.

The concrete uncertain-date representation stores a nullable normalized date
anchor beside an explicit `exact`, `month`, `year`, `decade`, `approximate` or
`unknown` precision. API payloads expose the precision and the matching
human-entered value; `unknown` has no value. `is_deceased` remains an
independent fact, so a deceased Person may correctly have unknown death
information.

`people` and the narrowly-scoped `person_detail_proposals` table are ordinary
ADR-0005 Class C tenant-owned tables with forced, fail-closed PostgreSQL RLS.
Owner and Administrator writes are authoritative. Member-created People are
provisional and later changes are stored as bounded proposals which an Owner
or Administrator may discover, approve or reject; approval locks and
re-validates the current Person before applying the proposed details. Person
creation, authoritative identity/deceased changes and proposal transitions
produce tenant-aware AuditEvents. Owner, Administrator and Member have
whole-record directory access; Contributor and Guest do not.

The React surface uses the immutable Person ULID beneath the Family Space slug,
tenant-aware TanStack Query keys and typed feature-local API functions. It
provides directory loading/empty/error states, record creation, direct
authoritative editing, Member proposals and Owner/Administrator proposal
review without calling the shared transport from pages or components.

### Documentation updates

- Recorded the normalized-date-plus-precision representation and bounded
  proposal mechanics selected for S02.
- Advanced `tasks.json` to `FPA-P04-S03` after S02 verification completed.

### Commit boundary

```text
Implement Family Space Person records
```

## FPA-P04-S03 — Link accounts to people

Implement one-to-one User-to-Person linking per Family Space, Member and
reachable-Contributor self-claim proposals, and Owner/Administrator
approval, correction and removal, per ADR-0006 §§5-6 and §16.

The authoritative association is stored separately from its review history.
`person_account_links` enforces one linked account per Person and one linked
Person per account within each Family Space with database uniqueness
constraints. `person_account_claims` records pending, approved and rejected
self-claims; partial uniqueness prevents one account or Person from
participating in several simultaneous pending claims. Both are ordinary
ADR-0005 Class C tables with explicit Family Space ownership and forced,
fail-closed RLS.

Member may submit a self-claim from a visible Person page. Contributor retains
the ADR's future permission ceiling where a later resource grant legitimately
exposes a claim flow, but a known or guessed Person ULID is not reachability and
S03 does not create a Contributor directory or speculative resource-grant
flow. Contributor and Guest therefore have no current S03 claim route. Owner
and Administrator can discover,
approve or reject pending claims and can directly establish, correct or remove
links using an active tenant-scoped membership. Approval rechecks active
membership and both cardinality constraints transactionally. Direct assignment
rejects superseded pending claims rather than leaving unresolvable work behind.

Established links are archival identity metadata: membership removal and
account revocation do not change them. Only an explicit audited
Owner/Administrator correction or unlink does. The frontend adds typed
feature-local link APIs, tenant-aware TanStack Query keys and role-dependent
self-claim, review and account-selection controls to the existing Person page.

### Documentation updates

- Recorded the separate authoritative-link and self-claim representation and
  its database cardinality guarantees.
- Advanced `tasks.json` to `FPA-P04-S04` after S03 verification completed.

### Commit boundary

```text
Link Family Space accounts to People
```

## FPA-P04-S04 — Implement relationships and family circles

Implement the canonical relationship edge with derived inverse wording,
the Member-proposed / Owner-Administrator-confirmed relationship model
with proposal- and approval-time validation, and People-only,
presentation-only Family Circles, per ADR-0006 §§7-9.

Relationships are stored as one tenant-owned canonical edge. Directed types
retain their subject/related direction and derive inverse labels at read time;
symmetric types normalize the two Person ULIDs before persistence and never
create a mirrored row. The fixed additive vocabulary is centralized in one
enum. Direct writes lock both People in deterministic order and reject
self-relationships, duplicates, direct inverse directed-type cycles and defined
same-pair contradictions.

Owner and Administrator may create, replace, dispute and remove authoritative
edges. Member uses a separate pending proposal record for create, replace,
dispute and remove actions. Proposed records are excluded from authoritative
relationship reads, and approval locks and revalidates against current People
and relationship state before applying the action. Proposal, resolution,
dispute and direct-authoritative actions have distinct AuditEvents.

Family Circles are flat, Family-Space-owned records with a People-only join
table. Owner, Administrator and Member may manage them. Circle membership is
used only for presentation; no authorization policy, tenant resolution or RLS
decision consults Circle data. Relationship, proposal, Circle and Circle-Person
tables use ADR-0005's forced, fail-closed Class C RLS pattern.

The frontend keeps the new endpoints in typed People-feature API modules and
uses tenant-aware TanStack Query hooks for all server state. Person pages show
perspective-correct relationship wording and role-appropriate authoritative or
proposal controls; the People page exposes the minimal Circle presentation
workflow.

### Documentation updates

- Recorded the canonical-edge, review, validation and flat-Circle
  implementation boundaries.
- Advanced `tasks.json` to `FPA-P04-S05` after S04 verification completed.

### Commit boundary

```text
Implement relationships and Family Circles
```

## FPA-P04-S05 — Implement person merge and correction workflow

Implement Person merge via a redirect/tombstone record, atomic
reconciliation of Phase 4-owned relationships, circles and links,
structured merge provenance, and realistically bounded reversal, per
ADR-0006 §12.

FPA-P04-S05 completed on 2026-08-06. An absorbed Person is soft-deleted but
retains its immutable ULID; detail requests for that ULID resolve to the
surviving Person and expose the redirect source, while directory listings omit
the tombstone. Merge execution locks both People, captures an authoritative
before/after ledger and atomically reconciles Phase-4-owned relationships,
relationship proposals, Circle memberships and account links. Exact duplicate
edges and memberships collapse deliberately, self-edges are removed, and
incompatible relationship or dual-account-link cases fail unless the Owner or
Administrator supplies an explicit safe resolution.

Members may submit bounded duplicate proposals; Owners and Administrators may
approve, reject, merge directly and request reversal. Automatic reversal is
allowed only while the current Phase 4 state still matches the recorded
post-merge state and the original account links remain restorable. Otherwise
the merge is marked `manual_correction_required`, the refusal is audited and no
partial restoration occurs. Structured merge provenance remains separate from
ordinary AuditEvents, which record proposal, merge, reversal and manual
correction outcomes.

The merge ledger and proposal tables use ADR-0005's forced Class C RLS pattern.
Every later domain that introduces a Person reference must explicitly integrate
that reference into merge reconciliation and reversal-safety evaluation.
The frontend uses a typed People-feature API module, tenant-aware TanStack Query
hooks and role-appropriate proposal, merge, review and reversal controls.

### Documentation updates

- Recorded the tombstone, reconciliation, provenance and bounded-reversal
  implementation boundaries.
- Advanced `tasks.json` to Phase 5's ADR-acceptance stage after the complete
  Phase 4 verification gate passed.

### Commit boundary

```text
Implement Person merge and correction workflow
```

### Phase verification

- Deceased people receive full archive pages.
- Relative labels can be presented from the current person's perspective.
- Cyclic or contradictory relationships are detected where practical.
- Person deletion does not erase historical provenance silently.

Phase 4 completed on 2026-08-06 after its full repository, PostgreSQL 17.6,
persistent-migration and rebuilt-stack acceptance gate passed.

---

# Phase 5 — Media Storage and Upload Pipeline

## FPA-P05-S01 — Accept media storage and upload pipeline ADR

### Objective

Decide the Phase 5/6 media boundary, direct-upload authority, storage-key
lifecycle, original preservation and validation guarantees, deterministic
processing ownership, metadata privacy, variant identity, secure delivery,
role baseline, auditing and cleanup in ADR-0007 before implementation begins.

### Engineering rationale

Upload authority, byte immutability, validation, canonical generation,
metadata exposure and delivery are one trust chain. Deciding that chain once
prevents later stages from treating an arrived object as trusted, deriving a
security-sensitive path from client claims, confusing a presentation asset
with the archival original, or introducing a Phase 6 Photo dependency early.

### Prerequisites

- FPA-P04-S05 complete.

### Expected changes

- ADR-0007 accepted.

### Verification

- ADR-0007 covers the MediaUpload/MediaVariant ownership boundary; bounded
  extension-independent staging authority; server-detected archival and
  quarantine keys; write-once-equivalent finalisation; the explicit upload
  state machine; server-computed SHA-256 integrity; required validation and
  configurable malware scanning; JPEG, PNG, HEIC/HEIF, WebP and TIFF support;
  complete original metadata preservation; presentation-safe canonical and
  variant assets; Laravel processing ownership; tenant-aware asynchronous
  work; independent bulk-upload semantics; short-lived authorised delivery;
  Contributor/Guest deferral; truthful audit events; and cleanup including
  ADR-0005 tenant teardown.

ADR-0007 was accepted 2026-08-10. Phase 5 owns Family-Space-local
`MediaUpload` and `MediaVariant` infrastructure while Phase 6 later introduces
`Photo` and references a ready upload in that direction only. A browser receives
bounded authority for a server-generated, tenant-scoped,
extension-independent staging key. Laravel independently verifies the arrived
object, detects its real format and finalises verified or quarantined bytes
under the corresponding hierarchy without allowing stale, reused or racing
upload authority to replace a preserved original. Database idempotency and
object-byte immutability are separate required guarantees.

The accepted lifecycle distinguishes initiated, uploaded-but-untrusted,
verifying, preserved, processing, ready, quarantined, abandoned and degraded
states. Preservation freezes a server-computed SHA-256 over the exact original
bytes. The original retains its complete EXIF, GPS, ICC profile and encoding;
ordinary APIs, UI responses, canonical assets and variants withhold
privacy-sensitive metadata, while an authorised Owner, Administrator or Member
download deliberately returns the untouched original and may therefore expose
its embedded metadata. Laravel records `original_download_authorised` when it
issues the signed URL and does not claim to have observed the later
object-storage GET.

Laravel owns deterministic validation and processing on the existing tenant
job envelope. Malware scanning remains a required configurable validation
stage. JPEG, PNG, HEIC/HEIF, WebP and TIFF originals are accepted; canonical
assets are oriented, sRGB and metadata-stripped; thumbnail, card and display
variants are versioned and regenerable. Bulk upload is independent per file.
Contributor upload is intentionally absent from Phase 5 because no Album or
Event resource exists to scope it to; this is a Phase 6/7 architectural
placeholder, not a permanent prohibition. No ordinary Phase 5 operation may
delete a preserved original; ADR-0005's idempotent Family Space teardown is the
sole explicit tenant-deletion exception. The 24-hour abandoned and 7-day
quarantine defaults remain accepted.

This stage's entire scope was accepting the ADR, so the ADR-acceptance commit
is the stage-completion commit and receives the `phase-5-s01` tag directly.

### Documentation updates

- Accepted ADR-0007 after its bounded consistency reconciliation.
- Advanced `tasks.json` to `FPA-P05-S02` after FPA-P05-S01 completed.

### Commit boundary

```text
Accept ADR-0007: Media storage and upload pipeline
```

## FPA-P05-S02 — Implement upload initiation and completion

Use idempotency keys and explicit upload states. Upload authority targets a
tenant-scoped, extension-independent staging key; select and verify the
concrete bounded-authority and write-once boundary without trusting client
filename, MIME type or extension data.

FPA-P05-S02 uses a server-generated `MediaUpload` ULID and the staging key
`families/{family_space_id}/media-staging/{media_upload_id}/original`. A
15-minute presigned S3-compatible PUT is scoped to that one key and signs the
`If-None-Match: *` condition, so neither a reused nor a racing authority can
replace bytes already accepted at the staging key. The browser-facing signing
endpoint is configured separately from Laravel's internal object-storage
endpoint; local bucket CORS permits the configured web origin to send the
conditional PUT. The default plausible-size ceiling at completion is 100 MiB
and remains configurable.

Initiation is idempotent per Family Space, uploader and `Idempotency-Key`;
reusing a key with a different request fingerprint is rejected. Client filename
and claimed MIME type are retained only as non-authoritative metadata and do
not affect the key. Completion independently inspects the staged object, then
conditionally advances `initiated` to `uploaded`; repeated completion is a
no-op success. The upload row retains the existing tenant operation context so
FPA-P05-S03 can dispatch validation without reconstructing tenant, correlation
or trace identity. No validation, malware scanning, format-derived archival
key or processing transition is introduced in this stage.

The React flow lives in the `media-uploads` feature. Its typed API module owns
initiation, the direct storage PUT, envelope unwrapping and completion; its
TanStack mutation hook owns server-state mutation; and the page only composes
the accessible file-selection and result states.

### Verification

- API tests cover role authorization, tenant/account isolation, idempotent
  initiation, conflicting key reuse, extension-independent keys, audit context,
  missing/empty/oversized objects and duplicate completion.
- Signing tests require the conditional header to be part of the signed
  authority. The LocalStack regression uses one authority twice, requires the
  second PUT to fail with precondition status, and verifies the first bytes are
  unchanged.
- PostgreSQL integration verifies the `media_uploads` Class C RLS boundary.
- Frontend tests cover the typed initiate/direct-PUT/complete sequence, storage
  rejection, accessible success and error presentation.

### Commit boundary

```text
Implement media upload initiation and completion
```

## FPA-P05-S03 — Validate and preserve originals

Verify type, size and checksum; preserve original bytes; quarantine invalid
files. Finalise verified or quarantined bytes from staging with an extension
derived only from the server-detected format. Select the concrete
copy/move/finalisation API and prove that reused or racing upload authority
cannot replace an already preserved original.

Completion dispatches one unique, tenant-context-carrying Laravel validation
job per `MediaUpload`. The job conditionally claims `uploaded` as `verifying`,
re-downloads the staging object, and treats every filename, claimed MIME type
and client extension as provenance only. Replayed validation against a terminal
state is a no-op.

The validation pipeline applies the configured byte ceiling; detects JPEG,
PNG, HEIC, HEIF, WebP or TIFF from file signatures; rejects AVIF and unknown
formats; performs a bounded ImageMagick header probe and full decode; enforces
a configurable 100-million-pixel default; computes SHA-256 over the exact
staged bytes; and submits those bytes to the replaceable `MalwareScanner`
boundary. The deployed local implementation is ClamAV over its streaming TCP
protocol. It fails closed on infection, timeout, an unavailable scanner or an
unrecognised scan result. Decoder execution defaults to a 20-second timeout
with explicit memory, map and disk limits; the ClamAV scan timeout defaults to
30 seconds. The API image explicitly installs the JPEG, PNG, HEIC/HEIF, WebP
and TIFF decoder support used by the accepted allowlist.

Successful validation conditionally writes the untouched bytes to
`families/{family_space_id}/media/{media_upload_id}/original.{detected_ext}`
with their SHA-256 as object metadata. The signed server write includes
`If-None-Match: *`; an existing object is idempotent only when both byte length
and checksum match, and otherwise raises an immutable-object collision without
overwriting it. The database then freezes the original key, detected MIME type,
byte size and SHA-256 at `preserved`, records
`media_upload.original_accepted`, and removes the staging object.

A validation failure is finalised with the same write-once rule under
`families/{family_space_id}/quarantine/{media_upload_id}/original.{detected_ext}`
or `.bin` when no supported format was detected. The upload records a bounded
machine-readable rejection reason, records
`media_upload.original_quarantined`, and removes the staging object only after
the quarantine object and database transition are durable. There is no
canonical generation, metadata extraction, presentation variant or Photo
domain work in this stage.

An hourly scheduler command discovers quarantine objects older than the
configurable seven-day default through a narrowly executable PostgreSQL
function, then dispatches unique tenant-aware purge jobs. Purging deletes only
the quarantine object and clears its object key idempotently; the terminal
`MediaUpload` rejection row and its existing audit event remain durable. This
does not introduce the S07 abandoned-upload sweep or any preserved-original
deletion path.

### Verification

- Feature tests cover every accepted signature and detected extension,
  misleading client metadata, explicit AVIF/unknown rejection, decoder-invalid
  quarantine, malware detection, scanner unavailability and timeout,
  checksum stability, replay idempotency and immutable-final-key collision.
- Real LocalStack tests prove identical finalisation is idempotent while
  different bytes cannot replace an existing archival object.
- The media-validation smoke test proves all accepted ImageMagick coders can
  write and decode a real sample, and proves the deployed ClamAV service accepts
  clean input and rejects the EICAR test signature.
- PostgreSQL integration re-runs all migrations and Class C tenant-isolation
  checks with the quarantine-key schema present, and proves cross-tenant due
  discovery is unavailable to `PUBLIC` while executable by the runtime role.

### Documentation updates

- Recorded the selected decoder, malware scanner, failure posture, limits,
  write-once finalisation and quarantine mechanics.
- Advanced `tasks.json` to `FPA-P05-S04` after FPA-P05-S03 completed.

### Commit boundary

```text
Validate and preserve uploaded originals
```

## FPA-P05-S04 — Extract metadata and generate canonical assets

Apply orientation correctly and preserve original EXIF separately.

Successful S03 preservation dispatches a unique tenant-context-carrying
canonical job keyed by `MediaUpload` and the frozen original SHA-256. The job
only claims the `preserved` state, re-downloads the immutable original and
recomputes its checksum before processing. A stale source identity, terminal
state or replay after canonical completion is a no-op; unexpected integrity
failure stops processing without replacing either source or canonical bytes.

The replaceable metadata boundary is implemented with ExifTool in the Laravel
worker. It extracts pixel width and height, original orientation, camera make
and model, the EXIF capture timestamp, GPS coordinates, the exact raw EXIF
profile and the exact embedded ICC profile. Raw binary profiles are stored
losslessly as base64 text because PostgreSQL text-protocol binding truncates
unencoded NUL-bearing profile bytes. Each decoded profile is bounded to a
configurable 32 MiB default. The EXIF timestamp remains its technical string,
including an offset only when the file supplied one; it is not converted into
or exposed as a Phase 6 historical date.

Normalized GPS coordinates and the lossless raw profiles remain tenant-owned
database fields and are absent from every ordinary Phase 5 response. The
preserved original itself remains byte-for-byte unchanged. This retains the
complete source metadata while keeping GPS and other private metadata outside
ordinary presentation.

The replaceable canonical-generation boundary uses bounded ImageMagick in the
Laravel job. It selects the first image frame, applies EXIF orientation,
normalizes to sRGB and strips all metadata and profiles. An opaque source
produces a quality-90 progressive JPEG by default; meaningful alpha produces a
PNG so transparency is not flattened. The full-resolution canonical is written
conditionally to
`families/{family_space_id}/media/{media_upload_id}/canonical.{jpg|png}` with
its SHA-256 as object metadata. Existing identical output is idempotent and a
different object at that key is never overwritten. Once the database freezes
the canonical key, MIME type and checksum together with the private technical
metadata, the upload advances from `preserved` to `processing` for S05.

### Verification

- Feature tests cover source-checksum identity, tenant context, lossless
  NUL-bearing EXIF/ICC representation, private GPS fields, canonical
  finalisation, state transition, replay safety and immutable-key collision.
- Ordinary upload responses explicitly exclude GPS and raw profile fields.
- Real container tests prove orientation is applied, sRGB output is produced,
  EXIF/GPS are stripped, the original checksum is unchanged, regeneration is
  deterministic, meaningful alpha selects PNG, and HEIC, HEIF and TIFF all
  produce browser-presentable canonical assets.
- PostgreSQL 17.6 integration proves private metadata remains inside the
  existing forced Class C tenant boundary and binary profiles round-trip
  losslessly through their base64 representation.

No thumbnail, card or display variant is introduced here; those remain the
complete scope of FPA-P05-S05.

### Documentation updates

- Recorded the selected metadata/canonical tools, private-field representation,
  processing limits, format selection and `preserved`-to-`processing` boundary.
- Advanced `tasks.json` to `FPA-P05-S05` after FPA-P05-S04 completed.

### Commit boundary

```text
Extract media metadata and generate canonical assets
```

## FPA-P05-S05 — Generate presentation variants

Generate ADR-0007's fixed presentation-variant set from the canonical asset
through a unique tenant-aware Laravel job. The V1 transform vocabulary is
centralised and intentionally limited to:

- `thumbnail`: 320 by 320 pixels, centre-cropped;
- `card`: 768 by 512 pixels, centre-cropped;
- `display`: contained within 2048 by 2048 pixels without enlargement.

All three transforms produce sRGB WebP at quality 82 with embedded metadata
stripped. This preserves meaningful transparency while giving each transform
name one implied format and geometry. V1 does not introduce a responsive
`srcset` family or AVIF. Every variant is stored under
`families/{family_space_id}/media/{media_upload_id}/variants/{transform}.v{processing_version}.webp`
and identified durably by `(media_upload_id, transform_name,
processing_version)`. The initial processing version is `1`; a later transform
change increments the version instead of replacing an earlier identity.

Canonical completion dispatches the variant job with the existing
`TenantOperationContext`, canonical SHA-256 and processing version. The job is
unique for that source/version identity, re-verifies the downloaded canonical
checksum, uses write-once-equivalent object finalisation and reconciles rows
under a conditional state check. Completing all three variants moves the upload
from `processing` to `ready`. Exhausting job retries moves only the matching
`processing` upload to `degraded`; the preserved original and canonical remain
untouched. A ready upload may be safely re-dispatched to reconstruct deleted
disposable variant objects without adding duplicate rows.

### Verification

- Feature coverage proves the fixed set, versioned identity, duplicate-safe
  persistence, stale-source rejection, write-once collision behaviour,
  regeneration after variant deletion and the bounded degraded transition.
- Real ImageMagick coverage proves exact crop/contain geometry, sRGB WebP
  output, metadata stripping and deterministic regeneration.
- PostgreSQL 17.6 coverage proves `media_variants` uses the forced Class C
  Family-Space tenant boundary.

### Boundaries

No delivery URL or media-view authorization is added here; that remains
FPA-P05-S06. No bulk-upload presentation, recovery endpoint, degraded retry
surface, frontend flow or Phase 6 `Photo` record is introduced.

### Documentation updates

- Recorded the fixed V1 transform vocabulary, encoding, versioned key layout,
  queued lifecycle and regeneration behaviour.
- Advanced `tasks.json` to `FPA-P05-S06` after FPA-P05-S05 completed.

### Commit boundary

```text
Generate presentation media variants
```

## FPA-P05-S06 — Secure media delivery

Expose three authenticated Family-Space-scoped authority endpoints for a
`MediaUpload`: canonical viewing, one fixed current-version variant by transform
name, and preserved-original download. The endpoints never proxy bytes or
accept an object key from the client. Laravel resolves the tenant-owned upload
and variant, applies `MediaUploadPolicy`, then returns a signed GET URL scoped
to exactly the server-selected key.

Owner, Administrator and Member receive the ADR-0007 baseline for all three
delivery types. Contributor and Guest receive no default access. Canonical and
variant authority is available only after the upload reaches `ready`; original
authority is available once preservation succeeds and remains available during
`preserved`, `processing`, `ready` and `degraded`. Cross-tenant identifiers,
unknown transforms, missing current-version variants and unavailable assets
fail closed.

Delivery authority lasts five minutes by default and is capped at fifteen
minutes even if configuration requests a longer interval. Each URL is an S3
Signature V4 GET for one key. The verified database MIME type is signed into the
response, and the signature leaves the `Range` header available so clients can
request byte ranges. Object storage explicitly blocks all public ACLs and
policies, validates presigned signatures, and permits browser GET/HEAD CORS with
the required range-response headers. No stable unauthenticated URL or prefix
grant is introduced.

Canonical and variant authority is deliberately not written to `AuditEvent`.
After original access has passed policy and URL signing succeeds, Laravel writes
the exact `original_download_authorised` action before returning the URL. The
event records the expiry but neither the URL nor an object key. Signing failure
writes no audit event, and no `original_downloaded` action is emitted because
Laravel cannot observe the subsequent storage GET. Ordinary API payloads expose
no EXIF, GPS, ICC profile or other preserved private metadata; an authorised
original GET still deliberately receives the untouched archival bytes.

### Verification

- Feature coverage proves Owner/Administrator/Member access, Contributor/Guest
  denial, unauthenticated and cross-tenant denial, ready-state enforcement,
  fixed-transform lookup, bounded expiry, clean response fields and truthful
  original-authority auditing.
- Real LocalStack coverage proves private-bucket controls, delivery CORS,
  signature validation, key scoping, response MIME type and byte-range GETs.

### Boundaries

No frontend viewing surface or Phase 6 `Photo` route is introduced. S06 does
not add bulk upload, abandoned/degraded recovery, tenant media teardown or a
standalone media-delete operation; those remain FPA-P05-S07 or Phase 6.

### Documentation updates

- Recorded the delivery endpoints, role/state baseline, bounded signing,
  storage controls, MIME/range behaviour and truthful audit boundary.
- Advanced `tasks.json` to `FPA-P05-S07` after FPA-P05-S06 completed.

### Commit boundary

```text
Secure media delivery
```

## FPA-P05-S07 — Add upload recovery and bulk upload

Support retries, partial failure reporting and duplicate-safe client behaviour.
Extend ADR-0005's idempotent Family Space teardown to remove the tenant's media
objects and Phase-5-owned rows without introducing a standalone media-delete
operation.

Degraded processing recovery is explicit and source-bound. A canonical job
that exhausts its retries may move only the matching preserved source into
`degraded`; the uploader, or an Owner/Administrator when the uploader is no
longer able to act, may then redispatch canonical generation from the frozen
original SHA-256, or variant generation from the frozen canonical SHA-256 and
configured processing version. The state change is claimed under a row lock
before dispatch, so repeated recovery requests do not enqueue duplicate work
and never replace or mutate the preserved original.

Bulk upload remains presentation-only grouping. The browser assigns one ULID
`upload_batch_id` and a stable idempotency key to each selected file, then runs
the ordinary per-file initiation, direct PUT and completion flow independently.
One failed file does not roll back another. A tenant- and uploader-scoped batch
status endpoint reports per-file state and aggregate counts. Members see only
their own batch, while Owner and Administrator may monitor tenant batches and
recover work orphaned by uploader access loss; rejection reasons remain limited
to Owner and Administrator responses. The touched React upload
feature keeps endpoint knowledge in its typed feature API module, mutation and
polling state in TanStack Query hooks, and accessible progress, partial-failure
and retry presentation in the page/component layer.

An hourly scheduler discovers expired `initiated` uploads through a narrowly
executable PostgreSQL function. The default cutoff is 24 hours. Each unique,
tenant-context-bearing cleanup job claims the row as `abandoned` before it
deletes the untrusted staging object, records `staging_deleted_at` only after
successful deletion, and remains discoverable after a storage failure so the
cleanup can be retried safely. Uploaded or otherwise progressed work is never
abandoned by the sweep.

ADR-0005 Family Space teardown now deletes only that tenant's `media-staging`,
`media` and `quarantine` object prefixes before deleting its Phase-5-owned
`MediaUpload` rows; `MediaVariant` rows follow their existing cascade. Both
storage and row removal are idempotent, including retry after a storage
failure. This is the already-authorised tenant-deletion exception only: S07
does not add a standalone media-delete endpoint or any Phase 6 `Photo`
deletion/restoration behaviour.

### Verification

- Feature tests cover source-bound canonical and variant recovery, repeated
  retry safety, uploader/role authorization, independent batch progress and
  privacy, abandoned cleanup and retry, and idempotent tenant teardown.
- Frontend tests cover multi-file grouping, per-file partial failure, stable
  duplicate-safe retry input, batch status loading and accessible progress.
- PostgreSQL integration proves cross-tenant abandoned-work discovery and its
  revoked-public/runtime-only execution boundary.
- Real LocalStack coverage proves tenant-prefix teardown is bounded and
  idempotent while preserving another Family Space's objects.

### Phase verification

- Uploading the same completion event twice is safe.
- HEIC and required mobile formats are tested.
- Original checksums remain stable.
- Reused or racing upload authority cannot replace a preserved original.
- Variants can be deleted and regenerated.
- Unauthorised media access fails.

### Documentation updates

- Recorded the source-bound recovery, presentation-only batching, 24-hour
  abandoned sweep and tenant-teardown implementation boundaries.
- Completed FPA-P05-S07 and Phase 5 in `tasks.json`, advancing the current
  stage to FPA-P06-S01 without starting Phase 6 or drafting ADR-0008.

### Commit boundary

```text
Complete media upload recovery and bulk workflows
```

---

# Phase 6 — Photo Domain, Provenance and Organisation

## FPA-P06-S01 — Accept photo domain, provenance and organisation ADR

### Objective

Decide the Photo/MediaUpload reference, provenance and historical-date
semantics, the Photo/Album visibility model, Contributor's first
resource-scoped grant, the Phase 5 delivery non-bypass guarantee, and
deletion/restoration authority in ADR-0008 before implementation begins.

### Engineering rationale

Provenance, visibility and deletion authority are one connected trust
model, not independent features. Deciding them together now prevents a
later stage from inventing its own ad hoc rule for "who can see this" or
"who can edit this," silently reopening the Phase 5 delivery boundary
without the non-bypass guarantee this ADR requires, or conflating the
Photo's creator with its uploader.

### Prerequisites

- FPA-P05-S07 complete.

### Expected changes

- ADR-0008 accepted.

### Verification

- ADR-0008 covers the required unique one-directional Photo/MediaUpload
  reference, the created_by/uploader distinction, and who may create a
  Photo (Member from their own upload only, Owner/Administrator from any
  upload, Contributor only via a scoped Album contribution); the
  identity-bearing photographer/scanner/physical-owner provenance claims
  under ADR-0006's proposed/authoritative pattern, kept structurally
  separate from the ordinary archive_source_description field; the reused
  uncertain-date concept and the new human-supplied-location concept, both
  kept strictly separate from EXIF/GPS technical metadata; PhotoPerson
  under the same proposed/authoritative pattern with a non-overwrite
  boundary against future machine recognition; free-text, Family-Space-
  scoped Photo tags with no approval workflow; the two-value Photo
  visibility model (defaulting to family_space, except a Contributor's
  scoped contribution which defaults to private) with Album-only
  selected-audience sharing and explicit, reversible visibility widening;
  Album/AlbumGrant as Contributor's first resource-scoped grant, including
  the explicit Private/Selected visibility distinction, the
  can_contribute-implies-can_view invariant, membership-reactivation
  continuity, and fixed default Member contribution on family_space-
  visibility Albums; the Phase 5 delivery non-bypass guarantee — beginning
  in FPA-P06-S02 for intrinsic visibility, extended in FPA-P06-S04 for
  Album access — with preserved-original download carved out as
  intrinsic-visibility-only; reversible soft deletion and restoration
  authority with no permanent-deletion path; and the Phase 7/8/9/10/11
  non-goals this ADR explicitly declines to build.

ADR-0008 was accepted 2026-08-24, then amended the same day after a
pre-implementation reconciliation review surfaced genuine ambiguities
ahead of FPA-P06-S02 — a bounded clarification pass, not a redesign; no
prior decision was reversed. `Photo.media_upload_id` is required and
unique, one-directional to `MediaUpload`, and at most one `MediaUpload`
backs one `Photo`; a `ready` `MediaUpload` may exist indefinitely without
becoming a Photo. `Photo.created_by` identifies the Family Space member
who created the Photo record and is intentionally distinct from and
never derived from `MediaUpload.user_id`; every creator-based authority
rule in this ADR (visibility, Album widening, deletion/restoration) reads
`created_by`, not the uploader. A Member may create a Photo only from
their own ready MediaUpload; Owner/Administrator may promote any ready
MediaUpload in the Family Space. A Contributor holds no Photo-creation
authority at all — they introduce new Photos only by uploading through an
Album for which they hold `AlbumGrant.can_contribute`, and the system
creates the resulting Photo as part of that upload, never the Contributor
directly.

Photographer, scanner and original physical owner are single-valued,
identity-bearing provenance claims, each a nullable `Person` reference or
a mutually exclusive free-text fallback, following ADR-0006's
Member-proposes/Owner-or-Administrator-confirms pattern unmodified. The
physical container or collection a photo came from (e.g. "Green family
album," "Box labelled Spain") is a **separate, ordinary**
`archive_source_description` field requiring no approval workflow — split
explicitly from the identity-bearing physical-owner claim it was
originally conflated with. Caption, description and archive source
description are all ordinary content. The historical date reuses
ADR-0006's uncertain-date concept unmodified and must never be silently
populated or confirmed from `MediaUpload`'s EXIF capture timestamp. A new,
equally lightweight human-supplied location field follows the same
proposed/authoritative pattern and stays strictly separate from
`MediaUpload`'s GPS coordinates — no GIS, coordinates, or reverse
geocoding. `PhotoPerson` uses the same proposed/authoritative pattern;
only confirmed rows are authoritative human ground truth, and future
machine-recognition output (Phase 9/10) may never overwrite them directly.
Photo tags are free-text, Family-Space-scoped, ordinary Member-editable
labels with no approval workflow, no taxonomy and no hierarchy — not a
substitute for Albums.

`Photo.visibility` has exactly two values, `family_space` (the default)
and `private` (the default only for a Photo created via a Contributor's
scoped Album contribution); no separate restricted tier or Photo-level
grant table exists. Selected-audience sharing lives entirely in
`Album`/`AlbumGrant` (`can_view`/`can_contribute` per membership, with
`can_contribute` always implying `can_view`). A **private** Album has no
`AlbumGrant` rows at all (creator/Owner/Administrator only); a
**selected** Album's audience is governed entirely by its `AlbumGrant`
rows — the two tiers are explicitly distinct, not interchangeable. An
existing `AlbumGrant` remains attached automatically when ADR-0005's
in-place membership reactivation reinstates a removed member. Adding a
private Photo to a broader Album is an explicit, authorized, UI-visible,
tested visibility-widening operation; removing it from every widening
Album automatically and immediately restores exactly its intrinsic
visibility, computed from live state rather than a cached grant. Default
`family_space`-visibility Album contribution is fixed product policy:
Owner, Administrator, the Album's creator, and ordinary Member all
contribute by default; Contributor contributes — and uploads — only
through an explicit `AlbumGrant`; Guest never contributes — this is
Contributor's first concrete resource-scoped grant, closing the
placeholder ADR-0005 opened and ADR-0007 deferred. `AlbumGrant` governs
presentation access only (canonical asset and variants); it never confers
preserved-original download, which continues to follow ADR-0007 §16's
existing role policy evaluated against the Photo's own intrinsic
visibility. Once a `MediaUpload` is attached to a Photo, the existing
Phase 5 delivery endpoints (`MediaUploadPolicy`) must become Photo-aware
for intrinsic visibility **starting in FPA-P06-S02** — not deferred to
Album work — so that the `MediaUpload` ULID alone can never bypass a
private Photo even before any Album exists; FPA-P06-S04 then extends the
same check to consult `AlbumGrant` for presentation-only widening. This is
fixed as security-critical.

Photo deletion is reversible soft deletion only, and Phase 6 introduces no
permanent-deletion path of any kind. Owner or Administrator may
soft-delete or restore any Photo; the Photo's creator may do the same to
their own Photo while they retain Family Space access. No Member action
can permanently destroy preserved media, and Phase 5's assets and
associated Album/story/comment/reaction rows are unaffected by and
retained across a soft delete. `PhotoStory` and `PhotoComment` are
author-editable/removable with Owner/Administrator retaining moderation
authority regardless of author; reactions use a small fixed vocabulary
with explicit no-ranking/no-engagement-feed guarantees. Dynamic views are
query-only, spanning date, location and tag metadata alongside Photo and
PhotoPerson; no persisted view entity exists. Duplicate linkage (Phase 8),
Event/Event-Album structure (Phase 7), machine face data (Phase 9/10),
persisted dynamic views (Phase 11), GIS/coordinate location, and tag
taxonomy/hierarchy are explicitly out of scope.

This stage's entire scope was accepting the ADR, so the ADR-acceptance
commit is the stage-completion commit and receives the `phase-6-s01` tag
directly. The same-day reconciliation amendment was folded into that
already-accepted ADR text rather than treated as a second stage, since no
implementation work had yet begun against the ambiguous version.

### Documentation updates

- Accepted ADR-0008.
- Advanced `tasks.json` to `FPA-P06-S02` after FPA-P06-S01 completed.

### Commit boundary

```text
Accept ADR-0008: Photo domain, provenance and organisation
```

## FPA-P06-S02 — Implement photo and provenance records

Record uploader, photographer, scanner, archive source and original owner
independently. `Photo.media_upload_id` is required and unique;
`Photo.created_by` is distinct from and never derived from
`MediaUpload.user_id`. A Member may create a Photo only from their own
ready MediaUpload; Owner/Administrator may promote any ready MediaUpload;
a Contributor's scoped exception is completed in S04 once `AlbumGrant`
exists. Photographer/scanner/physical-owner claims are Person-or-free-text
identity-bearing provenance and follow the propose/confirm pattern.
`archive_source_description` is a **separate, ordinary** field (e.g.
"Green family album," "Box labelled Spain") requiring no approval
workflow — do not reuse the physical-owner free-text column for it.
Caption and description likewise require no approval workflow. Implement
`Tag`/`PhotoTag` (Family-Space-scoped, free-text, ordinary
Member-editable, no taxonomy or hierarchy). Establish `Photo.visibility`
(`family_space`/`private`, defaulting to `family_space`) as a column here.

**S02 must also extend `MediaUploadPolicy`** so that, as soon as a
`MediaUpload` is attached to a Photo, `Photo.visibility` alone is
authoritative for canonical, variant, and original delivery — do not
defer this to S04. This closes the security gap that would otherwise
exist between this stage (which introduces `Photo.visibility`) and the
stage that introduces Albums.

Photo, provenance-proposal, Family-Space tag and Photo/tag-link records are
tenant-isolated Class C data. Photo creation promotes exactly one ready
MediaUpload: a Member may promote only their own upload, while Owner and
Administrator may promote any ready upload in the Family Space. The Photo's
creator remains independent of its uploader. Identity-bearing photographer,
scanner and original-physical-owner claims use pending Member proposals and
immediate or resolving Owner/Administrator confirmation; caption, description,
archive source and free-text tags remain ordinary editable metadata.

Intrinsic Photo visibility is enforced through all existing canonical,
variant and preserved-original MediaUpload delivery routes as soon as the
upload is attached to a Photo. Person merge reconciliation rewires every
authoritative and proposed Person provenance reference, records the exact
pre-merge state and restores it on a permitted reversal. Family Space teardown
removes the new records before the underlying media rows. The touched React
surface keeps endpoint knowledge in a typed `photos` feature API, server state
in TanStack Query hooks and accessible list, detail, editing, tag and
provenance presentation in page/components.

### Verification

- Feature tests cover creation authority, readiness and uniqueness, intrinsic
  private visibility across list/detail and every Phase 5 delivery route,
  proposal/confirmation, source-versus-owner separation, tags, content editing
  and cross-tenant denial.
- Merge and tenant-deletion regressions cover Photo-owned references, exact
  reversal and complete teardown.
- Frontend tests cover API ownership, list/create states, visibility, tags,
  detail presentation and provenance submission.
- PostgreSQL 17.6 integration proves forced Class C isolation for all four new
  tables.

### Documentation updates

- Recorded the Photo/provenance/tag persistence, delivery non-bypass, merge and
  teardown boundaries.
- Completed FPA-P06-S02 and advanced `tasks.json` to `FPA-P06-S03`.

### Commit boundary

```text
Implement photo and provenance records
```

## FPA-P06-S03 — Implement family metadata and approximate dates

Support exact, month, year, decade and approximate values without
inventing precision, reusing ADR-0006's uncertain-date concept unmodified
and keeping it strictly separate from `MediaUpload`'s EXIF capture
timestamp. Implement a human-supplied location field with the same
propose/confirm pattern, strictly separate from `MediaUpload`'s GPS
coordinates — free text only, no GIS, coordinates or reverse geocoding.
Implement `PhotoPerson` under the same propose/confirm pattern; only
confirmed rows are authoritative, and the table must remain structurally
separate from any future machine-recognition output.

Photo historical dates store the shared `DatePrecision` and normalized date
value used by Person records, including an explicit unknown value with no
invented date. Human-supplied location is an independent free-text claim. Both
use retained metadata proposals: Member submissions remain pending while Owner
and Administrator submissions or resolutions update the authoritative Photo
fields. No implementation path reads EXIF capture time or GPS to pre-populate
either value.

`PhotoPerson` retains pending, approved and rejected human associations. Only
approved rows are loaded into Photo payloads and the authoritative graph. The
service hard-codes ordinary submissions as human proposals and exposes no
machine-confirmation path, establishing the non-overwrite boundary Phase 9/10
must inherit. Active duplicates are prevented; Person merge reconciliation
collapses conflicting active associations deterministically and exact reversal
restores the prior Person and resolution states.

### Verification

- Feature tests cover every uncertain-date precision, invalid precision/value
  combinations, Member proposal and administrative confirmation, location,
  confirmed-only PhotoPerson presentation, rejected-history retention and
  cross-tenant denial.
- Regressions prove historical date and location remain independent of stored
  EXIF/GPS values, Person merge handles conflicting associations, reversal
  restores exact state and Family Space teardown removes the new rows.
- Frontend tests cover typed endpoint ownership, metadata/Person submission and
  authoritative display.
- PostgreSQL 17.6 integration proves forced Class C isolation for both new
  proposal tables.

### Documentation updates

- Recorded the uncertain-date reuse, EXIF/GPS separation, confirmed-only human
  graph and machine-recognition non-overwrite boundary.
- Completed FPA-P06-S03 and advanced `tasks.json` to `FPA-P06-S04`.

### Commit boundary

```text
Implement family metadata and approximate dates
```

## FPA-P06-S04 — Implement albums and dynamic views

Albums are explicit collections; generated views query shared metadata.
Implement `Album`, `AlbumPhoto` and `AlbumGrant` (`can_view`/
`can_contribute`, with `can_contribute` always implying `can_view` —
reject or make unrepresentable any grant with `can_contribute = true` and
`can_view = false`). Implement the explicit Private (zero grants) versus
Selected (grant-governed) visibility distinction, and rely on ADR-0005's
in-place membership reactivation for `AlbumGrant` continuity with no extra
reconciliation step. Default `family_space`-visibility contribution is
fixed for Owner/Administrator/creator/Member; Contributor contributes, and
introduces new Photos, only by uploading through an Album for which they
hold `AlbumGrant.can_contribute` — never through direct Photo-creation
authority. The system creates the resulting Photo already attached to
that Album, defaulting to `private` visibility, not `family_space`.

Implement §7's Photo-visibility-widening authorization, UI signal and
automatic-narrowing-on-removal behaviour. **Extend** S02's already
Photo-aware `MediaUploadPolicy` to additionally consult `AlbumGrant` for
presentation-only access (canonical asset and variants) — never original
download, which continues to follow ADR-0007's role policy evaluated
against the Photo's own intrinsic visibility regardless of any Album
grant. This completes ADR-0008's security-critical delivery non-bypass
requirement that S02 began.

### Implementation record — 2026-08-24

Implemented tenant-isolated `Album`, ordered `AlbumPhoto` and membership-bound
`AlbumGrant` records with forced PostgreSQL row-level security. Private Albums
hold no grants, Selected Albums use explicit live grants, and Family Space
Albums retain the fixed Member contribution rule. Membership reactivation
preserves grant identity because grants reference the membership row.

Contributor upload authority is available only through a currently
contributable Album. Successful processing creates exactly one private Photo
already attached to that Album; revoked authority fails closed. Existing
private Photos require an authorised, explicit widening confirmation before
joining a broader Album, while removal narrows live reachability without
mutating intrinsic Photo visibility.

Canonical and variant delivery now recognises visible Album membership.
Preserved-original delivery deliberately ignores Album grants and continues to
apply the Photo's intrinsic visibility and ADR-0007 role boundary. Query-only
Photo filters cover confirmed Person associations, tags, human location,
historical year and missing confirmed dates without persisting view records.

The React work follows the frontend engineering standard: typed Album and
Photo feature API modules own endpoints and envelope handling, TanStack Query
owns server state, and pages contain no direct shared-client calls or raw
fetching effects. The Album UI exposes scoped upload and an explicit widening
signal.

### Verification

- Regression tests cover Private/Selected grant invariants, live grant
  revocation, widening confirmation, automatic narrowing, Contributor scoped
  upload finalisation and idempotency, and the preserved-original denial.
- Frontend tests cover Contributor controls, the absence of Album creation for
  Contributors, and explicit widening confirmation.
- The complete application suite, PostgreSQL 17.6 forced-RLS suite, contracts,
  security, formatting, linting and type checks pass.

### Documentation updates

- Completed FPA-P06-S04 and advanced `tasks.json` to `FPA-P06-S05`.

### Commit boundary

```text
Implement albums and dynamic photo views
```

## FPA-P06-S05 — Implement stories, comments and reactions

Add edit history and moderation boundaries appropriate for a private
family environment. `PhotoStory` and `PhotoComment` are author-editable/
removable; Owner/Administrator retain moderation removal authority
regardless of author. Reactions use a small fixed vocabulary with no
ranking, engagement feed, or memories/search weighting.

### Implementation record — 2026-08-24

Implemented attributed `PhotoStory` and `PhotoComment` records as distinct
ordinary-content types. Authors alone may edit their text; each edit stores the
prior body in a numbered revision row. Authors may remove their own content,
while Owner and Administrator may moderate removal regardless of authorship.
Removal is soft so ordinary UI removal does not erase the content or its
correction trail. Owner/Administrator removal of another author's content is
audited as moderation; author self-removal is ordinary activity and does not
emit that moderation event.

Each user may hold at most one reaction per Photo. The fixed V1 vocabulary is
`love`, `smile`, `laugh` and `remember`, enforced by request validation and a
PostgreSQL constraint. Reactions are returned only as lightweight expressions;
no ranking, score, feed, search weighting or memories weighting surface exists.

All five S05 tables are Class C tenant content with forced PostgreSQL RLS. The
React Photo conversation panel uses a typed feature API module and TanStack
Query for all reads, mutations and cache reconciliation.

### Verification

- API tests cover author editing, immutable revision snapshots, author and
  moderator removal boundaries, soft removal/audit, fixed reaction validation,
  one-reaction replacement and cross-tenant/private denial.
- Frontend tests cover separate story/comment presentation, editing and the
  fixed reaction controls through the typed mutation boundary.
- The complete API, frontend and PostgreSQL 17.6 forced-RLS suites pass.

### Documentation updates

- Completed FPA-P06-S05 and advanced `tasks.json` to `FPA-P06-S06`.

### Commit boundary

```text
Implement photo stories comments and reactions
```

## FPA-P06-S06 — Implement photo deletion and restoration

Use reversible soft deletion, restoration, and derivative-preserving
cleanup jobs. **Phase 6 introduces no permanent-deletion path** — do not
add one under this stage; permanent destruction remains governed
exclusively by ADR-0005's Family Space teardown and any later Phase 13
retention architecture. Deletion/restoration authority is evaluated
against `Photo.created_by`, never `MediaUpload.user_id`. Associated
Album/story/comment/reaction rows are retained, not deleted, across a soft
delete, so restoration is a pure metadata reversal. Re-verify that the
delivery non-bypass check (S02's intrinsic-visibility layer, extended in
S04) also respects a soft-deleted Photo's presentation state.

### Phase verification

- One photo can belong to several albums.
- Provenance remains intact after edits.
- The identity-bearing physical-owner claim and the ordinary archive
  source description are stored and edited independently of each other.
- Date and location sorting/filtering handle uncertain or free-text values
  consistently.
- Photo restoration re-establishes valid asset references.
- A MediaUpload ULID cannot bypass a private Photo through the existing
  Phase 5 delivery endpoints immediately after S02, before any Album
  exists.
- Adding/removing a Photo from a widening Album correctly widens/restores
  its reachability without mutating `Photo.visibility` itself.
- No `AlbumGrant` can exist with `can_contribute = true` and `can_view =
  false`.
- Album-derived (grant-only) access never authorizes preserved-original
  download for a role that would not otherwise qualify under ADR-0007.
- No Phase 6 code path permanently destroys a Photo or its underlying
  media.

### Implementation record (2026-08-24)

- Added tenant-isolated reversible Photo soft deletion and restoration,
  with authority evaluated against `Photo.created_by`: Owner and
  Administrator may manage every Photo, while a creator may manage their
  own Photo only while they retain active non-Guest Family Space access.
- Retained `AlbumPhoto`, `PhotoStory`, `PhotoComment`, `PhotoReaction`,
  `MediaUpload` and `MediaVariant` records across the tombstone lifecycle;
  restoration therefore re-establishes the existing associations without
  reconstructing or mutating them.
- Excluded deleted Photos from list, detail and Album presentation and
  denied canonical, variant and preserved-original delivery while the
  Photo is deleted. The MediaUpload relationship deliberately includes
  soft-deleted Photos so a tombstone cannot be mistaken for an unattached
  Phase 5 upload.
- Kept permanent destruction exclusive to ADR-0005 Family Space teardown,
  where already-deleted and live Photos are force-deleted idempotently as
  part of tenant destruction; no standalone permanent Photo or media
  deletion operation was added.
- Added typed feature-local TanStack Query deletion/restoration operations,
  a recently removed Photo surface and focused API/frontend regression
  coverage for retention, delivery denial, restoration and the
  creator-versus-uploader authority boundary.
- Applied the persistent migration and completed the full repository,
  frontend, API, PostgreSQL 17.6 RLS, contracts and security gates.

### Documentation updates

- Completed FPA-P06-S06 and Phase 6, then advanced `tasks.json` to the
  ADR-only FPA-P07-S01 boundary without drafting or implementing Phase 7.

### Commit boundary

```text
Implement reversible photo deletion and restoration
```

---

# Phase 7 — Events and Collaborative Sharing

## FPA-P07-S01 — Accept events and collaborative sharing ADR (ADR-0009)

### Objective

Decide the Event resource, Album/Event and Photo/primary-Event relationships,
derived attendance, Event admission lifecycle, Guest trust boundary,
contribution and delivery permissions, and reversible Event lifecycle before
implementation begins.

### Engineering rationale

Events introduce the first legitimate Guest access path. Event discovery,
admission, Album participation, media delivery and conversational authority
therefore form one security boundary and must be fixed together rather than
being inferred independently by later stages.

### Prerequisites

- FPA-P06-S06 complete, including the accepted Phase 6 Guest-denial correction.

### Expected changes

- ADR-0009 accepted.

### Verification

- Event creation, editing, discovery, removal and restoration authority is
  explicit, with no Event visibility enum.
- `Album.event_id` and `Photo.primary_event_id` remain single, nullable and
  authorization-inert until the Guest-access stage.
- Attendance derives only from confirmed `PhotoPerson` records.
- Event-invitation acceptance reuses active memberships without role mutation;
  Event admission is unique, revocable, re-admittable and evaluated live.
- Guest access composes a valid admission with Album participation or an
  Event-scoped grant, while non-Event Guest access remains denied.
- Original download, comment/reaction, Story-authoring and Event soft-deletion
  boundaries are explicit and covered by required bypass tests.

ADR-0009 was accepted on 2026-08-25 after two pre-implementation blocker
reviews. The reconciliations clarified existing decisions without changing the
Event/Album/Guest architecture. This stage's entire scope was accepting the
ADR, so the acceptance commit is the stage-completion commit and receives the
`phase-7-s01` tag directly.

### Documentation updates

- Accepted ADR-0009.
- Advanced `tasks.json` to FPA-P07-S02.

### Commit boundary

```text
Accept ADR-0009: Events and collaborative sharing
```

## FPA-P07-S02 — Implement events and event albums

### Objective

Introduce the tenant-owned Event resource and its authorization-inert Album and
Photo organisational references, together with derived attendance and a
human-reviewed duplicate-candidate surface.

### Implementation boundary

- Add `events` with ULID identity, Family Space ownership, creator, name,
  optional description/date range/location and presentation-only status
  (`planned`, `active`, `completed`, `archived`). Enforce tenant RLS, status and
  date-range constraints in PostgreSQL.
- Owner, Administrator and Member may create Events. Owner and Administrator
  may edit any Event; a Member may edit an Event they created. Contributor and
  Guest receive no Event discovery or mutation path in this stage.
- Add nullable `Album.event_id` and `Photo.primary_event_id`. Each reference may
  identify only an Event in the same Family Space and has no authorization
  effect in S02. Albums remain independently reachable resources.
- Derive attendance at read time from distinct, confirmed `PhotoPerson`
  associations on Photos reached through an Event Album or the Photo's explicit
  primary Event. Do not persist attendance or infer it from upload activity.
- Expose Event lists/details, Event Albums, derived attendees, Person-to-Event
  reverse lookup and advisory duplicate candidates through typed feature API
  modules and TanStack Query hooks.

### Duplicate-candidate heuristic

Candidate selection is deterministic, Family-Space-scoped and advisory only. A
different Event is suggested when either its name is an exact match after
Unicode-aware lowercasing, trimming and collapsing internal whitespace, or its
start date is within seven calendar days and its non-empty location is an exact
match after the same normalization. Suggestions never merge, delete, re-parent
or otherwise mutate either Event automatically.

### Verification

- Feature tests cover role and creator authorization, cross-tenant reference
  rejection, both attendance derivation paths, confirmed-only deduplication,
  Person reverse lookup and deterministic advisory duplicate suggestions.
- PostgreSQL verification covers Event RLS and database constraints.
- Frontend type, lint, component/API tests and production build remain clean.

### Commit boundary

```text
Implement events and event albums
```

## FPA-P07-S03 — Implement event contributions

### Objective

Confirm that Event Albums use the existing Album contribution model without a
parallel Event upload workflow.

### Engineering rationale

ADR-0009 §13 deliberately keeps Member and Contributor contribution governed by
ADR-0008's existing `AlbumPolicy::contribute` rules. `Album.event_id` is an
organisational reference only in this stage, so the established
`MediaUpload.target_album_id` initiation, completion and per-file finalisation
path must behave identically for Event-linked and ordinary Albums.

### Prerequisites

- FPA-P07-S02 is complete.
- ADR-0009 is accepted.

### Expected changes

- Add focused backend regression coverage proving ordinary Member contribution
  and grant-scoped Contributor upload/finalisation on an Event Album.
- Add frontend coverage proving an Event-linked Album exposes the existing
  scoped contribution controls to an authorised Contributor.
- Do not add `guest_participation`, Event admissions, Guest upload authority or
  any alternate upload endpoint; those belong to FPA-P07-S04.

### Commands

Use the existing Album endpoints and `MediaUpload.target_album_id` workflow. No
production-code branch is required for Event Albums because the S02 Event
reference is intentionally authorization-inert.

### Verification

- Focused API and frontend tests cover Event Album contribution.
- Repository format, lint, type, application-test and security checks remain
  clean.

### Risks and edge cases

- Event linkage must never weaken or replace the existing Album contribution
  policy.
- Contributor uploads still require a live `AlbumGrant.can_contribute` and
  create one private Photo attached to the target Album.
- Guest access remains denied until FPA-P07-S04 introduces the complete
  admission and participation model.

### Documentation updates

- Mark FPA-P07-S03 complete in `tasks.json` with verification evidence.
- Record the bounded implementation in the session journal.

### Commit boundary

```text
Implement event contributions
```

## FPA-P07-S04 — Implement restricted guest upload links

### Objective

Implement ADR-0009's authenticated, Event-scoped Guest boundary without adding
anonymous uploads or widening ordinary Family Space access.

### Engineering rationale

A Guest membership is an authenticated identity, not a general Family Space
grant. Authority is evaluated live from an active Guest membership, a valid
`EventAdmission`, and either the Event Album's `guest_participation` or an
individual Event-scoped `AlbumGrant`. The Event page is the Guest's only
navigation root.

### Prerequisites

- FPA-P07-S03 complete.
- ADR-0009 accepted.

### Expected changes

- Add `Album.guest_participation` (`none`, `view`, `contribute`), nullable
  `Invitation.event_id`, and the tenant-owned `event_admissions` table with one
  reusable row per Event and membership.
- Use `EVENT_ADMISSION_LIFETIME_DAYS` with a 30-day default. Compute validity
  live as `now < admitted_at + lifetime`; do not persist an expiry state or run
  an expiry sweep.
- Scope ordinary pending invitations by Family Space and email, and Event
  invitations by Family Space, email and Event. Event acceptance creates or
  reactivates a Guest membership when needed, reuses an active membership
  without changing its role, and admits that membership to the Event.
- Provide Owner/Administrator admission, revocation and re-admission endpoints.
  Re-admission reuses the row, clears revocation and starts a fresh validity
  window. Admission transitions are audited.
- Exclude Guest rows from ordinary Family Space discovery and membership
  presentation. Deny Guests every existing general Family Space, membership,
  People, relationship, invitation, Album, Photo and Event enumeration path.
- Permit a Guest to reach only a specifically admitted, non-deleted Event and
  Albums/Photos allowed by `guest_participation` or an Event-scoped individual
  grant. A non-Event grant remains ineffective.
- Reuse the existing Album-targeted upload workflow for `contribute`; do not add
  a second upload endpoint. Guest uploads create private Photos and preserve
  uploader and source provenance.
- Permit comments and reactions for visible Event Photos, while authorising
  `PhotoStory` creation separately and denying it to Guests.
- Permit preserved-original download only through `view` or `contribute`
  participation plus a currently valid admission. An individual grant alone
  permits presentation access but not original download.
- Add reversible Owner/Administrator Event removal and restoration. Removal
  immediately disables Guest access without deleting admissions, Albums,
  Photos or references; restoration resumes any still-valid access.
- Add typed feature API functions and TanStack Query hooks for Event admission,
  Event detail and scoped Album detail. The Guest UI navigates only from the
  admitted Event to its Albums and Photos.

### Verification

- Prove direct-identifier, cross-Event and non-Event access fails closed across
  Event, Album, Photo and media delivery paths.
- Prove live expiry, configuration changes, revocation, idempotent revocation,
  re-admission, Event removal and restoration without an expiry job.
- Prove active Owner, Administrator, Member and Contributor roles are never
  changed by Event invitation acceptance; prove two Event invitations can be
  accepted in either order while reusing one membership.
- Prove only Owner/Administrator manages admissions, Guests do not appear in
  family membership surfaces, and Guests may comment/react but not author a
  Story.
- Prove both participation levels allow original download, while an individual
  grant at `none` does not.
- Run the full API, PostgreSQL RLS, frontend, formatting, lint, type, security,
  documentation and foundation gates.

### Commit boundary

```text
Implement restricted Event guest access
```

## FPA-P07-S05 — Implement event notifications and exports

### Objective

Notify the relevant Event cohort when a new contribution becomes available and
provide a short-lived, curated Event archive containing preserved originals and
a useful machine-readable metadata manifest.

### Notification boundary

- Dispatch one queued email notification when a newly uploaded Photo is first
  finalised into an Event Album. Processing retries and idempotent finalisation
  must not send duplicates; comments, reactions, metadata edits and attaching
  an existing Photo do not generate Phase 7 notifications.
- Persist one tenant-scoped delivery row per Event, Photo and recipient. Send
  recipients individually and mark each row after successful delivery so a job
  retry resumes only the unsent remainder of a partially delivered cohort.
- Recipients are the Event creator, active Owner/Administrator memberships and
  memberships with a currently valid Event admission. Deduplicate recipients,
  exclude the uploader and exclude removed memberships, revoked accounts and
  expired or revoked admissions. Admission validity is evaluated live using the
  same configured lifetime as authorization.
- The message identifies the Event and contributing user and links to the Event
  page. It does not attach media or disclose wider Family Space content.
- Notification preferences, digests, in-application notifications and
  comment/reaction notifications remain later product work.

### Curated archive boundary

- Only an active Owner or Administrator may request, inspect or download an
  Event archive. Member, Contributor and Guest are denied even where they may
  view individual Event Albums; an archive combines content across all of the
  Event's Albums and therefore uses the narrow management authority.
- Build the archive asynchronously. Persist one `EventExport` row per request
  with `pending`, `processing`, `ready`, `failed` or `expired` state and a
  server-generated tenant/Event-scoped private object key. A request never
  grants access to any other Event or Family Space.
- Give generation a bounded 15-minute worker timeout and a per-export overlap
  lock; the queue visibility timeout must exceed that bound so a large archive
  cannot be processed concurrently after premature message redelivery.
- The concrete format is a ZIP archive containing `manifest.json` plus one
  untouched preserved original per distinct, non-deleted Photo reached through
  an Event Album or `Photo.primary_event_id`. A Photo reached through both paths
  appears once. Archive entry names are server-generated from the Photo ULID and
  the detected format; client filenames are metadata only.
- The UTF-8 JSON manifest is schema version 1 and records Event identity and
  descriptive fields, generation time, requester identity and, per Photo,
  Photo/media identifiers, archive entry, original filename, detected MIME
  type, byte size, SHA-256, uploader and creator identities, caption,
  description, historical date, location, tags and confirmed provenance
  fields. Missing or checksum-invalid preserved originals fail the entire
  archive rather than producing an apparently complete partial export.
- A ready archive expires 24 hours after generation. Download authorization is
  rechecked on every request and issues a five-minute signed URL. An hourly,
  idempotent cleanup removes expired archive objects and marks their rows
  `expired`; ordinary media objects are never touched. Family Space teardown
  also removes Event exports through the existing tenant-prefix cleanup.
- Audit archive request, successful generation, failure, download authorization
  and expiry cleanup. Do not claim that Laravel observed the signed object-store
  GET itself.

### Frontend boundary

- Extend the typed Event API module, Event query-key factory and TanStack Query
  hooks with archive request/status/list operations.
- Show archive controls only where the API reports export-management authority.
  The page consumes hooks and never calls the shared HTTP client directly or
  fetches server state through `useEffect`.

### Phase verification

- Guest access cannot enumerate family members or unrelated photos.
- Expired event links stop working.
- Contributions retain uploader and source provenance.
- Event exports include originals and a metadata manifest.
- Notification recipient selection excludes the uploader and every stale,
  revoked or unrelated admission, and processing retries do not duplicate mail.
- Archive authorization, tenant isolation, distinct Photo selection, manifest
  content, original checksum validation, signed-download authorization and
  expiry cleanup are covered by feature and PostgreSQL tests.
- Run the full API, PostgreSQL RLS, frontend, formatting, lint, type, security,
  contracts, documentation and foundation gates, plus a production web build.

### Documentation updates

- Mark FPA-P07-S05 and Phase 7 complete in `tasks.json` only after the full
  verification gate passes.
- Record the bounded implementation in the session journal.

### Commit boundary

```text
Implement Event notifications and exports
```

---

# Phase 8 — Exact and Visual Duplicate Detection

## FPA-P08-S01 — Accept duplicate-detection ADR (ADR-0010)

### Objective

Resolve duplicate definitions and the explicit duplicate-decision model
before implementation. ADR-0010 fixes no consolidation, merge, redirect
or aggregation mechanism of any kind — duplicate handling is the
three-outcome choice (use existing Photo, create a new independent
Photo, cancel) and nothing else. Perceptual-similarity algorithm and
threshold selection are explicitly deferred to an empirical calibration
gate immediately before FPA-P08-S03, not fixed by the ADR.

### Verification

- Exact matching uses the existing frozen SHA-256 and never crosses a Family
  Space or discloses a Photo the actor cannot already view.
- The three-outcome flow maps onto direct Photo creation and asynchronous
  Album/Event contribution without changing existing promotion authority.
- Existing Photo conversations are preserved honestly while new comments and
  reactions become Album-scoped.
- Duplicate decisions are durable, auditable, suppress automatic rediscovery
  and may be explicitly reopened by Owner or Administrator.
- Retrospective exact matching belongs to S02; perceptual hashing and its
  empirical calibration gate belong to S03.

ADR-0010 was accepted on 2026-08-26 after bounded pre-implementation
reconciliation. The reconciliation made the settled product model buildable
against the live repository without changing its three-outcome flow, strict
one-to-one MediaUpload/Photo cardinality, no-consolidation rule or Phase 8
stage boundaries. This stage's entire scope was accepting the ADR, so the
acceptance commit is the stage-completion commit and receives the
`phase-8-s01` tag directly.

### Documentation updates

- Accepted ADR-0010.
- Reconciled the Phase 8 roadmap and implementation-stage wording.
- Advanced `tasks.json` to FPA-P08-S02.

### Commit boundary

```text
Accept ADR-0010: Duplicate detection
```

## FPA-P08-S02 — Implement exact duplicate detection

Use the existing frozen `MediaUpload.original_sha256` (ADR-0007 §6) and
family-scoped comparison; introduce no new checksum mechanism. Only
checksum matches visible to the current actor are disclosed; when more
than one visible Photo matches, all are shown and the actor chooses —
no canonical winner is invented.

Implement the three-outcome choice on both real creation paths: direct
Photo creation (synchronous — respond with visible candidates instead of
creating anything when a match exists and no decision was supplied) and
Album/Event contribution (asynchronous — `AlbumContributionFinalizer`
must pause on a visible match rather than auto-creating the Photo,
recording a small `MediaUploadDuplicateHold` resolvable only by the
original uploader using their existing contribution authority; this is
not a new `MediaUpload` lifecycle state).

Re-scope `PhotoComment`/`PhotoReaction` to `(photo_id, album_id)`: add a
nullable `album_id` column, leave every existing row untouched
(`album_id = NULL`), and require every newly created row to set it.
Legacy rows are exposed only on the Photo's own direct page, read-only,
never inside an Album view. Removing a Photo from an Album must not
delete its conversation for that Album — no cascade on `AlbumPhoto`
removal, so re-adding the Photo restores it automatically.

Implement `DuplicateDecision` (canonical, unordered `photo_low_id`/
`photo_high_id` pair identity) so a pair the family has already resolved
— by choosing "create a new Photo" despite a known match — is never
re-surfaced. Choosing "create a new Photo" against more than one visible
match writes one `DuplicateDecision` for every disclosed match, in the
same transaction, idempotently — never against a match that was not
disclosed on that decision screen, and never a single arbitrary pick.

**Stage ownership is exact-only.** S02 owns interactive SHA-256 exact
duplicate detection, visibility-filtered exact-match disclosure, the
three-outcome duplicate decision flow, `DuplicateDecision`
creation/suppression for exact matches, and `MediaUploadDuplicateHold`
handling for the asynchronous Album/Event contribution path. S02 also
owns **retrospective/backfill exact duplicate candidate generation** —
finding checksum matches between Photos that already exist or were
created concurrently, outside the interactive check above. This
backfill is idempotent and consults `DuplicateDecision` first so an
already-settled pair is never regenerated as a candidate. It is exact-
checksum work and does not wait on S03's calibration gate below. S03
owns perceptual candidate generation only, and nothing about exact
matching.

### Implementation record (2026-08-26)

- Added Family-Space-scoped exact matching from the existing frozen original
  SHA-256, filtering interactive disclosures through the actor's live Photo
  visibility and preserving ADR-0008's existing promotion authority.
- Implemented the three explicit outcomes for direct Photo creation and the
  asynchronous Album/Event contribution finalizer. The latter records a
  separate uploader-owned hold without changing `MediaUpload.state` and
  rechecks current contribution authority at resolution time.
- Made multi-match decisions deterministic under races by carrying the exact
  disclosed Photo identifiers into the resolving request, revalidating them,
  and writing one canonical `DuplicateDecision` per disclosed pair in the same
  transaction as creation. Matches that appear later are not treated as if the
  actor had already reviewed them.
- Added idempotent retrospective exact-candidate generation with settled-
  decision suppression; no perceptual hash, algorithm or threshold was added.
- Re-scoped new comments and reactions to `(photo_id, album_id)`, retained
  legacy null-Album rows as direct-page-only read-only history, and retained
  Album conversation rows across AlbumPhoto removal and re-addition.
- Added typed feature-local API modules, TanStack Query hooks and explicit
  direct/held duplicate-choice UI, including the existing private-Photo
  visibility-widening confirmation.
- Applied the migration to persistent PostgreSQL as batch 30 and verified the
  three new tables under forced tenant RLS. The complete API, frontend,
  PostgreSQL 17.6, documentation, contract, security and repository gates pass.

FPA-P08-S02 was accepted on 2026-08-26. FPA-P08-S03 has not begun; its
empirical algorithm/threshold calibration gate remains the next-stage
prerequisite.

### Documentation updates

- Completed FPA-P08-S02 and advanced `tasks.json` to FPA-P08-S03.

### Commit boundary

```text
Implement exact duplicate detection
```

## FPA-P08-S03 — Implement perceptual similarity analysis

**Before this stage begins**, complete the calibration gate: choose a
candidate algorithm, test it against representative fambam images,
measure false positives and false negatives, and document the selected
threshold in this guide. This stage implements the calibrated choice; it
does not select it.

### Calibration record (2026-08-26)

The gate was completed against 40 private canonical assets: the 38-image
representative upload set supplied for this stage plus two existing canonical
copies. The labelled evaluation contained 18 related pairs (format conversion,
recompression, grayscale treatment, resizing/cropping and byte-identical
copies) and 762 unrelated pairs. Private image bytes were used only in the
local calibration environment and are not repository fixtures.

ImageMagick's normalised channel PHASH was rejected: the threshold required to
retain both crop examples produced 291 false positives, while a conservative
threshold produced four false negatives. The selected algorithm is
`dhash-luma-64`, processing version `1`: auto-orient the canonical asset,
convert it to grayscale, resize it to exactly 9 by 8 pixels, and record the 64
horizontal adjacent-pixel comparisons. Similarity is the Hamming distance
between two hashes; a perceptual `DuplicateCandidate` is generated when the
distance is **18 or lower**. On the labelled corpus the hardest related pair
measured 18 and the nearest unrelated pair measured 19, giving 18 true
positives, 0 false negatives, 0 false positives and 762 true negatives at the
selected threshold. This is an advisory candidate threshold, not proof that
two Photos are duplicates; later recalibration requires a new processing
version rather than reinterpretation of stored version-1 hashes. Treat the
version-1 cutoff as provisional to this calibration corpus and recalibrate
before relying on it for materially different sources, including old scans,
new export pipelines, substantially different resolution/compression, or new
image-processing paths.

Generate versioned perceptual hashes `(media_upload_id, algorithm,
processing_version)` from the canonical asset (ADR-0007 §9), as a
Laravel job (ADR-0007 §12) — not a Python/ML inference call. Perceptual
matches generate `DuplicateCandidate` rows exactly like exact matches,
consulting `DuplicateDecision` first so an already-settled pair is never
regenerated as a candidate. S03 owns perceptual candidate generation
only; it never duplicates or extends S02's exact-match work.

### Implementation record (2026-08-26)

- Implemented calibrated `dhash-luma-64` version 1 generation from canonical
  assets through a deterministic ImageMagick-backed Laravel worker, storing
  one tenant-owned versioned hash per MediaUpload.
- Added PostgreSQL discovery for eligible Photos missing the current hash and
  scheduled idempotent dispatch every ten minutes; the queue job revalidates
  tenant, Photo, ready-upload and canonical-checksum state before hashing.
- Added Family-Space-scoped Hamming comparison at the calibrated distance of
  18 or lower. Candidate generation skips exact-checksum pairs, soft-deleted
  Photos and currently settled DuplicateDecisions, and writes idempotent
  advisory `DuplicateCandidate` rows with algorithm, version and score.
- Added deterministic hash/distance, threshold-boundary, idempotency,
  settled-decision, exact-pair, stale/deleted-Photo, tenant-isolation, RLS and
  Family Space teardown coverage. Persistent PostgreSQL migration batch 31
  was applied and the existing Photo backfill completed; a second discovery
  run dispatched zero jobs.
- The complete API, frontend, Python, PostgreSQL 17.6, real ImageMagick,
  infrastructure, documentation, contract, formatting, lint, type, security,
  JSON and diff gates pass. Private calibration copies were removed from the
  host and local containers after measurement; no family image is a repository
  fixture.

### Documentation updates

- Recorded the completed calibration and selected version-1 threshold.
- Completed FPA-P08-S03 and advanced `tasks.json` to FPA-P08-S04.

### Commit boundary

```text
Implement perceptual duplicate detection
```

## FPA-P08-S04 — Implement duplicate review

Never delete, merge or consolidate automatically. The review surface is
exactly two actions — ignore (leave `pending`) or dismiss (`not a
duplicate`, writing a `DuplicateDecision`) — with no third action. There
is no consolidation to preserve associations against, because Phase 8
never reassigns, redirects or aggregates a Photo's own metadata, Album
membership, stories, comments or reactions; §7's Album-scoped
conversation and the ordinary Photo/Album tools already established in
Phase 6/7 are what a family uses if they want to change anything about
an existing Photo.

An Owner or Administrator may **reopen** a previously settled
`DuplicateDecision`: an explicit, audited state transition on the same
row (`reopened_by`/`reopened_at`), never a delete-and-recreate and never
automatic. Reopening makes the pair eligible for review again; it does
not itself change anything about the Photos involved, and ordinary
Photo soft-delete/restore never reopens a decision on its own.

### Implementation record (2026-08-26)

- Added an Owner/Administrator-only, archive-wide review API and typed
  TanStack Query surface showing both canonical assets and the source, version
  and score behind each advisory suggestion.
- Implemented exactly the two approved review outcomes: leave a candidate
  pending for later, or dismiss it as not a duplicate. Dismissal settles the
  canonical pair, dismisses every pending source for that pair and records the
  candidate and durable-decision audit events; it never changes either Photo.
- Added the explicit audited Owner/Administrator reopen action on the same
  `DuplicateDecision` row. Reopening creates no candidate itself, while a
  later natural exact or perceptual run may re-pend the existing candidate.
- Added Member (and manager) flagging between two Photos the actor can already
  view. Contributor and Guest remain excluded, and a settled pair cannot be
  flagged again unless first reopened.
- Applied the frontend engineering standard in every touched file: endpoint
  paths and envelope mapping live in the typed duplicate API module, query
  keys and server state live in TanStack Query hooks, and pages/components
  contain no direct transport calls or data-fetching effects.
- Added authorization, visibility, pair canonicalisation, audit, duplicate-
  source collapse, dismissal, reopening/regeneration, no-consolidation,
  endpoint ownership and accessible two-action UI tests.

### Documentation updates

- Completed FPA-P08-S04 and Phase 8; advanced `tasks.json` to FPA-P09-S01.

### Post-completion review closeout (2026-08-27)

- Added real PostgreSQL proof that the checksum advisory lock serializes
  concurrent reverse-order decision creation into one canonical
  `DuplicateDecision`, and that PostgreSQL rejects a reversed pair.
- Added explicit Guest Event/Album hold-resolution coverage for uploader
  ownership, live contribution-authority rechecking, all three choices and
  continued denial of general Photo-creation authority.
- Added asynchronous visibility-before-existence proof for an invisible exact
  match and full settled-decision soft-delete/restore/reopen regression proof.
- Normalized new comment and reaction writes so missing/null `album_id` fails
  validation consistently, while legacy null-Album rows remain unchanged.
- Verified the accepted reopening behavior without changing it: reopening
  removes suppression but creates no candidate itself; only later natural
  detection may re-pend the pair.
- Assigned substantive CI suite enforcement to FPA-P17-S03 without changing
  the current foundation-only workflow.

### Commit boundary

```text
Implement duplicate review
```

### Phase verification

- Exact duplicate detection is deterministic and family-scoped.
- A checksum match invisible to the current actor behaves as no match.
- Both creation paths (direct, and Album/Event contribution) offer the
  same three outcomes; the asynchronous path's pending hold is
  resolvable only by the original uploader.
- The same Photo added to two different Albums carries independent,
  empty-until-used comment/reaction threads; legacy Photo-scoped
  conversation is preserved, never fabricated into an Album, never
  duplicated, and never destroyed by removing a Photo from an Album.
- A dismissed or resolved-as-separate pair is never presented again,
  including after either Photo is soft-deleted and restored.
- Choosing "create a new Photo" against multiple disclosed matches
  writes one `DuplicateDecision` per disclosed match and nothing against
  an undisclosed match; repeating the same choice is idempotent.
- Retrospective/backfill exact-match candidate generation is owned by
  S02, is idempotent, and never regenerates a pair already settled by
  `DuplicateDecision`.
- Similar candidates can be dismissed; no third action exists.
- Reopening a settled `DuplicateDecision` is restricted to Owner/
  Administrator, is recorded as an audited state transition on the same
  row, and is never triggered automatically or by Photo soft-delete/
  restore.

---

# Phase 9 — Local Face Analysis Foundation

## FPA-P09-S01 — Build representative local benchmark

Create a private, non-repository benchmark manifest covering recent photos, age variation, groups, scans, profiles, siblings and poor-quality images.

## FPA-P09-S02 — Accept local face analysis foundation ADR

Resolve provider abstraction, initial local model and licensing, analysis data model and versioning, inference deployment and service identity, and the biometric-data threat model, in one ADR (ADR-0011). ADR-0011 names `buffalo_l` (InsightFace, ONNX Runtime) as the intended initial candidate and records the current private/non-commercial licensing assumption and commercialisation review trigger; direct confirmation of the pinned package/weight licence text and the weight checksum remains S04's job. Do not implement a pretrained model until code and model-weight licensing are recorded within it.

ADR-0011 was accepted on 2026-08-27 after two adversarial
implementation-readiness reviews reconciled its signing audience, result
transport and bounds, run/attempt identity, stale-message and DLQ semantics,
tenant-consistent relationships, teardown coverage, licensing gate and stage
ownership against the live repository. This stage's entire scope was accepting
the ADR, so the acceptance commit is the stage-completion commit and receives
the `phase-9-s02` tag directly.

### Documentation updates

- Accepted ADR-0011.
- Reconciled the Phase 9 implementation-stage boundaries.
- Advanced `tasks.json` to FPA-P09-S03.

### Commit boundary

```text
Accept ADR-0011: Local face analysis foundation
```

## FPA-P09-S03 — Implement face-analysis provider contract

Provider and transport **contracts only** — not the full operational pipeline. Owns the Fambam-owned provider interface and result types; the `ImageAnalysisRequested`/`Completed`/`Failed` wire/event schemas, including `contract_version`; the result-artifact schema and its safety bounds (size, face-count, embedding dimension/dtype, landmark scheme, geometry and numeric validity), enforced against conservative placeholder defaults; the `FaceAnalysisRun`/`FaceAnalysisAttempt`/`FaceObservation` schema, including the tenant-consistent composite foreign keys; analysis identity/version semantics; and the signing-audience extension to the existing delivery/upload-authorization abstractions. The application contract should express detection and embedding capabilities without leaking one provider's schema. Does **not** require production-shaped queue infrastructure or calibrated queue/timeout settings.

### Objective

Establish the complete versioned provider/transport contract and tenant-safe
persistence foundation required by ADR-0011 without loading a model, running
inference or building the operational SQS pipeline.

### Implementation

- Added five language-neutral JSON Schema 2020-12 documents for the shared
  face-analysis types, requested/completed/failed messages and bounded result
  artifact. A repository contract checker verifies required schemas, unique
  identifiers and local references.
- Added strict, frozen Pydantic models and a directly callable
  `FaceAnalysisProvider` protocol. Unknown fields, malformed hashes/ULIDs,
  non-finite values and embedding-length disagreement fail closed without
  coupling the interface to InsightFace or an HTTP route.
- Added `FaceAnalysisRun`, `FaceAnalysisAttempt` and `FaceObservation` models
  and migration batch 32. The logical run identity is unique; attempts have
  independent write-once result keys; observations have stable per-run order;
  and all three tables force Class C tenant RLS.
- Added composite tenant foreign keys for attempt/observation to run and run to
  MediaUpload. The existing `media_uploads` table receives only the supporting
  additive `(id, family_space_id)` uniqueness constraint.
- Added mandatory conservative result bounds and a Laravel validator that
  checks byte size before parsing, contract version, face count, embedding
  shape/dtype, landmark scheme, JSON depth, finite values, canonical-relative
  geometry, diagnostics size and message/artifact count agreement.
- Extended the existing read/write signing abstractions with explicit browser
  and service audiences. Existing browser flows remain on
  `AWS_PUBLIC_ENDPOINT`; worker authorities use Docker-reachable
  `AWS_ENDPOINT`, with write-once `If-None-Match` preserved.

### Verification

- Foundation, documentation, Compose and contract checks passed.
- Frontend formatting, lint, typecheck and the unchanged 37-file/86-test suite
  passed.
- PHP formatting and PHPStan passed; the complete API suite passed with 243
  tests discovered, 205 passed, 38 environment-specific skips and 1,677
  assertions.
- Python Ruff, mypy and the complete eight-test image-analysis suite passed.
- Disposable PostgreSQL 17.6 migration/RLS verification passed: 28 tests and
  293 assertions, including direct database rejection for all three mismatched
  tenant relationships.
- Persistent PostgreSQL migration batch 32 is applied; migration status, forced
  RLS on all three tables and the three composite constraints were verified.
- npm and Composer security audits reported no vulnerabilities.
- Infrastructure smoke passed after the stopped local Redis/API stack was
  restarted; its real LocalStack storage suite passed eight tests and 39
  assertions.
- `git diff --check` passed.

### Stage boundary

No real provider, model weights, inference, queue/DLQ infrastructure, raw-SQS
consumer, automatic dispatch, reprocessing command or Phase 10 identity work is
included. Those remain S04, S05 and Phase 10 responsibilities respectively.

### Commit boundary

```text
Implement face-analysis provider contract
```

## FPA-P09-S04 — Implement local face-analysis provider

Initial candidate: InsightFace-compatible provider (`buffalo_l`) through ONNX Runtime. Owns model loading and canonical inference; running the private benchmark harness directly against the provider to produce real latency, memory, and result-size evidence; calibrating S03's bound defaults and queue-timing values from that evidence; and completing ADR-0011's licensing record against the actual pinned package/weight files.

### Objective

Implement the pinned local provider behind S03's provider-neutral contract,
prove it directly on the private S01 corpus, and turn measured evidence into
explicit model-integrity, configuration and result-bound defaults without
beginning the SQS pipeline.

### Implementation

- Pinned InsightFace 1.0.1 and ONNX Runtime 1.29.0. The official `buffalo_l`
  v0.7 archive and every ONNX file in it are verified against recorded SHA-256
  values before the model directory is scanned or loaded.
- Recorded the directly inspected library and weight-licence position in
  `docs/face-analysis/MODEL_LICENSING.md`. The official archive contains no
  bundled licence file; upstream package metadata and the official Model Zoo
  explicitly restrict the provided weights to non-commercial research.
- Implemented a directly callable provider returning contract-native bounds,
  five-point landmarks, confidence and L2-normalised 512-dimensional float32
  embeddings. Invalid decode, shape, numeric, confidence, face-count or model-
  integrity conditions fail closed without emitting biometric values.
- Calibrated a 640 × 640 detector at threshold 0.6 from a private 0.5/0.6/0.7
  sweep. Execution backend is excluded from `config_hash`; CPU/CoreML parity
  evidence confirms the accepted assumption within recorded tolerances.
- Added private direct-invocation benchmark and backend-parity tools. Their
  output remains gitignored and carries aggregate/count/timing/delta evidence,
  never image bytes, face crops, landmarks or embeddings.
- Calibrated the result-artifact byte ceiling from 8 MiB to 4 MiB while keeping
  the 256-face, 512-float32 and five-landmark structural bounds. S05 retains
  ownership of applying the evidence-calibrated starting values: 30-second
  visibility, five receives, 20-second inference timeout, five-minute attempt
  staleness, three attempts, 60-minute authorities and a 2 GiB single-inference
  worker.
- Mounted the ignored local model directory read-only into the image-analysis
  container and added the minimal native OpenCV runtime libraries required by
  the slim Linux image.

### Verification

- The complete Python format, lint, strict-mypy and 14-test suite passes.
- The 38-image private CPU benchmark detected 93 candidate faces against 79
  annotated visible faces: 30 exact-count, two under-count and six over-count
  images. Limitations are recorded rather than interpreted as identity truth.
- Hot CPU inference measured 66.856 ms median, 132.988 ms p95 and 141.732 ms
  maximum; model construction was 365.652 ms, first inference warm-up 173.802
  ms, observed peak RSS 1,093,976,064 bytes and maximum result size 67,869
  bytes.
- CPU/CoreML parity passed on three representative private assets with equal
  counts, identical logical configuration identity, sub-pixel geometry deltas
  and minimum embedding cosine 0.9992794022265646.
- The rebuilt arm64 Docker image loaded the checksum-verified model and
  analyzed one read-only private benchmark asset successfully.

### Stage boundary

No queue consumer, SQS dispatch, Laravel result adapter, analysis persistence,
DLQ/redrive configuration, stale-attempt reconciliation, automated Photo
dispatch or Phase 10 identity behaviour is included. Those remain S05 or Phase
10 work.

### Commit boundary

```text
Implement local face-analysis provider
```

## FPA-P09-S05 — Implement queued analysis pipeline

Store provider, model, version, configuration hash, source checksum and processing status. Owns: production-shaped use of the dedicated request/completed/failed queues; the Laravel-side raw-SQS result adapter; worker-facing signed URL delivery in practice; the write-once result-artifact transport; durable dispatch-attempt creation before dispatch; idempotent, tenant-consistent Laravel persistence; IAM/service-identity plumbing; dead-letter queues and redrive policy for all three queues; stale-attempt timeout reconciliation; queue visibility/redrive settings informed by S04's measurements; the bounded, audited backend reprocessing trigger; and adding `face-analysis` to the existing Family Space teardown object-storage prefix list.

### Objective

Connect the accepted provider and persistence contracts through the real local
SQS/S3 boundary with durable, tenant-safe and recoverable processing, without
introducing face identity, matching, clustering or `PhotoPerson` behavior.

### Implementation

- Canonical completion dispatches a unique Laravel job. The pipeline inserts
  or reuses one logical run identity, locks it, and creates the concrete
  attempt and result key before publishing. A transport retry republishes the
  same dispatched attempt; an analysis retry receives a fresh attempt and
  write-once key under the same run.
- The dedicated Python worker consumes raw requested messages, validates the
  strict contract, verifies the downloaded canonical SHA-256, enforces the
  calibrated 20-second inference and 4 MiB result limits, uploads a tagged
  write-once result artifact and emits only its reference/checksum/count.
- A dedicated Laravel raw-SQS consumer handles completed and failed messages.
  It first resolves `request_id` through a narrowly granted PostgreSQL
  `SECURITY DEFINER` function, establishes that trusted tenant context, then
  cross-checks all echoed identity and current MediaUpload state. Completed
  artifacts are size-inspected, downloaded, checksum-verified, contract-
  validated and atomically persisted as immutable observations. Redelivery,
  late outcomes and reconciliation races are guarded by the attempt lock and
  single-use state transition.
- All three source queues use 30-second visibility, five receives and their
  own DLQ. The production Terraform module separates Laravel send/consume/S3
  authority from the worker's SQS-only task role; the worker has no standing
  S3 permissions. Local synthetic observability traffic uses its own queue.
- A scheduled command reconciles attempts dispatched for more than five
  minutes, retrying with fresh attempts up to the calibrated maximum of three.
  A separate operator command requires one Family Space, optionally narrows
  to explicit uploads, rejects platform-wide use and audits actual scope and
  analysis identity.
- Transient results carry an S3 lifecycle tag with a one-day expiry backstop
  and are explicitly deleted after handling. Family Space teardown now also
  walks the tenant's `face-analysis` prefix. AWS clients retain local explicit
  credentials while allowing the production task-role credential chain.

### Verification

- Foundation, documentation, Compose, contract, formatting, lint, PHPStan,
  strict mypy, frontend typecheck and dependency-security gates passed.
- The complete frontend, Laravel and Python suites passed. Focused coverage
  proves durable-before-publish ordering, logical reuse, fresh-attempt retry,
  completion redelivery, checksum failure, strict poison handling, bounded
  audited reprocessing and lifecycle-tagged write-once authority.
- Disposable PostgreSQL 17.6 verification passed with direct concurrent
  dispatch proving one run and one attempt, tenant-consistent RLS/foreign-key
  coverage and raw-consumer rejection without deletion or process failure.
- Persistent migration batch 33 is applied. The rebuilt stack completed one
  disposable ordinary MediaUpload end to end on its first attempt; the raw
  completion persisted a valid zero-face outcome and removed its transient
  artifact. The fixture and canonical object were removed afterward.
- LocalStack confirms empty source queues, 30-second visibility, five-receive
  redrive to three distinct DLQs and the one-day tagged lifecycle rule.
  Infrastructure and observability smoke checks passed.

### Stage boundary

S06 retains the final operational benchmark and telemetry acceptance work.
No observation comparison, person identity, clustering, suggestion, human
review or `PhotoPerson` behavior is present; those remain Phase 10.

### Commit boundary

```text
Implement queued face-analysis pipeline
```

## FPA-P09-S06 — Add benchmark and operational metrics

Measure detection coverage, false detections, execution time, memory and failure rates. Owns the final end-to-end operational benchmark run and acceptance measurement, building on S04's provider-level evidence.

### Implementation

- The schema-version-2 private benchmark reports completion/failure rate,
  exact-count results, conservative count-derived coverage and miss limits,
  excess detections requiring review, confidence distribution, category
  aggregates, dimensions, throughput, latency, memory and result size. Detailed
  results remain gitignored and contain no committed family media.
- The queued worker extracts the request trace context and SQS system receive
  attributes. OpenTelemetry spans and metrics cover queue latency, end-to-end
  analysis duration, receive attempt count, inference duration,
  completed/failed outcomes, bounded failure category, detected/no-face counts,
  canonical dimensions, provider/model/configuration/backend identity and peak
  resident memory where measurable.
- Operational telemetry excludes tenant/upload identifiers, media-derived
  checksums, image bytes, signed URLs, bounds, landmarks, embeddings and
  provider diagnostics. PostgreSQL and audit responsibilities remain exactly
  as ADR-0011 section 20 defines them.

### Measured acceptance

- The final direct CPU run completed all 38 private images with zero provider
  failures. It produced 93 detections against 79 visible-face count annotations
  and 30 exact-count images. The count-only annotations establish a 75/79
  matched upper bound, four-miss lower bound and 18 excess detections requiring
  review; they cannot identify a specific false-positive location.
- Hot inference measured 69.569 ms median, 132.495 ms p95 and 134.045 ms
  maximum, with 13.068 sequential images/second, 973,864,960 bytes peak RSS and
  a 67,869-byte maximum result. S05's 20-second timeout, 2 GiB worker memory and
  4 MiB artifact ceiling retain substantial measured headroom.

### Verification

- Foundation, documentation, Compose, contract, formatting, lint, frontend,
  PHPStan, strict mypy and dependency-security checks passed.
- The complete frontend suite passed 86 tests; Laravel discovered 254 tests,
  passing 214 with 40 environment-specific skips and 1,706 assertions; all 21
  Python tests passed.
- PostgreSQL 17.6 integration passed 30 tests and 309 assertions, retaining the
  run-identity, retry, reprocessing and tenant-isolation guarantees.
- The rebuilt production-shaped worker exported its trace-linked queue,
  attempt, bounded-failure and memory signals through the real collector. The
  non-family probe was explicitly deleted, both queues were empty afterward,
  and the result consumer was restored.
- Infrastructure passed nine tests and 44 assertions; trace propagation and
  Grafana observability smoke passed; `git diff --check` passed.

### Stage boundary

The benchmark remains direct and private; it is never application data. No
similarity query, observation comparison, clustering, identity suggestion,
human review or `PhotoPerson` behavior is included. Those remain Phase 10.

### Commit boundary

```text
Add face-analysis operational metrics
```

### Phase verification

- Local Apple Silicon development works using the documented runtime.
- Reprocessing is idempotent.
- Model changes create new analysis records rather than mutating history invisibly.
- Raw images are not committed to the repository.
- Benchmark findings are documented without exposing sensitive family media.

---

# Phase 10 — Face Clustering and Human Review

## FPA-P10-S01 — Accept face clustering and human identity review ADR (ADR-0012)

ADR-0012 was accepted on 2026-08-28 after adversarial implementation-
readiness review reconciled face-level assignment cardinality, deterministic
`PhotoPerson` integration, disposable pgvector projection semantics,
recognition consent and suppression, rebuildable cluster generations, Person
merge and guarded reversal, calibration activation, authorization, audit and
the S02-S07 implementation boundaries against the live repository. This
stage's entire scope was accepting the ADR, so the acceptance commit is the
stage-completion commit and receives the `phase-10-s01` tag directly.

### Documentation updates

- Accepted ADR-0012.
- Reconciled the Phase 10 implementation-stage boundaries.
- Advanced `tasks.json` to FPA-P10-S02.

### Commit boundary

```text
Accept ADR-0012: Face clustering and human identity review
```

## FPA-P10-S02 — Implement embedding storage and similarity queries

Add the supporting additive `(id, family_space_id)` uniqueness constraints
to `face_observations` and `people` (before any composite foreign key that
depends on them, mirroring the existing `media_uploads` fix); provision the
`pgvector` extension locally, in the Postgres-only regression-test path, and
document the production requirement; add `face_embedding_projections` as a
disposable, checksum-verified, deterministically rebuildable projection of
`face_observations.embedding` — never a second representation on
`FaceObservation` itself; implement the Fambam-owned similarity-search
abstraction, scoped by Family Space and compatible embedding-space identity,
with no pgvector-specific construct reachable from recognition-domain code.

FPA-P10-S02 completed on 2026-08-28. The local and disposable PostgreSQL
environments now use a pgvector-capable PostgreSQL 17 image; migration batch 34
adds the two supporting tenant-consistent uniqueness constraints and the
forced-RLS `face_embedding_projections` table. Immutable float32 bytea remains
authoritative, while a tenant-bounded manager deterministically creates or
rebuilds checksum-bound vector projections without touching
`FaceObservation`. New analysis completion creates the projection in the same
database transaction. The provider-neutral `SimilaritySearch` contract scopes
every query by Family Space, projection version, dimension and the complete
provider/model/weight/config identity; pgvector operators remain confined to
its PostgreSQL adapter. Persistent migration, bounded backfill, drift repair,
cross-tenant/composite-FK rejection, incompatible-model exclusion and the
complete repository gates passed without beginning clustering.

### Commit boundary

```text
Implement pgvector face similarity projections
```

## FPA-P10-S03 — Implement conservative face clustering

Add `face_cluster_generations` → `face_clusters` → `face_cluster_members`,
with an enforceable three-state cluster lifecycle
(`active`/`retired`/`superseded`) and an enforceable generation lifecycle
(`building`/`active`/`superseded`) — database-level partial-unique
invariants, not prose. A rebuild's candidate population is every
recognition-eligible, currently-unassigned `FaceObservation` within one
compatible embedding space, **including observations currently held by
the generation being replaced** — active membership in the generation
being superseded never excludes an observation from a rebuild. The one
population that *is* permanently excluded is any observation with
membership history, of any generation, in a currently-`retired` cluster
(human-resolved, protected) — distinct from mere membership in a
`superseded` cluster (unprotected). A building generation's memberships
are created `is_active = false` (non-operational: invisible to
suggestion generation, review, and merge/split) and only become
`is_active = true` as part of the six-step atomic activation transaction
once the build succeeds: lock/validate the current active generation;
deactivate its active memberships; supersede its active clusters (never
touching anything already `retired`); activate the new generation;
activate its memberships; supersede the old generation. A failed build
never runs this transaction, so the active generation and its memberships
are left completely untouched. Implement re-clustering without image
inference. Runs only against the private benchmark corpus, isolated
development fixtures, and direct service-level tests while
`config('face_recognition.processing_enabled')` is `false`.

FPA-P10-S03 completed on 2026-08-28. Migration batch 35 adds the forced-RLS,
tenant-consistent generation, cluster and membership hierarchy with partial
unique indexes enforcing one active generation per Family Space and one active
membership per FaceObservation. A deterministic complete-link clusterer uses
only the Fambam-owned similarity abstraction and treats missing pair evidence
conservatively. Rebuilds include current machine groupings, exclude every face
with human-retired cluster history, create inert building memberships and
atomically activate only after revalidating the generation being replaced.
Family Space teardown removes the derived hierarchy. The operational command
remains disabled and the numeric clustering threshold remains unset until S07
calibration; direct fixture/service tests supply an isolated provisional value.

### Commit boundary

```text
Implement conservative face clustering
```

## FPA-P10-S04 — Implement identity suggestion and confirmation

Add `face_identity_assignments` (`pending`/`approved`/`rejected`/`withdrawn`)
and its integrity constraints; implement the four deterministic
`PhotoPerson`-ensuring transitions; implement trusted-gallery derivation and
confidence-banded candidate generation. Likewise gated by
`processing_enabled` for real Family Space data.

FPA-P10-S04 completed on 2026-08-28. Migration batch 36 adds the forced-RLS,
tenant-consistent assignment table and its partial unique active-claim index.
The similarity abstraction now returns approved trusted-gallery references
without exposing pgvector to recognition-domain code. Gated candidate
generation produces strong, shortlist or no-suggestion outcomes, keeps a
single reference cautious, prefers ambiguity over guessing, persists only a
strong single candidate as pending and emits privacy-bounded telemetry. Member
proposal and Owner/Administrator approval follow the existing authority model;
approval atomically creates, reuses or resolves `PhotoPerson` while preserving
rejected history. Numeric settings remain unset and automatic processing stays
disabled until S07.

### Commit boundary

```text
Implement face identity suggestions and confirmation
```

## FPA-P10-S05 — Implement merge, split, reject and unknown workflows

Add `FaceIdentitySuppression` and its reopen mechanism; implement cluster
merge/split/naming (acting only on `active` clusters in the current
generation). Extend `PersonMergeManager`'s existing `captureState()`/
`restoreState()`/reconciliation methods to cover:

- `face_identity_assignments` — `approved`/`rejected`/already-`withdrawn`
  rows repoint unconditionally to the surviving Person (no collision is
  possible at that grain); a still-`pending` row is instead transitioned
  to `withdrawn` (non-judgmental administrative retirement, never
  rejection) rather than repointed, because it represents a machine
  opinion computed against the absorbed Person's own gallery, which no
  longer independently exists after the merge — not because of any
  uniqueness conflict;
- `FaceIdentitySuppression` — repoints to the survivor where no collision
  exists; where a survivor-side suppression already exists for the same
  face, the absorbed-side row is **deleted from the live table**, never
  left pointing at the absorbed Person, with its full pre-merge state
  preserved only in the merge's existing before/after snapshot (the same
  delete-then-reinsert-from-snapshot shape `PersonMergeManager` already
  uses for `family_circle_people`/`person_account_links`), so guarded
  reversal can restore it exactly.

**No Phase 10 live structure may retain a foreign key to the absorbed
Person once the merge transaction completes, without exception** — this
is the ADR-0006 §12 Person-merge integration this stage owes, not a new
merge mechanism.

## FPA-P10-S06 — Implement recognition consent and exclusion

Add `people.recognition_allowed` (`NOT NULL DEFAULT false`, which backfills
every existing Person automatically); implement exclusion's withdraw/
remove-from-matching behaviour, transitioning dependent pending assignments
to `withdrawn` (never `rejected`, never creating suppression) as part of one
audited consent-change action.

## FPA-P10-S07 — Calibrate against the family benchmark

Extend the private benchmark corpus to cover cross-age, sibling,
parent/child, old-scan and appearance-change gaps; calibrate confidence-band
cutoffs, minimum-reference behaviour, and clustering thresholds against it;
record the result as a named `calibration_profile`; only then, as an
explicit final step, set `config('face_recognition.processing_enabled')` to
`true` so automatic Phase 10 processing begins against real Family Space
data for the first time. Phase 9's `0.6` detection threshold must not be
reused as a recognition threshold.

### Phase verification

- Confirmed assignments are separate from machine results.
- Re-clustering does not require image inference.
- False merges can be corrected safely.
- Excluded people do not receive new embeddings.
- No cross-family vector query is possible.

---

# Phase 11 — Search and Discovery

## FPA-P11-S01 — Accept search and discovery ADR (ADR-0013)

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

## FPA-P12-S01 — Accept memories and family homepage ADR (ADR-0014)

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

## FPA-P13-S01 — Accept export, portability, backup and recovery ADR (ADR-0015)

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

# Phase 14 — Family Product UI / UX Completion

This phase integrates the development UIs built throughout the functional
phases into the final Family Space product experience. It is not permission to
defer feature-level frontend work: every functional phase must still provide
enough UI to navigate, exercise and verify its own capability.

## FPA-P14-S01 — Accept family product UI/UX integration ADR (ADR-0019)

Define the integrated information architecture, navigation model, visual and
interaction foundations, supported responsive layouts, role-based journey
matrix and product-level accessibility acceptance approach. Preserve existing
domain authorization; this ADR integrates the product experience rather than
redesigning domain permissions.

## FPA-P14-S02 — Implement the product shell and Family Space context

Integrate global navigation, Family Space switching/context and the family
homepage into a consistent responsive shell with clear loading, empty, error
and success states.

## FPA-P14-S03 — Integrate Photo, upload, Album and Event journeys

Make browsing, Photo detail, ready-upload selection and promotion, upload
progress/recovery, Albums, Events and the Guest experience coherent without
requiring internal IDs, API knowledge or developer tooling.

## FPA-P14-S04 — Integrate People, recognition, duplicate and discovery journeys

Connect People and Person pages, Photo tagging, face suggestions and human
confirmation, duplicate prompts/review, search, discovery, memories and
history surfaces into understandable family workflows.

## FPA-P14-S05 — Integrate collaboration and Family Space management journeys

Complete comments, reactions, stories, invitations, membership, personal and
Family Space export/portability, and the normal Owner/Administrator Family
Space controls. Keep these customer-facing controls strictly distinct from
Phase 15 Platform Administration.

## FPA-P14-S06 — Complete responsive, visual and state integration

Reconcile mobile and desktop behaviour, visual consistency, keyboard and
assistive-technology operation, and loading, empty, error and success states
across all important journeys.

## FPA-P14-S07 — Conduct role-based product journey acceptance

Test Owner, Administrator, Member, Contributor and Guest through their real
product experiences with non-technical participants where practical. Resolve
journey blockers without using internal IDs, APIs, database records or
developer tooling as user-facing workarounds.

### Phase verification

- Each role can complete its important permitted journeys and is clearly
  refused where it lacks authority.
- Core family journeys work on supported mobile and desktop layouts.
- No normal product journey requires knowledge of internal identifiers or
  developer interfaces.
- Family Space management is clearly separated from platform operations.

---

# Phase 15 — Platform Administration UI

This is a separate system-operator interface, not Family Space
Owner/Administrator functionality. Its surfaces must be derived from
capabilities that exist when the phase begins; do not add speculative
enterprise administration features.

## FPA-P15-S01 — Accept platform administration authorization and operations ADR (ADR-0020)

Define dedicated Platform Administrator authorization, strict separation from
Family Space roles, least privilege, audited privileged operations and the
deliberate boundaries for support/debug access to family data. Platform
administration must not become an implicit tenancy bypass and must never imply
Family Space membership.

## FPA-P15-S02 — Implement platform administrator identity and audit foundation

Implement the dedicated authorization boundary, privileged-operation audit
trail and fail-closed separation from Family Space membership and ordinary
product routes.

## FPA-P15-S03 — Implement account and Family Space operations

Add only the user, account, Family Space, suspension/deactivation and support
investigation surfaces justified by completed account and tenancy capabilities.

## FPA-P15-S04 — Implement media and background-processing operations

Expose storage/media status, failed uploads, background jobs and retries, and
face/duplicate-analysis processing health using bounded, audited operations.

## FPA-P15-S05 — Implement diagnostics and supported lifecycle operations

Expose platform-wide audit/system events, operational diagnostics, supported
deletion/retention operations and genuinely required configuration without
inventing unsupported lifecycle semantics.

## FPA-P15-S06 — Verify privileged operational journeys

Test least privilege, separation from Family Space roles, absence of implicit
membership, audit completeness, deliberate family-data support access and the
operator journeys actually implemented.

### Phase verification

- Platform Administrator authority is dedicated and independent of Family
  Space roles.
- Privileged actions are least-privilege and auditable.
- Support access to family data is explicit, bounded and reviewable.
- Ordinary operational workflows do not require direct database or container
  access.

---

# Phase 16 — Security, Privacy and Accessibility Hardening

## FPA-P16-S01 — Accept security, privacy and accessibility ADR (ADR-0016)

Resolve the consent and lawful-processing model, child and guardian controls, security incident and breach response, and the accessibility acceptance standard, in one ADR before the audits and hardening work below measure against it.

## FPA-P16-S02 — Produce threat model and privacy data map

## FPA-P16-S03 — Review biometric and child-data controls

## FPA-P16-S04 — Perform application security hardening

## FPA-P16-S05 — Perform accessibility audit

## FPA-P16-S06 — Conduct non-technical family usability test

## FPA-P16-S07 — Resolve production blockers

### Phase verification

- Critical and high-risk issues are closed or explicitly accepted.
- Core workflows meet the chosen accessibility standard.
- Consent and exclusion controls are understandable to ordinary users.

---

# Phase 17 — Production Deployment and Pilot

## FPA-P17-S01 — Accept production deployment and family pilot ADR (ADR-0017)

## FPA-P17-S02 — Provision production infrastructure

## FPA-P17-S03 — Configure deployment, monitoring and rollback

Expand GitHub Actions beyond the foundation-only workflow so CI enforces the
API, web, PostgreSQL integration/RLS and image-analysis test suites before
production deployment. Keep each application suite independently runnable and
make the PostgreSQL job exercise the real database-specific paths.

## FPA-P17-S04 — Import initial curated archive

## FPA-P17-S05 — Invite pilot family members

## FPA-P17-S06 — Review pilot feedback and operating costs

## FPA-P17-S07 — Declare V1 or create remediation stages

### Phase verification

- Deployment is reproducible.
- Alerts and budget controls work.
- Pilot users complete core journeys.
- Support and recovery procedures are proven.

---

# Phase 18 — Semantic Image Search

This phase starts only after V1 unless explicitly promoted through roadmap review.

## FPA-P18-S01 — Build semantic-search benchmark

## FPA-P18-S02 — Accept semantic image search ADR (ADR-0018)

## FPA-P18-S03 — Implement semantic embedding provider

## FPA-P18-S04 — Implement hybrid search

## FPA-P18-S05 — Add re-indexing and evaluation tooling

---

# Phase 19 — Advanced Archive Features

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
