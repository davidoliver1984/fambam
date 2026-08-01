# Family Photo Archive — Project Roadmap

> **Purpose:** Define what will be built, in what order, and where architectural decisions are required.
>
> This roadmap is intentionally product-led. The project exists to provide a private, family-centred alternative to social media for sharing and preserving photographs across an international family.

## Roadmap conventions

- Phase identifiers use `P##`.
- Stage identifiers use `FPA-P##-S##`.
- Architectural decisions are recorded in `docs/adr/`.
- Detailed implementation instructions belong in `docs/IMPLEMENTATION_GUIDE.md`.
- Current execution state belongs in `tasks.json`.
- Completed implementation sessions should be recorded in `docs/journal/`.
- A phase is complete only when its required verification and documentation are complete.

## ADR methodology

Each roadmap phase plans exactly one ADR: the architectural design document
for that phase, accepted at that phase's leading "Accept ... ADR" stage
before any implementation stage in the phase begins. Additional ADRs are
created only when a significant architectural change is discovered during
implementation of an already-accepted phase — they are not pre-planned.
Phase 0 is a one-time exception (see Phase 0 below). The explicit mapping
between each ADR, its owning roadmap phase and its gating implementation
stage is recorded in `tasks.json`'s `adrs.planned` list
(`roadmap_phase` / `required_by_stage` fields).

## Product principles

1. Family-centred rather than user-centred.
2. Private by default and invite-only.
3. Accounts, people and relationships are separate concepts.
4. Human-confirmed family knowledge outranks machine-generated suggestions.
5. Original photographs and provenance must be preserved.
6. AI analysis is replaceable, versioned derived data.
7. Accessibility for elderly and non-technical relatives is a core requirement.
8. No advertising, follower system, popularity metrics or algorithmic engagement feed.
9. The project should ship useful family sharing before pursuing advanced AI features.
10. The family must be able to export its photographs and metadata.

---

# Phase 0 — Product and Repository Foundation

## Objective

Establish the repository, engineering conventions, documentation ownership and agreed product boundaries.

## Outcomes

- Monorepo or clearly structured repository created.
- Root README, CONTRIBUTING guide and AI collaboration guidance.
- Project roadmap, implementation guide and canonical task tracker.
- ADR and journal directories.
- Local command interface.
- Initial CI and formatting conventions.
- Explicit non-goals and V1 boundary.

## Required decisions

- ADR-0001: Repository and service topology.
- ADR-0002: Primary technology stack.

Phase 0 is a one-time exception to the one-ADR-per-phase methodology (see
"ADR methodology" below): ADR-0001 was already accepted narrowly, before
that methodology was adopted, so Phase 0 closes with two ADRs rather than
one. A separate documentation-ownership ADR was considered and rejected —
that convention doesn't meet the bar for when an ADR is warranted, and is
already fully specified in `docs/IMPLEMENTATION_GUIDE.md` and
`CONTRIBUTING.md`.

## Exit criteria

- A new developer or AI coding agent can identify the current stage and run all foundation checks.
- Documentation files do not contradict one another.
- The first implementation stage has a bounded commit boundary.

---

# Phase 1 — Local Development Platform

## Objective

Create a reproducible local environment for the web application, API, database, queues, object storage and image-analysis service.

## Target components

- Web frontend.
- Laravel API.
- Python image-analysis service.
- PostgreSQL.
- Redis.
- S3-compatible object storage.
- Mail testing service.
- Optional local observability tooling.

## Required decisions

- ADR-0003: Local development platform.

## Exit criteria

- One documented command starts the development platform.
- Every application exposes a health check.
- Database, queues and object storage are reachable from the appropriate services.
- CI can run application-level checks independently.

---

# Phase 2 — Identity, Authentication and Invitations

## Objective

Implement secure, invite-only access suitable for an international family.

## Scope

- Registration by invitation.
- Login and logout.
- Current-user endpoint.
- Email verification.
- Password reset.
- Session security.
- Optional MFA foundation.
- Invitation expiry, revocation and acceptance.
- Basic account profile and timezone.

## Required decisions

- ADR-0004: Identity, authentication and invitations.

## Exit criteria

- Uninvited users cannot create accounts.
- Invitation lifecycle is audited.
- Authentication flows have feature tests.
- Sessions and cookies follow production security requirements.

---

# Phase 3 — Family Spaces and Tenancy

## Objective

Make the family space the primary collaboration, ownership and security boundary.

## Scope

- Create and manage family spaces.
- Membership roles.
- Active-family context.
- Family-scoped routing.
- Policies and explicit tenant queries.
- Database defence in depth.
- Family-space audit history.
- Member removal and access revocation.

## Initial roles

- Owner.
- Administrator.
- Member.
- Contributor.
- Guest.

## Required decisions

- ADR-0005: Family spaces and tenancy.

## Exit criteria

- Cross-family access tests fail closed.
- Tenant-owned records require a family-space identifier.
- Runtime database roles cannot bypass tenant protections.
- At least one owner must remain.
- Membership changes are audited.

---

# Phase 4 — People, Accounts and Family Relationships

## Objective

Model family history independently of login accounts.

## Scope

- Person records.
- Optional account-to-person links.
- Living and deceased people.
- Birth and death dates with uncertainty.
- Names, preferred names and historical names.
- Family relationships.
- Family circles and presentation groups.
- Relationship-aware views from the current person's perspective.
- Person merge safeguards.

## Required decisions

- ADR-0006: People, accounts and relationships.

## Exit criteria

- A person can exist without an account.
- One account can link to the correct person inside a family space.
- Relationships do not grant permissions.
- Deceased relatives have first-class archive pages.
- Person merges preserve provenance and audit history.

---

# Phase 5 — Media Storage and Upload Pipeline

## Objective

Provide reliable photo upload, original preservation and secure media delivery.

## Scope

- Direct or API-mediated uploads.
- File validation.
- Malware and content-type checks.
- Original asset preservation.
- Checksums.
- EXIF extraction.
- Orientation correction.
- Canonical analysis asset.
- Responsive variants and thumbnails.
- Signed/private media delivery.
- Upload progress and failure recovery.
- HEIC and common mobile formats.
- Bulk upload foundation.

## Required decisions

- ADR-0007: Media storage and upload pipeline.

## Exit criteria

- Originals are never silently overwritten.
- Derivatives are reproducible.
- Presentation changes do not invalidate AI analysis unnecessarily.
- Upload retries are idempotent.
- Access to media is family-scoped and tested.

---

# Phase 6 — Photo Domain, Provenance and Organisation

## Objective

Model photographs as family archive records rather than anonymous files.

## Scope

- Photo records and media assets.
- Uploader, photographer, scanner and physical-source provenance.
- Approximate dates and confidence.
- Locations.
- Albums.
- Dynamic views.
- Tags and family-supplied metadata.
- Photo stories.
- Comments and simple reactions.
- Soft deletion and restoration.

## Required decisions

- ADR-0008: Photo domain, provenance and organisation.

## Exit criteria

- One photo can appear in many collections without asset duplication.
- Technical and family metadata are clearly separated.
- Provenance is retained through edits.
- Historical dates can represent exact, month-only, year-only and approximate values.

---

# Phase 7 — Events and Collaborative Sharing

## Objective

Support birthdays, holidays, weddings and other shared family occasions.

## Scope

- Events.
- Event albums.
- Contributors and attendees.
- Date ranges and locations.
- Event-specific invitations or upload links.
- Bulk contribution.
- Notifications.
- Download original or curated event archive.

## Required decisions

- ADR-0009: Events and collaborative sharing.

## Exit criteria

- Multiple relatives can contribute safely to one event.
- Guest contribution cannot expose the wider family archive.
- Event exports retain useful metadata.

---

# Phase 8 — Exact and Visual Duplicate Detection

## Objective

Reduce repeated uploads without risking automatic data loss.

## Scope

- Cryptographic checksum detection.
- Perceptual hashing.
- Similar-image candidates.
- Duplicate review interface.
- Consolidation of metadata and album references.
- No automatic deletion.

## Required decisions

- ADR-0010: Duplicate detection.

## Exit criteria

- Exact duplicates are reliably detected.
- Similar-image suggestions are explainable.
- User decisions are reversible and audited.
- Metadata is not lost during consolidation.

---

# Phase 9 — Local Face Analysis Foundation

## Objective

Introduce replaceable, self-hosted face detection and embedding generation.

## Initial candidate

- InsightFace-compatible local provider, subject to model licensing review.

## Scope

- Provider interface.
- Python inference boundary.
- Face detection.
- Facial landmarks and alignment where required.
- Embedding generation.
- Analysis jobs.
- Model and configuration versioning.
- Source checksum validation.
- Provider-specific analysis storage.
- Local benchmark harness.

## Required decisions

- ADR-0011: Local face analysis foundation.

## Exit criteria

- The Mac development environment can process a representative benchmark set.
- Provider outputs are stored as derived data.
- Changing provider or model does not destroy human metadata.
- Analysis can be safely retried.
- Model licensing is documented.

---

# Phase 10 — Face Clustering and Human Identity Review

## Objective

Convert face embeddings into useful, correctable family groupings.

## Scope

- Face clustering.
- Unnamed person groups.
- Suggested identity matches.
- Confidence bands.
- Manual confirmation.
- Reject, merge and split workflows.
- Unknown and irrelevant face handling.
- Bulk review.
- Re-clustering without image inference.
- Audit trail.

## Safety principle

False merges are more harmful than missed matches. Initial thresholds must favour precision.

## Required decisions

- ADR-0012: Face clustering and human identity review.

## Exit criteria

- Human-confirmed identity survives re-analysis.
- Incorrect clusters can be split.
- Recognition can be disabled for a person.
- No cross-family matching is possible.
- Benchmark results and chosen thresholds are documented.

---

# Phase 11 — Search and Discovery

## Objective

Make the family archive easy to explore without requiring manual album organisation.

## Scope

- Search by person.
- Date and approximate date.
- Location.
- Event.
- Album.
- Contributor.
- Metadata completeness.
- Combined filters.
- Saved views.
- Search permissions.

## Required decisions

- ADR-0013: Search and discovery.

## Exit criteria

- Searches remain family-scoped.
- Approximate historical dates behave predictably.
- Person and event combinations are supported.
- Search results are accessible and mobile-friendly.

---

# Phase 12 — Memories and Family Homepage

## Objective

Create a warm, useful homepage that feels like a private family home rather than a social feed.

## Scope

- Recent family contributions.
- “On this day.”
- Across-the-years memories.
- Newly identified relatives.
- Recently added stories.
- Personalised family relationship views.
- Notification preferences.
- Gentle pagination rather than engagement-driven infinite scroll.

## Required decisions

- ADR-0014: Memories and family homepage.

## Exit criteria

- Memory generation is deterministic and explainable.
- Sensitive or excluded photos are respected.
- Users can control which memories are surfaced.
- The homepage performs well with large archives.

---

# Phase 13 — Export, Portability, Backup and Recovery

## Objective

Ensure the family can recover, move and preserve its archive independently of the application.

## Scope

- Personal export.
- Family-space export.
- Original media export.
- Metadata manifests.
- Album and event structure.
- People and relationship data.
- Recognition-data exclusion by default.
- Backup verification.
- Restore procedure.
- Disaster-recovery runbook.

## Required decisions

- ADR-0015: Export, portability, backup and recovery.

## Exit criteria

- A complete family archive can be exported without proprietary tooling.
- Restore is tested, not merely documented.
- Backup health is observable.
- Deletion semantics cover primary data, derivatives and backups.

---

# Phase 14 — Security, Privacy and Accessibility Hardening

## Objective

Perform a dedicated production-readiness pass before inviting the wider family.

## Scope

- Threat model.
- Biometric-data review.
- UK GDPR documentation.
- Consent records.
- Child and guardian controls.
- Account security.
- Object-storage security.
- Rate limiting.
- Audit review.
- Accessibility testing.
- Keyboard navigation.
- Screen-reader support.
- Low-bandwidth behaviour.
- Elderly-user usability testing.

## Required decisions

- ADR-0016: Security, privacy and accessibility hardening.

## Exit criteria

- Critical security findings are resolved.
- Privacy controls are visible and understandable.
- Recognition is opt-in where required by the final policy.
- Core workflows meet the selected accessibility standard.
- A non-technical family member can complete upload, browse and comment tasks.

---

# Phase 15 — Production Deployment and Family Pilot

## Objective

Deploy the platform safely and validate it with a small real family cohort.

## Scope

- Production infrastructure.
- Domain and TLS.
- Managed database and object storage.
- Worker deployment.
- Monitoring and alerts.
- Cost budgets.
- Pilot invitations.
- Feedback capture.
- Support and recovery procedures.
- Import of an initial curated archive.

## Required decisions

- ADR-0017: Production deployment and family pilot.

## Exit criteria

- Production deployment is reproducible.
- Backups and restore have been tested.
- A small pilot group uses the service successfully.
- Operational costs are measured.
- Pilot findings are triaged before wider rollout.

---

# Phase 16 — Semantic Image Search

## Objective

Enable natural-language discovery of photographs after the core family-sharing product is stable.

## Scope

- Image-text embedding provider abstraction.
- Local model evaluation.
- Semantic embedding generation.
- Vector search.
- Hybrid metadata and semantic search.
- Re-indexing strategy.
- Safety and false-result handling.

## Example queries

- Mum holding a baby.
- Dog on the beach.
- Christmas dinner.
- Old black-and-white wedding photographs.
- Dad wearing a red shirt.

## Required decisions

- ADR-0018: Semantic image search.

## Exit criteria

- Search quality is evaluated against a family-specific benchmark.
- Semantic results obey family permissions.
- Model upgrades are versioned and reversible.
- Search never presents generated descriptions as confirmed facts.

---

# Phase 17 — Advanced Archive Features

## Objective

Extend the mature archive without compromising its original purpose.

## Candidate features

- Voice memories.
- Family-tree visualisation.
- Historical timelines.
- Video support.
- Mobile applications.
- Automatic camera backup.
- AI-assisted date or location suggestions.
- Image restoration and colourisation.
- Conversational archive search.
- Print-ready exports.
- End-to-end encrypted private collections.

Every feature requires a separate scope review and should not be assumed part of V1.

---

# V1 release boundary

V1 is considered complete after Phase 15.

Phase 16 and later are optional post-V1 enhancements.

The V1 family pilot must prioritise:

- secure invitations;
- family-space isolation;
- people and relationships;
- reliable uploads;
- originals and derivatives;
- albums and events;
- comments and stories;
- duplicate detection;
- face grouping and human confirmation;
- useful search;
- memories;
- export and recovery;
- privacy, security and accessibility.

The success metric is not feature count.

The project succeeds when the family trusts it, understands it and chooses to share photographs through it.
