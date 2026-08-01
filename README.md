# Family Photo Archive

Family Photo Archive is a private, invite-only digital family home for sharing
photographs, recording their stories and preserving family history. It is
family-centred rather than user-centred: family spaces provide the ownership,
collaboration and security boundary for photos, people, relationships, events
and memories.

The project deliberately avoids public profiles, follower systems, popularity
metrics, advertising and algorithmic engagement feeds. Original photographs
and their provenance are preserved, human-confirmed family knowledge takes
priority over automated suggestions, and family data must remain portable.

## Current status

Phase 0, Product and Repository Foundation, is complete. The repository is now
in Phase 1, Local Development Platform. The three application services and
their reproducible local infrastructure are implemented and verified; baseline
observability is next. The canonical execution state is recorded in
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
