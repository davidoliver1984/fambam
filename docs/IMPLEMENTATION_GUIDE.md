# fambam — Implementation Guide

> **Purpose:** This is the durable build guide for the fambam.
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
  Fambam therefore defaults to a dedicated host-port range while retaining the
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

## FPA-P05-S04 — Extract metadata and generate canonical assets

Apply orientation correctly and preserve original EXIF separately.

## FPA-P05-S05 — Generate presentation variants

Create thumbnails and responsive variants through queued jobs.

## FPA-P05-S06 — Secure media delivery

Use authorised application checks and short-lived signed URLs or an equivalent
accepted approach. Record `original_download_authorised` when Laravel issues an
authorised signed URL; do not claim the subsequent object-storage GET occurred.
Ordinary APIs and presentation assets withhold privacy-sensitive metadata, while
an authorised original download deliberately receives the untouched original.

## FPA-P05-S07 — Add upload recovery and bulk upload

Support retries, partial failure reporting and duplicate-safe client behaviour.
Extend ADR-0005's idempotent Family Space teardown to remove the tenant's media
objects and Phase-5-owned rows without introducing a standalone media-delete
operation.

### Phase verification

- Uploading the same completion event twice is safe.
- HEIC and required mobile formats are tested.
- Original checksums remain stable.
- Reused or racing upload authority cannot replace a preserved original.
- Variants can be deleted and regenerated.
- Unauthorised media access fails.

---

# Phase 6 — Photo Domain, Provenance and Organisation

## FPA-P06-S01 — Accept photo domain, provenance and organisation ADR

Resolve photo ownership terms, albums, dynamic collections and historical confidence (ADR-0008).

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

## FPA-P07-S01 — Accept events and collaborative sharing ADR (ADR-0009)

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

# Phase 8 — Exact and Visual Duplicate Detection

## FPA-P08-S01 — Accept duplicate-detection ADR (ADR-0010)

Resolve duplicate definitions, similarity thresholds and consolidation behaviour before implementation.

## FPA-P08-S02 — Implement exact duplicate detection

Use cryptographic hashes and family-aware duplicate policies.

## FPA-P08-S03 — Implement perceptual similarity analysis

Generate versioned perceptual hashes and candidate scores.

## FPA-P08-S04 — Implement duplicate review and consolidation

Never delete automatically. Preserve album, event, story and provenance associations.

### Phase verification

- Exact duplicate detection is deterministic.
- Similar candidates can be dismissed.
- Consolidation has audit records and safe conflict handling.

---

# Phase 9 — Local Face Analysis Foundation

## FPA-P09-S01 — Build representative local benchmark

Create a private, non-repository benchmark manifest covering recent photos, age variation, groups, scans, profiles, siblings and poor-quality images.

## FPA-P09-S02 — Accept local face analysis foundation ADR

Resolve provider abstraction, initial local model and licensing, analysis data model and versioning, inference deployment and service identity, and the biometric-data threat model, in one ADR (ADR-0011). Do not implement a pretrained model until code and model-weight licensing are recorded within it.

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

## FPA-P10-S01 — Accept face clustering and human identity review ADR (ADR-0012)

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

# Phase 14 — Security, Privacy and Accessibility Hardening

## FPA-P14-S01 — Accept security, privacy and accessibility ADR (ADR-0016)

Resolve the consent and lawful-processing model, child and guardian controls, security incident and breach response, and the accessibility acceptance standard, in one ADR before the audits and hardening work below measure against it.

## FPA-P14-S02 — Produce threat model and privacy data map

## FPA-P14-S03 — Review biometric and child-data controls

## FPA-P14-S04 — Perform application security hardening

## FPA-P14-S05 — Perform accessibility audit

## FPA-P14-S06 — Conduct non-technical family usability test

## FPA-P14-S07 — Resolve production blockers

### Phase verification

- Critical and high-risk issues are closed or explicitly accepted.
- Core workflows meet the chosen accessibility standard.
- Consent and exclusion controls are understandable to ordinary users.

---

# Phase 15 — Production Deployment and Pilot

## FPA-P15-S01 — Accept production deployment and family pilot ADR (ADR-0017)

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

## FPA-P16-S02 — Accept semantic image search ADR (ADR-0018)

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
