# fambam — a private family photo platform

fambam is a private, invite-only digital family home for sharing
photographs, recording their stories and preserving family history. It is
family-centred rather than user-centred: family spaces provide the ownership,
collaboration and security boundary for photos, people, relationships, events
and memories.

The project deliberately avoids public profiles, follower systems, popularity
metrics, advertising and algorithmic engagement feeds. Original photographs
and their provenance are preserved, human-confirmed family knowledge takes
priority over automated suggestions, and family data must remain portable.

## Current status

Phases 0 and 1 are complete. Phase 2 account-security hardening is implemented
in `FPA-P02-S04` and remains under independent review before the Phase 2
acceptance gate can complete. The canonical execution state is recorded in
[`tasks.json`](tasks.json).

## Repository structure

| Path | Responsibility |
| --- | --- |
| `apps/web` | Browser application and presentation logic |
| `apps/api` | Laravel API and sole business-data authority |
| `apps/image-ai` | Stateless Python image-analysis inference service |
| `contracts/events` | Versioned, language-neutral event contracts |
| `contracts/http` | Versioned HTTP contracts |
| `docs/adr` | Architectural decisions and trade-offs |
| `docs/journal` | Engineering session records |
| `infrastructure` | Local and production infrastructure configuration |
| `scripts` | Repeatable repository automation |
| `tests/e2e` | Cross-service end-to-end tests |

The service topology is defined by
[`ADR-0001`](docs/adr/0001-repository-and-service-topology.md). Laravel owns
business data and is the sole PostgreSQL writer. The Python service is stateless
and communicates through asynchronous, versioned messages.

## Developer commands

Run `make help` from the repository root to list the command surface. The core
local-platform commands are:

```bash
make up
make status
make infrastructure-smoke
make logs
make down
```

The default host endpoints are:

| Service | URL |
| --- | --- |
| Web | `http://localhost:3010` |
| API health | `http://localhost:8082/api/health` |
| Image AI health | `http://localhost:8010/health` |
| Mailpit | `http://localhost:8026` |
| LocalStack | `http://localhost:4570` |
| Grafana | `http://localhost:3011` |

The account UI is available at `http://localhost:3010/login`. It uses
CSRF-protected, database-backed Laravel sessions; there is deliberately no open
registration route. Create the first local invitation-capable account with the
interactive command below, then use the account page to invite relatives:

```bash
docker compose exec api php artisan fambam:bootstrap-user owner@example.test \
  --name="Archive Owner" --timezone="Europe/London"
```

Invitation emails are captured by Mailpit locally. Their acceptance links keep
the invitation token in the URL fragment, exchange it once for a short-lived
claim and remove it from browser history before displaying the account form.

Copy `.env.example` to `.env` only when local port or safe development-default
overrides are needed. The core checks are:

```bash
make foundation-check
make docs-check
make contracts-check
make format-check
make lint
make typecheck
make test
make observability-smoke
```

## Documentation

- [`PRODUCT_VISION.md`](PRODUCT_VISION.md) — product purpose, principles and non-goals.
- [`PROJECT_ROADMAP.md`](PROJECT_ROADMAP.md) — delivery phases and release boundary.
- [`docs/IMPLEMENTATION_GUIDE.md`](docs/IMPLEMENTATION_GUIDE.md) — stage implementation and verification guidance.
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — repository-wide engineering workflow.
- [`docs/ENGINEERING_METHODOLOGY.md`](docs/ENGINEERING_METHODOLOGY.md) — reusable engineering process and responsibilities.

Read [`CONTRIBUTING.md`](CONTRIBUTING.md) before making changes. Never commit
real family photographs, personal data, credentials or populated environment
files.
