# ADR-0012: Face Clustering and Human Identity Review

- Status: Accepted
- Date: 2026-08-27
- Decision owners: David
- Related stages: FPA-P10-S01 (accepting this ADR completes that stage),
  implemented by FPA-P10-S02, FPA-P10-S03, FPA-P10-S04, FPA-P10-S05,
  FPA-P10-S06, FPA-P10-S07

## Context

Phase 9 (ADR-0011, accepted, git tag `phase-9-s02`) closed with a complete,
production-shaped face-analysis foundation: `face_analysis_runs` →
`face_analysis_attempts` → `face_observations`, tenant-consistent at the
database level through composite foreign keys against
`media_uploads.(id, family_space_id)`, immutable once written, versioned
by `(provider, model_identifier, model_weight_checksum, config_hash)`, and
carrying a real, calibrated pipeline: InsightFace 1.0.1 / ONNX Runtime
1.29.0 / `buffalo_l` v0.7, 512-dimensional float32 embeddings, five-point
landmarks, a **0.6 detection-confidence threshold**. That threshold
governs whether a face is detected at all — it says nothing about whether
two embeddings represent the same person, and this ADR does not reuse it
as one (§16). Phase 9 assigned no identity to anything it detected and
built no comparison of one `FaceObservation` against another; this ADR is
where that comparison — and the human review around it — is designed.

Four earlier ADRs already fix constraints this ADR builds on rather than
reopens:

- **ADR-0011** fixed `FaceObservation` as immutable, permanent, versioned
  derived data (§13), embeddings stored as an ordinary Postgres column
  with the ANN/search engine choice explicitly deferred to this ADR (§2),
  and the tenant-consistent composite-FK discipline this ADR extends to
  every new table it introduces.
- **ADR-0008 §5** fixed `PhotoPerson` — "the claim that a given `Person`
  appears in a given Photo" — as the human-confirmed/proposed authoritative
  record, with an explicit non-overwrite guarantee against future machine
  suggestions. Direct inspection of the live schema
  (`2026_08_24_010000_add_photo_family_metadata.php:39-58`) confirms
  `photo_people` has no reference to any specific detected face today, and
  carries `CREATE UNIQUE INDEX photo_people_active_unique ON photo_people
  (photo_id, person_id) WHERE status IN ('pending', 'approved')` — **at
  most one active row per `(photo, person)` pair.** This single fact
  drives §3 below: a face-level claim cannot be represented as a column on
  `PhotoPerson` without breaking that constraint the moment the same
  Person appears twice in one Photo.
- **ADR-0006 §12** fixed Person merge (survivor/absorbed, tombstone,
  atomic reconciliation) and named an obligation this ADR now owes: "every
  future Person-referencing domain (photographs, **face-recognition
  assignments**, search indices, and similar) must explicitly integrate
  with merge behaviour when it is introduced." §12 below closes that
  obligation by extending `PersonMergeManager`'s own already-shipped
  capture/reconcile/restore mechanism — direct inspection of
  `apps/api/app/Services/PersonMergeManager.php` confirms this mechanism
  already exists and already reconciles `PhotoPerson` (its
  `reconcilePhotoProvenance()` method, `captureState()`/`restoreState()`/
  `statesMatch()` for guarded reversal) — this ADR extends the same
  mechanism, it does not invent a parallel one. ADR-0006 §4/§14 also
  already fixed the proposed-vs-authoritative pattern — Member may
  propose, Owner/Administrator alone may confirm or resolve as
  authoritative — reused directly in §13 rather than redesigned.
- **ADR-0005 §9** fixed the three RLS treatments; every table this ADR
  introduces is the ordinary tenant-owned kind, on the same composite-FK
  pattern ADR-0011 §8 already proved out for biometric data specifically.

`PROJECT_ROADMAP.md`'s Phase 10 objective is "convert face embeddings into
useful, correctable family groupings," with an explicit, already-fixed
safety principle this ADR operationalises rather than re-derives: **"False
merges are more harmful than missed matches. Initial thresholds must
favour precision."** Its exit criteria fix five concrete guarantees this
ADR must make architecturally true: human-confirmed identity survives
re-analysis; incorrect clusters can be split; recognition can be disabled
for a person; no cross-family matching is possible; benchmark results and
chosen thresholds are documented. `docs/IMPLEMENTATION_GUIDE.md`'s Phase 10
section is currently headers only — this ADR supplies the substance.

**Benchmark evidence.** Phase 9's benchmark measured detection coverage
and inference performance. **It measured nothing about recognition
accuracy**: same-person-across-decades, child-to-adult progression,
sibling and parent/child resemblance, damaged/old scans, and confirmed
appearance changes were named as explicit gaps reserved for this phase and
remain unfilled. §16 fixes the calibration gate — and the safe-activation
boundary this reconciliation adds to it — that this evidence must close
before any threshold or automatic processing goes live against real
family data.

**Current repository state, reconfirmed for this reconciliation pass.** No
vector extension, no ANN index, and no recognition-specific table exists
anywhere yet. `face_observations.embedding` is an ordinary `binary`
column. **Neither `face_observations` nor `people` yet carries the
supporting `UNIQUE (id, family_space_id)` constraint this ADR's new tables
require** — `media_uploads` received exactly this fix during ADR-0011's
own reconciliation; `face_observations` and `people` have not, and both
must (§4). `config/image-analysis.php` already records Phase 9's active
generation identity — this ADR's active-recognition-profile concept (§11)
reuses that identity directly rather than inventing a parallel one.

This ADR decides: the vector-storage/similarity-search abstraction and its
disposable-projection shape; the face-level identity concept
(`FaceIdentityAssignment`) and its deterministic relationship to
`PhotoPerson`; the trusted-gallery derivation and candidate-matching
shape; confidence bands and multi-candidate behaviour; rejection/
suppression and consent-withdrawal semantics; unknown-face clustering and
its enforceable lifecycle; recognition consent/exclusion; model-version
coexistence; the explicit Person-merge integration ADR-0006 §12 already
named; authorization and audit; deletion/reprocessing interactions; the
calibration gate and its safe-activation boundary; and the Phase 10/14 UI
boundary. It deliberately decides **nothing** about final Phase 14 UX,
cross-Family-Space matching (never built, ever), making AI identity
authoritative, retraining InsightFace, or specific numeric thresholds.

## Decision

### 1. Scope: correctable groupings and suggestions, never authoritative identity

Phase 10 computes similarity, generates candidate suggestions and
unconfirmed clusters, and gives **Owner/Administrator** the tools to
confirm, reject, reopen suppression, merge, split, and exclude —
**Member's role is limited to proposing and viewing**, exactly as fixed in
§13's authorization table; nothing in this phase widens Member authority
beyond that. Phase 10 never itself creates an authoritative identity fact
— every path that results in "this is William" ends at a human confirming
a `FaceIdentityAssignment` (§3), never at a machine writing one as
already-approved.

### 2. Vector storage and similarity-search abstraction

**PostgreSQL remains authoritative for embeddings and their provenance.
pgvector is the initial similarity-search implementation behind a narrow
Fambam-owned abstraction. Qdrant, if ever justified by scale or workload,
is a rebuildable projection, never a second source of truth.
Backend-specific ANN indexes are disposable; embeddings and provenance are
not.**

**The searchable vector lives in a separate, disposable projection table
— never as a second representation on `FaceObservation` itself.**
ADR-0011 §13 fixed `FaceObservation` as immutable and never updated in
place; a pgvector column that must be populated, and periodically
rebuilt or re-encoded, on that same row would either violate that
guarantee outright or require a narrow, ADR-level carve-out to
immutability that this reconciliation does not adopt, because a cleaner
option exists:

```text
face_embedding_projections
- id
- family_space_id
- face_observation_id
- projection_version      (the deterministic bytea→vector encoding version)
- source_checksum         (SHA-256 of the exact FaceObservation.embedding
                            bytes this projection was derived from)
- vector                  (pgvector-typed, dimension matching
                            FaceObservation.embedding_dimension)
- created_at / updated_at
```

- **Tenant-consistent**: composite foreign key `(face_observation_id,
  family_space_id) → face_observations (id, family_space_id)` — the same
  discipline §4 fixes for `face_identity_assignments`, and one of the two
  reasons `face_observations` needs its own supporting `UNIQUE (id,
  family_space_id)` constraint (§4).
- **One active projection per face**: `UNIQUE (face_observation_id)` — a
  rebuild upserts this one row rather than accumulating versions. A
  `FaceObservation`'s embedding-space identity is permanently fixed at
  creation (inherited from its immutable parent `FaceAnalysisRun`, ADR-0011
  §10), so no `FaceObservation` ever needs more than one live projection.
- **Deterministic, checksum-verified rebuild**: the projection's `vector`
  is always exactly re-derivable from `face_observations.embedding` by the
  same fixed encoding; `source_checksum` lets drift be detected by
  comparison rather than assumed — if a projection's `source_checksum`
  does not match a fresh hash of its parent's current (immutable, so
  always identical) embedding, the projection is stale and is rebuilt from
  the authoritative bytea, never patched in place, and never by mutating
  `FaceObservation`.
- **Fully disposable**: deleting or rebuilding a `face_embedding_projections`
  row is ordinary DML on this table alone. Nothing about `FaceObservation`
  is ever touched by a projection rebuild, by a pgvector extension
  upgrade, or by a future migration to a different search backend.

This is the same relationship, recursively, that this ADR already
requires between PostgreSQL and any future Qdrant projection — one
deterministic derivation, two possible destinations, **neither of which is
ever the authoritative source**. `pgvector`'s presence changes what this
table's `vector` column looks like; it never touches `face_observations`.

A single, narrow, Fambam-owned interface (a "similarity search"
repository/service) is the *only* thing recognition-domain code ever
calls, operating over `face_embedding_projections`. It exposes operations
in domain terms — "find the k nearest trusted references to this
embedding, scoped to this Family Space and this embedding-space identity"
— never pgvector-specific SQL, its distance operators (`<=>`/`<->`/`<#>`),
or its index types (HNSW/IVFFlat, deliberately not chosen or tuned here).
No recognition-domain class imports a pgvector-specific concept directly.

**Every similarity operation is scoped by two things, always**:
`family_space_id` (RLS enforces this identically to every other
tenant-owned table) and **compatible embedding-space identity** — the
full `(provider, model_identifier, model_weight_checksum, config_hash)`
tuple ADR-0011 §10 already fixed. Comparing embeddings across two
different identities is never performed, at any confidence level (§11).

**A future Qdrant migration means exporting `face_observations.embedding`
plus its provenance from PostgreSQL and rebuilding the search projection —
never regenerating embeddings, never changing recognition-domain logic.**

**Provisioning** (own its full scope, not left implicit): the local
Docker Postgres image must support the `pgvector` extension (either
switching the base image, e.g. to `pgvector/pgvector:pg17`, or building
the extension into the existing image); `CREATE EXTENSION vector` must run
before, in migration order, the `face_embedding_projections` table
migration that declares a `vector`-typed column; the regression-test
environment for anything touching this table must run against real
PostgreSQL — the project's existing sqlite-by-default business-logic test
path (used elsewhere in this codebase) cannot exercise a pgvector column
at all, so similarity-search tests join the existing Postgres-only test
path (`scripts/test-postgres-rls.sh`'s pattern) rather than the default
suite; production provisioning requires whichever managed PostgreSQL
Phase 17 ultimately selects to support the `pgvector` extension at its
running engine version — a provisioning/version requirement for that
future decision, not a new decision this ADR makes.

This ADR does not choose pgvector merely because Postgres already exists,
and does not choose it in ignorance of Qdrant — it is chosen because the
actual expected query shape (always single-tenant, always one embedding
space, realistically thousands rather than millions of vectors per Family
Space — §17) sits squarely inside what a transactionally-consistent,
RLS-native, already-proven database already does well.

### 3. `FaceIdentityAssignment`: a face-level identity claim, distinct from `PhotoPerson`

```text
PhotoPerson (ADR-0008 §5, unchanged)
= the authoritative/proposed Photo-level fact that a Person
  appears somewhere in a Photo.

FaceIdentityAssignment (new, this ADR)
= the face-level claim that one specific FaceObservation
  represents one specific Person.
```

`face_identity_assignments`: `id`, `family_space_id`, `face_observation_id`,
`person_id`, `proposal_source` (`'human'` default, extended with values
such as `'automatic_suggestion'`/`'cluster_confirmation'` — mirroring
`photo_people.proposal_source`'s own free-form, unconstrained-by-CHECK
shape exactly), **`status` (`pending | approved | rejected | withdrawn`
— see below for `withdrawn`)**, `proposed_by`/`resolved_by` (nullable —
automatic suggestions have no human proposer), `resolved_at`, timestamps.

**A Photo may contain multiple `FaceObservation`s assigned to the same
Person** — exactly what `PhotoPerson`'s single-row-per-`(photo, person)`
constraint cannot represent and `FaceIdentityAssignment` exists to
represent correctly: one row per detected face, however many faces of the
same Person one Photo contains.

**A fourth status, `withdrawn`, represents non-judgmental administrative
retirement of a pending, machine-originated identity assignment — never
rejection, and never specific to one trigger.** A `pending`
`FaceIdentityAssignment` transitions to `withdrawn` whenever a bounded
administrative lifecycle event makes it obsolete without any human ever
forming an opinion about whether it was correct — concretely, at least:
recognition being disabled for the candidate Person (§10), or a Person
merge making the pending candidate obsolete because the Person it named
no longer exists as an independent identity (§12). `withdrawn` means
"this suggestion was retired without any identity judgment being made
about it" — it is **not** equivalent to `rejected`, and transitioning to
it **never** creates a `FaceIdentitySuppression` row (§8): withdrawal
carries no opinion about whether the match was right or wrong, only that
pursuing it further is no longer meaningful. A `withdrawn` row is
permanent, durable history, never deleted; `withdrawn` is naturally
excluded from §4's active-claim uniqueness (which only covers
`pending`/`approved`), so a withdrawn face is immediately free to receive
a fresh assignment the next time normal matching runs. No `withdrawn` row
is ever automatically resurrected by whatever later removed the reason
for its withdrawal — re-enabling recognition (§10) and completing a merge
(§12) both leave prior `withdrawn` rows exactly as they are; a face that
still lacks an active assignment simply becomes eligible for ordinary
candidate generation again, under whatever suppression/calibration rules
already apply (§7, §8), the same as any other unassigned face.

**Automatic suggestion generation, and therefore `FaceIdentityAssignment`
creation, is scoped to `FaceObservation`s whose `MediaUpload` has already
been promoted to a `Photo`.** There is no coherent "ensure `PhotoPerson`"
target (§5) for content that isn't yet a Photo at all.

### 4. `FaceIdentityAssignment` integrity

**At most one active identity claim may exist for a given face.**
`CREATE UNIQUE INDEX face_identity_assignments_active_unique ON
face_identity_assignments (face_observation_id) WHERE status IN
('pending', 'approved')` — the same partial-unique shape
`photo_people_active_unique` already uses, applied at the finer grain. A
rejected or withdrawn historical claim may coexist with a later, correct
one for a different Person.

**Tenant-consistent relational integrity, matching ADR-0011 §8's
discipline exactly**: `face_identity_assignments` (and, identically,
`face_embedding_projections` §2, `FaceIdentitySuppression` §8, and
`face_cluster_generations`/`face_clusters`/`face_cluster_members` §9)
carry an explicit `family_space_id` column, `FORCE ROW LEVEL SECURITY`,
and composite foreign keys — never independent single-column foreign keys
to a bare `id`.

**This reconciliation adds the supporting constraint this composite-FK
discipline actually requires and that the live schema does not yet
have**: `face_observations` does not currently carry `UNIQUE (id,
family_space_id)` — only `media_uploads` received this fix during
ADR-0011's own reconciliation. This ADR requires the identical additive
fix on **both** `face_observations` (`face_observations_id_family_space_unique`)
and `people` (`people_id_family_space_unique`, per the prior draft),
sequenced strictly **before**, in the same migration batch as, every
composite foreign key that depends on either — exactly the ordering
already proven for `media_uploads`. Neither addition changes `id`'s role
as the sole primary key/identity of its table, nor any existing
`FaceObservation` or `Person` lifecycle or identity semantics; it exists
solely to make these composite foreign keys expressible.

### 5. Ensuring `PhotoPerson`: deterministic transitions, never a silent override

**Approving a `FaceIdentityAssignment` ensures the corresponding
`PhotoPerson` row exists, idempotently, resolved through one of four
deterministic transitions — never through an ambiguous "find or create."**
The dependency still runs one way only:

```text
approved FaceIdentityAssignment  →  ensures PhotoPerson exists
PhotoPerson                      →  never requires a FaceObservation
```

The `Photo` is located via the `FaceObservation`'s `face_analysis_run_id →
media_upload_id → Photo.media_upload_id` chain (the same join path
ADR-0010's duplicate detection already uses to reach a Photo from a
`MediaUpload`). Given that Photo and the assignment's `person_id`, exactly
one of the following applies — note that only Owner/Administrator can
reach this step at all, since approving a `FaceIdentityAssignment` is
itself Owner/Administrator-only (§13), so every transition below is
already an instance of deliberate, authorized human action, not an
automatic process acting alone:

- **No `PhotoPerson` row exists for `(photo, person)` at all** (no active
  row, no historical row of any kind): a new `PhotoPerson` is created —
  `status = 'approved'`, `proposal_source = 'face_identity_assignment'`,
  `proposed_by` carried through from the `FaceIdentityAssignment`'s own
  `proposed_by` (nullable if automatic), `resolved_by` set to the actor
  approving the assignment, `resolved_at = now()`. Audited as an ordinary
  `PhotoPerson` confirmation, using the same event vocabulary Phase 6
  already uses for a direct manual confirmation — this path is
  indistinguishable in the audit log from any other confirmation except
  that the `FaceIdentityAssignment`'s own separate audit event
  additionally records the specific face involved.
- **An `approved` `PhotoPerson` already exists**: reused as-is — no new
  row, and its own `resolved_by`/`resolved_at` provenance is left
  untouched, since it may already represent an independent, earlier,
  equally valid confirmation. The `FaceIdentityAssignment` still becomes
  `approved` on its own row regardless.
- **A `pending` `PhotoPerson` already exists**: the same authoritative
  approve action **atomically resolves it to `approved` as part of the
  same transaction** — updating that existing row's `status`,
  `resolved_by`, `resolved_at` — rather than creating a second row, which
  §4's own analogue on `PhotoPerson` (`photo_people_active_unique`) would
  reject in any case. This is not a machine silently converting a human
  proposal: an Owner/Administrator approving the face identity assignment
  already holds, under ADR-0006 §14, exactly the authority to resolve that
  pending `PhotoPerson` directly — this transition is that same authority,
  exercised once, audited as one deliberate action covering both facts.
- **Only a historical `rejected` `PhotoPerson` exists, with no currently
  active row**: this is **not** treated as an eternal block. `PhotoPerson`
  introduces no distinct "durably blocking" marker beyond the ordinary
  `rejected` status, and the schema's own `photo_people_active_unique`
  index already defines "currently active" as `pending`/`approved` only —
  a `rejected` row asserts nothing currently, by construction. A new
  `approved` `PhotoPerson` row is created exactly as in the no-row case,
  and the old `rejected` row is left completely untouched, preserved as
  history. Nothing here is a silent override: the original human
  rejection stands unmodified; a *new*, separate, later human decision —
  made by an Owner/Administrator exercising the same reconsideration
  authority ADR-0006 §4 already grants them over any Person/relationship
  identity claim — creates a new fact alongside it.

No other case exists: these four transitions are exhaustive over
`PhotoPerson`'s own three-status lifecycle crossed with "row exists or
not," so no ambiguous "surface a conflict for later" state is needed
anywhere in this flow.

### 6. Trusted Person gallery

**No permanent centroid, ever.** A Person's trusted recognition gallery
is not a separately stored, independently-authoritative structure — it is
**derived, live**, from currently-`approved` `face_identity_assignments`
joined to their `face_observations`' embeddings (via `face_embedding_projections`
for the search operation itself, §2). Reversing or correcting an approval
(§5, §8) changes the gallery automatically, because nothing about it was
ever cached. This preserves the full diversity of a Person's confirmed
appearance across age and era, rather than blending it into one point a
single unusual or mistaken confirmation could distort.

**Reference count contributes to confidence, it does not gate
eligibility.** A Person with exactly one confirmed reference is not
excluded from recognition — that single reference may participate, but
only in cautious/shortlist candidate behaviour (§7); multiple independent
confirmed references may support the stronger, single-candidate band.
Exact counts and score cutoffs are calibration (§16), not fixed here.

**Aggregation** (best-match-against-every-confirmed-observation vs. a
top-k average) is likewise left to calibration unless real evidence
resolves it during implementation — the architectural commitment is that
matching runs against the *gallery*, never against a collapsed summary of
it.

### 7. Candidate suggestion generation and confidence bands

A `FaceObservation` without an active `FaceIdentityAssignment` is
compared, within its compatible embedding space (§11), against every
Family Space Person's trusted gallery (§6) not currently suppressed for
that face (§8) and not excluded from recognition (§10). The result is one
of three conceptual bands — no single binary threshold:

- **Stronger, single-candidate suggestion**: confident enough that the
  system commits to creating one `pending` `FaceIdentityAssignment` for
  the single best-matching Person, awaiting human confirmation.
  Confirmation is always required — a high-confidence score never
  auto-approves.
- **Cautious/ranked shortlist**: several Persons plausible enough to
  show, none confident enough to commit to a row. **A shortlist is
  ephemeral suggestion output, computed at review time, never persisted
  as multiple competing `FaceIdentityAssignment` rows** — §4's partial
  unique index deliberately allows only one active claim per face. Only
  when a human actually selects one candidate from a shown shortlist does
  exactly one `FaceIdentityAssignment` row get created.
- **No suggestion**: scores too low, or too close together to distinguish
  — most importantly the sibling/parent-child/twin case. When candidates
  are too close, the system prefers showing a shortlist, or nothing, over
  guessing.

This directly operationalises the roadmap's own fixed safety principle:
precision over recall, "I don't know" preferred over a false "this is
William."

### 8. Rejection and suppression

When a human rejects a suggestion — whether an actual `pending`
`FaceIdentityAssignment` or a shown-but-never-persisted shortlist
candidate — a durable suppression record is written, modelled directly on
`DuplicateDecision` (ADR-0010 §9), applied to `(face_observation_id,
person_id)`:

```text
FaceIdentitySuppression
- family_space_id
- face_observation_id
- person_id
- decided_by
- decided_at
- reopened_by   (nullable)
- reopened_at   (nullable)
```

Unique on `(family_space_id, face_observation_id, person_id)` — one row
per pair, ever — reused, not duplicated, on reconsideration. Both
automatic candidate generation (§7) and any future re-suggestion consult
this table first and never surface, or let a human re-approve without
deliberate reopening, a currently-suppressed pair. **Owner or
Administrator alone may reopen a suppressed pair, as an audited state
transition on the same row.** **Withdrawal (§3) never creates or touches
a `FaceIdentitySuppression` row, regardless of what triggered it** — the
two are deliberately distinct mechanisms for deliberately distinct facts
(a human judgment vs. a non-judgmental administrative retirement).
**Phase 10 needs only this backend/functional capability** — a polished
suppression-management surface belongs to Phase 14.

### 9. Unknown-face clustering, with an enforceable lifecycle

```text
face_cluster_generations              face_clusters                          face_cluster_members
- id                                   - id                                   - id
- family_space_id                      - family_space_id                     - family_space_id
- status (building|active|superseded)  - clustering_generation_id            - face_cluster_id
- activated_at                         - status (active|retired|superseded)  - face_observation_id
- superseded_at                        - created_at / updated_at             - is_active
- created_at                                                                  - created_at
```

Clusters are **purely machine-derived, mutable, and rebuildable without
image inference**, restricted to `FaceObservation`s with no currently-
active `FaceIdentityAssignment`. **`face_clusters` carries no identity
field of any kind** — no candidate-Person label, no provisional name —
precisely so a cluster can never become, even informally, an
authoritative identity structure. Any "this group might be the same
person as..." hint a review surface shows is computed live from member
observations' own suggestion scores, never stored on the cluster itself.

**"Active" is enforceable at the database level, not prose — and this
reconciliation distinguishes two genuinely different ways a cluster stops
being active.** `face_clusters.status` is `active | retired | superseded`:

- **`active`** — the cluster's current, operational state within the
  currently-active clustering generation.
- **`retired`** — a **human-driven**, terminal state: a family member
  named the cluster as a Person, or otherwise deliberately retired it
  through review. Audited (§14).
- **`superseded`** — a **machine-driven** state: a newer clustering
  generation replaced this cluster's grouping. This is **not**, and must
  never be treated as, a human identity judgment, and is **not** audited
  the way human retirement is (§14) — it is ordinary automatic clustering
  bookkeeping, exactly like generating the cluster in the first place.

**At most one active membership per `FaceObservation`** is enforced by
`UNIQUE (face_observation_id) WHERE is_active = true` on
`face_cluster_members`, mirroring the partial-unique idiom already used
for `PhotoPerson` and `FaceIdentityAssignment`. Moving a face — via merge,
split, retirement, or supersession — flips its old membership row's
`is_active` to `false` (never deletes it) and, if applicable, inserts a
new row with `is_active = true` for its new cluster. **Neither retirement
nor supersession ever deletes historical membership.**

**Clustering generations make "one operational generation at a time"
enforceable, not implicit.** `face_cluster_generations` carries its own
`status` (`building | active | superseded`), with
`UNIQUE (family_space_id) WHERE status = 'active'` — at most one active
generation per Family Space, ever; `face_clusters.clustering_generation_id`
is a tenant-consistent composite foreign key against it.

**Build input — a rebuild reconsiders the whole eligible population, not
only leftovers.** A new generation's candidate population is every
recognition-eligible `FaceObservation` for the current Family Space and
the current compatible embedding-space identity (§11) that has **no
currently-active `FaceIdentityAssignment`** — full stop. **Current active
membership in the generation being replaced does not itself exclude an
observation from this population**: a rebuild is a candidate-replacement
view of the same eligible population, not an incremental pass over
whatever the previous generation happened to leave unclustered. Excluding
already-actively-clustered faces from consideration would defeat the
entire purpose of rebuilding — a rebuild exists precisely to let the
active generation's own groupings be reconsidered as similarity evidence
accumulates or a clustering approach improves.

**Human-retired protection is the one thing that *does* survive a
rebuild, and it is not the same mechanism as "has an active
assignment."** An observation with any membership history — under any
generation, `active`, `superseded`, or otherwise — in a cluster whose
`status` is currently `retired` is **permanently excluded** from every
future rebuild's candidate population, independent of whether that
retirement happened to also create a `FaceIdentityAssignment` for it. This
is what distinguishes "a family member reviewed this grouping and
deliberately resolved or dismissed it" (protected, permanent) from "this
grouping simply hasn't been superseded by a newer rebuild yet"
(unprotected — membership in a merely-`superseded` or still-`active`
cluster carries no such protection). An automatic rebuild may supersede
machine-derived groupings freely; it may never silently undo a human
review decision recorded as `retired`.

**Membership created during a build is non-operational by construction,
not by a separate flag or table.** Every `face_cluster_members` row a
building generation creates is inserted with `is_active = false` — it is
not "active but hidden," it simply does not satisfy the one condition
(`is_active = true`) that suggestion generation, cluster review,
merge/split, and this table's own partial-unique enforcement all key off.
Because of this, a building generation's memberships can safely coexist,
mid-build, with the current active generation's own active memberships
for the very same `FaceObservation`s — the
`UNIQUE (face_observation_id) WHERE is_active = true` index above is
never at risk of a false conflict, since only one side of that pair is
ever `true` at a time, and building-side rows start out `false`.

**Atomic activation — the only moment a build's output becomes real —
runs as one transaction, once the build has fully succeeded**:

1. lock and re-validate the currently-active generation (guards against a
   concurrent rebuild racing this one);
2. deactivate the previous generation's active memberships
   (`is_active: true → false`) for every cluster that is currently
   `active` under it — never touching a cluster that is already `retired`;
3. transition those same previously-`active` clusters to `superseded`
   (again, never touching anything already `retired`);
4. transition the new generation `building → active`;
5. activate the new generation's own memberships (`is_active: false →
   true`);
6. transition the previous generation itself `active → superseded`.

Because step 2 (deactivate old) always completes before step 5 (activate
new) within the same transaction, no committed — or even
statement-boundary-visible — state ever holds two active memberships for
one `FaceObservation`, and the membership table's own partial-unique index
enforces exactly the correct post-transition invariant without needing to
change shape at all.

**Failure semantics**: if a build fails at any point before this
six-step transaction runs, the failed generation simply never reaches
`active` — the currently-active generation and its memberships are never
touched, remain fully operational throughout, and the failed/`building`
generation is left inert (or cleaned up) without ever partially applying.

This guarantees, by construction: at most one operational (`is_active =
true`) membership exists per `FaceObservation` for the active generation
at any time; every active membership references an `active` cluster
within the active generation; a building generation's memberships are
non-operational and participate in nothing user-facing; a `superseded`
cluster's memberships are historical and inactive, never deleted; a
`retired` cluster is human-resolved and inert, permanently distinct from,
and never reachable by, machine supersession; automatic generation
replacement may supersede machine-derived clusters freely but may never
perform human identity resolution or override a `retired` protection; and
`FaceObservation` rows are never touched by any of this. **Merge and split
act only on `active` clusters in the current generation** — `retired` and
`superseded` clusters remain permanently inert, inspectable history.

**Identifying a cluster as a Person resolves entirely into ordinary,
human-governed `FaceIdentityAssignment`s** (and, through §5, `PhotoPerson`
facts) — a bulk action creating/approving one `FaceIdentityAssignment` per
member observation, subject to every rule already fixed above, followed
by the cluster's own **human-driven, audited** retirement as described.
`FaceObservation` rows are never touched by any cluster operation, of
either kind.

### 10. Recognition consent and exclusion

`people` gains `recognition_allowed`, a `NOT NULL DEFAULT false` boolean.
**Because this is a schema-level default, not merely an application-level
one, adding the column with `NOT NULL DEFAULT false` automatically
backfills every existing `Person` row to `false` the moment the migration
runs — not only rows created afterward.** No separate backfill script is
required; the ordinary migration already produces the correct outcome for
every Person that predates this ADR. Changing the value is Owner/
Administrator-only, under ADR-0006 §14's existing authoritative-change
gate. **Manual `PhotoPerson` tagging, and manual `FaceIdentityAssignment`
creation, remain fully available regardless of this setting.**

When recognition is disabled for a Person:

- no new suggestions are generated naming them (§7's matching step
  excludes their gallery entirely);
- any still-`pending` `FaceIdentityAssignment` naming them transitions to
  `withdrawn` (§3) — **not** `rejected`, and **without** creating a
  `FaceIdentitySuppression` row;
- they are removed from the active similarity-matching gallery (§2's
  scoping);
- **already-`approved` `FaceIdentityAssignment`s are never deleted,
  withdrawn, or altered**;
- **human-confirmed `PhotoPerson` facts are never touched**;
- **`FaceObservation`s and their embedding provenance are never
  destroyed**.

**Audit**: the Owner/Administrator's consent change is audited once, as
one deliberate action; the mechanical withdrawal of however many
dependent `pending` assignments results from it is recorded as part of
that same audited action rather than one audit event per withdrawn row —
directly mirroring how ADR-0010 §9 already treats a multi-match "Create a
new Photo" as one audited decision that happens to write several
`DuplicateDecision` rows at once, not several independent decisions.

An unknown cluster informally associated with an excluded Person's
suggested identity loses that association (§9's identity hints are
computed live and simply stop being computed for an excluded Person); the
cluster's membership may persist anonymously.

### 11. Model/version coexistence: one active embedding profile

**Recognition operates against exactly one compatible embedding-space
identity at a time — the full `(provider, model_identifier,
model_weight_checksum, config_hash)` tuple `config/image-analysis.php`
already records as Phase 9's active generation identity.** No separate,
Phase-10-owned "active profile" concept is introduced.

`FaceObservation`s from a superseded identity are never mixed into the
same similarity calculation as the current one. **Changing the active
identity uses Phase 9's already-existing, explicit, bounded, audited
reprocessing trigger (ADR-0011 §15) — this ADR invents no automatic
migration and no mass rescanning.**

### 12. Person-merge integration — extending `PersonMergeManager`, not replacing it

**This is an explicit extension of the existing, already-shipped merge
mechanism** (`PersonMergeManager::merge()`/`captureState()`/
`restoreState()`/`statesMatch()`/`reverse()`), not a new integration
concept. `face_identity_assignments` and `FaceIdentitySuppression` are
added to the same before/after snapshot the merge transaction already
captures for `photo_people` and the rest, so guarded automatic reversal
continues to work exactly as it already does — if the captured "after"
state no longer matches current state at reversal time, the merge falls
back to `ManualCorrectionRequired`, unchanged.

- **`face_identity_assignments.person_id`** naming the absorbed Person is
  handled according to its own status, never uniformly. **`approved`,
  `rejected`, and already-`withdrawn`** rows are **unconditionally
  repointed** to the survivor — each is inert history or an already-
  confirmed fact, so repointing the `person_id` field asserts nothing new.
  No collision is possible at this table's own grain for these rows
  either: §4's partial-unique index restricts at most one *active* claim
  per face, independent of which Person it names, and a repointed
  `rejected`/`withdrawn` row is not active by definition.

  **A still-`pending` row is handled in two ordered steps, not skipped and
  not left with a dangling reference.** First, its complete pre-merge
  state — the original `person_id` naming the absorbed Person, and its
  original `pending` status — is captured in the merge's existing
  before-state snapshot, exactly as every other reconciled row already is
  (below). Second, the row is transitioned from `pending` to `withdrawn`
  (§3). **Only after that transition** is its `person_id` repointed to the
  survivor, purely to satisfy relational integrity — no live Phase 10 row
  may retain a foreign key to the absorbed Person once the merge
  completes, and this row is no exception. **Repointing the foreign key
  is not the same thing as repointing the suggestion's meaning.** A
  `withdrawn` row's `person_id` now naming the survivor never means, and
  is never read as, "this face might be the survivor" — §6's gallery
  derivation and §7's candidate generation only ever act on
  `pending`/`approved` rows, so a `withdrawn` row's `person_id` is inert
  bookkeeping, not a live claim, exactly the same as any other
  `withdrawn`/`rejected` row's `person_id` already is. An earlier draft's
  wording — "pending assignments are never repointed" — was too absolute
  and directly contradicted the separate, non-negotiable requirement that
  no live Phase 10 row may retain an absorbed-Person foreign key. The
  corrected, narrower rule is: **pending assignments are never
  automatically transformed or reactivated as suggestions for the merge
  survivor** — their foreign key is still reconciled to the survivor, but
  only once withdrawn, and in a form (`withdrawn`, not `pending`) no
  automatic process can ever read back as an active candidate.

  The reason a pending assignment is withdrawn first, rather than simply
  repointed the way an approved one is, remains the same semantic one:
  it records an *unvalidated machine opinion*, generated by comparing one
  `FaceObservation` against one specific Person's trusted gallery (§6) at
  the moment it was created. Once that Person is merged away, the gallery
  it was actually compared against no longer exists as an independent
  thing — leaving the row `pending` and merely repointing it would assert
  a machine opinion about a comparison ("is this the survivor?") that was
  never actually run. Withdrawing it first removes that stale opinion from
  ever being read as live, before its bookkeeping reference is reconciled
  for integrity's sake alone. Both this two-step path and the
  unconditional-repoint path are captured in, and restorable from, the
  merge's existing snapshot exactly like every other reconciled table.
- **The realistic, constructable conflict this reconciliation uses in
  place of an impossible single-face scenario**: Photo P has one
  `FaceObservation` confirmed, via `FaceIdentityAssignment`, as the
  absorbed Person, and a *different* `FaceObservation` in the same Photo
  confirmed as the survivor. Repointing both `approved` assignments to
  the survivor is fine at the assignment table (different
  `face_observation_id`s, no collision there) — but it reveals that
  `PhotoPerson (photo=P, person=survivor)` may already have two
  independent sources of confirmation, one originally reached through
  each side. **This is exactly the collision
  `PersonMergeManager::reconcilePhotoProvenance()` already resolves
  deterministically today** (approved beats pending; where both sides
  already hold an active claim, the absorbed side's `PhotoPerson` is
  demoted to `rejected` rather than duplicated) — unmodified by this ADR.
- **`FaceIdentitySuppression` reconciles entirely to the survivor — no
  live row may be left pointing at the absorbed Person, under any
  circumstance.** Every `FaceIdentitySuppression` row naming either side
  is added to the merge's existing before-state snapshot (§12's extension
  of `captureState()`), the same way `family_circle_people`/
  `person_account_links` are already captured today. Where no
  survivor-side suppression exists for a given face, the absorbed-side row
  is simply **repointed** — a plain, in-place `person_id` update, exactly
  like the assignment table's own repoint path. **Where a survivor-side
  suppression already exists for that same face** — a face independently
  rejected as the absorbed Person at one point and separately rejected as
  the survivor Person at another, which would otherwise collide with the
  table's own per-pair uniqueness — **the absorbed-side row is deleted
  from the live table outright, never left pointing at the absorbed
  Person.** Nothing is lost: its full pre-merge state is already part of
  the captured snapshot, using exactly the delete-then-reinsert-from-
  snapshot shape `PersonMergeManager::restoreState()` already uses for
  `family_circle_people` and `person_account_links` (`whereIn(...)->delete()`
  followed by re-`insert()` from the snapshot on reversal) — this ADR does
  not invent a new reversal shape, it reuses one this mechanism already
  has. On reversal, if the merge is still validly reversible, the deleted
  row is reinserted exactly as captured and the survivor's own untouched
  suppression is confirmed unchanged by `statesMatch()`, restoring both
  sides to their pre-merge state.
- **`face_cluster_generations`/`face_clusters`/`face_cluster_members`
  require no reconciliation at all**, because none of them carries a
  Person reference (§9) — a direct, intended benefit of keeping clusters
  identity-free.
- **Reversal of a withdrawn-then-repointed pending assignment restores
  from the snapshot, never from the withdrawn row's own current state.**
  If the merge is later successfully reversed through the existing guarded
  reversal mechanism (`PersonMergeManager::reverse()`), the row's original
  `person_id` (naming the absorbed Person) and its original `pending`
  status are both restored directly from the captured before-state
  snapshot — an ordinary field update on the still-existing row, the same
  shape `restoreState()` already uses for `photo_people`/`relationships`.
  Restoration is never *inferred* from the row's post-merge
  (`withdrawn`, survivor-`person_id`) values alone, because those values
  were never meant to reconstruct history — only the snapshot was. If
  reversal is blocked (`statesMatch()` finds the captured "after" state no
  longer matches current state), the merge falls back to
  `ManualCorrectionRequired`, unchanged, exactly as it already does for
  every other reconciled table.
- **No Phase 10 structure may retain a foreign key to the absorbed Person
  once the merge transaction completes — without exception.** Preservation
  of anything that would otherwise dangle happens entirely inside the
  merge's own before/after snapshot history, never by leaving a live
  reference in an ordinary table; the withdrawn-pending-assignment case
  above and the `FaceIdentitySuppression` collision case are the two
  places this reconciliation had to make that distinction explicit.

This closes the ADR-0006 §12 obligation without redesigning Person merge
itself — every new behaviour here is an addition to the existing
capture/reconcile/restore transaction, not a parallel mechanism.

### 13. Authorization

| Action | Owner | Administrator | Member | Contributor | Guest |
|---|---:|---:|---:|---:|---:|
| View suggestions/shortlists for Photos already visible to them | yes | yes | yes | no default | no |
| Propose a `FaceIdentityAssignment` (manually pick a candidate; accept a shortlist entry as worth reviewing) | yes | yes | yes | no | no |
| Confirm (approve) a `FaceIdentityAssignment` as authoritative | yes | yes | no | no | no |
| Reject a suggestion, creating durable suppression | yes | yes | no | no | no |
| Reopen a suppressed pair | yes | yes | no | no | no |
| Browse unknown-face clusters | yes | yes | yes | no | no |
| Propose naming a cluster as a Person | yes | yes | yes | no | no |
| Merge/split clusters; confirm (bulk-approve) a cluster naming | yes | yes | no | no | no |
| Change a Person's `recognition_allowed` state | yes | yes | no | no | no |

A direct application of ADR-0006 §4/§14's already-fixed pattern (Member
proposes, Owner/Administrator confirms or resolves as authoritative) and
ADR-0010 §10's already-fixed shape for machine-suggestion review — no new
authorization concept anywhere in this table, and no Member authority
beyond viewing and proposing.

### 14. Audit and telemetry

Automatic similarity scoring and automatic clustering are derived
operational computation, not human decisions, and generate **no** audit
rows — only OpenTelemetry metrics (candidate counts, scoring latency,
cluster sizes; never a score against a named Person, never an embedding
value). **A clustering generation superseding the previous one (§9) is
likewise not audited** — no human decision occurs when a newer generation
replaces an older one's still-active clusters; it is the same automatic
clustering bookkeeping as generating the clusters in the first place,
tracked only via OpenTelemetry (generation id, clusters superseded,
clusters left untouched because already human-`retired`). `AuditEvent`
records: a `FaceIdentityAssignment` proposed, approved, or rejected (with
its suppression record, if any); a suppression reopened; a cluster's
**human-driven** retirement via naming, and any merge/split of `active`
clusters; a Person's `recognition_allowed` state changed, including the
bundled withdrawal of dependent pending suggestions (§10) as part of that
one event. **A Person merge's own audit event (`person.merged`) likewise
already covers the bundled repointing/withdrawal of Phase 10 rows §12
performs** — no separate audit row is written per repointed or withdrawn
`face_identity_assignments`/`FaceIdentitySuppression` row.

### 15. Deletion, soft-delete, and reprocessing interactions

| Event | Outcome |
|---|---|
| Photo soft-deleted | `FaceIdentityAssignment`/suppression/cluster-membership rows referencing its faces are left untouched, simply not surfaced while trashed. |
| Photo restored | The same untouched state becomes reachable again; nothing is resurrected because nothing was removed. |
| Active embedding identity changes (§11) | Old-identity `FaceObservation`s stop participating in active matching; nothing referencing them is deleted or altered. |
| Person merged (§12) | Explicit, atomic reconciliation via `PersonMergeManager`; no independent Person deletion path exists (ADR-0006 §16). |
| Recognition disabled for a Person (§10) | Exactly the withdrawal/exclusion behaviour specified there; no historical fact is destroyed. |

### 16. Calibration gate and safe-activation boundary

**No recognition threshold, confidence-band cutoff, minimum-reference
count, or clustering-similarity threshold is fixed by this ADR.** Phase
9's `0.6` is a detection-confidence threshold and **must not be reused**
as a recognition/matching threshold. Before any such number is accepted,
the private benchmark corpus must be extended to cover: the same Person
across approximately 20/40/60+ years of age; child-to-teen-to-adult
progression; confirmed siblings; confirmed parent/child resemblance; old,
faded, or damaged scans; confirmed appearance changes; more children and
older adults; extreme profiles and heavy occlusion.

**A calibration number existing in a placeholder config value must never
translate into a live decision about real family data before it is
accepted.** `FPA-P10-S03`/`S04` implement clustering and candidate-
generation *machinery* against conservative placeholder thresholds — but
that machinery is not permitted to run automatically over ordinary Family
Space content until calibration is accepted. Concretely:

```text
config/face_recognition.php
- processing_enabled     (bool, default false)
- calibration_profile    (nullable string identifier)
```

- **While `processing_enabled` is `false`** (the state throughout `S03`
  and `S04`), automatic clustering and automatic `FaceIdentityAssignment`
  suggestion generation do not run against real Family Space data at all.
  `S03`/`S04` exercise their own machinery directly against the private
  benchmark corpus and explicitly isolated development fixtures, and
  through deterministic unit/integration tests that call the underlying
  services directly rather than through the automatic-dispatch path —
  never through the gate.
- **`FPA-P10-S07`** is the only stage that calibrates real threshold
  values from the extended benchmark, records them as a named
  `calibration_profile`, and — as an explicit, separate, operational step,
  not an automatic side effect of finishing calibration work — sets
  `processing_enabled = true` once that profile is accepted. Only from
  that point does automatic Phase 10 processing begin against ordinary
  Family Space content.

This mirrors, at the phase level, exactly the discipline ADR-0010 §8 and
ADR-0011 §18 already applied to their own pre-implementation calibration
gates — the difference here is that Phase 10's gate also has to keep
already-implemented automatic machinery switched off, not merely defer a
number.

### 17. Scale and Phase 10 vs. Phase 14 UI boundary

**Scale**: a realistic Family Space carries tens of Persons, realistically
single- to low-double-digit confirmed references per Person, and total
`FaceObservation`s bounded by however many photos that family has had
analysed — thousands, not millions, always queried one tenant at a time.

**UI boundary**: Phase 10 builds enough functional UI to exercise and
verify viewing a suggestion, confirming/rejecting identity, reviewing and
naming clusters, splitting/merging clusters, and toggling recognition
consent/exclusion — "functional, not polished," the same principle
`PROJECT_ROADMAP.md` already fixes for every phase before Phase 14's
integration pass. Review-surface wording should avoid authoritative
phrasing implying certainty the system does not have; exact copy and
visual design belong to Phase 14.

## Alternatives considered

- **Adding a nullable `face_observation_id` directly to `PhotoPerson`**
  — rejected on a concrete cardinality ground: `photo_people_active_unique`
  cannot represent the same Person appearing as two distinct detected
  faces in one Photo.
- **A pgvector column added directly to `face_observations`** — rejected:
  would either violate ADR-0011 §13's immutability guarantee the moment
  the column needed rebuilding/re-encoding, or require a narrow,
  ADR-level exception to that guarantee. A separate, disposable
  `face_embedding_projections` table (§2) achieves the same searchability
  with zero tension against immutability, and recursively models the same
  "authoritative source, disposable projection" relationship this ADR
  already requires between PostgreSQL and any future Qdrant backend.
- **Relaxing `photo_people_active_unique`** — rejected: retroactively
  modifies an already-accepted Phase 6 invariant rather than extending
  Phase 6 additively.
- **Persisting a shortlist as multiple simultaneous `pending`
  `FaceIdentityAssignment` rows** — rejected: would require relaxing §4's
  one-active-claim-per-face constraint for the common case.
- **A "surfaced conflict, resolved later" state for the rejected-
  `PhotoPerson`/pending-`PhotoPerson` cases (§5)** — considered in an
  earlier draft, and rejected in this reconciliation: every case actually
  resolves deterministically once "currently active" is read directly off
  the schema's own partial-unique definition, and once it's recognised
  that approving a `FaceIdentityAssignment` is already an Owner/
  Administrator action carrying the authority to resolve `PhotoPerson`
  directly — no ambiguous intermediate state is needed.
- **Using `rejected` for consent-driven withdrawal of pending suggestions**
  — rejected: `rejected` carries a human identity judgment and interacts
  with suppression; a Person being excluded from recognition asserts
  nothing about whether any specific past suggestion was actually wrong. A
  distinct `withdrawn` status (§3) keeps the two meanings separate.
- **Deleting withdrawn or superseded rows instead of keeping them as
  durable history** — rejected outright, consistent with this project's
  standing preference for preservation over destruction everywhere else.
- **A single centroid, or one centroid per age/appearance cluster, per
  Person** — rejected per settled product direction.
- **A hard minimum-reference-count gate excluding a Person from
  recognition entirely** — rejected per settled product direction;
  reference count feeds confidence, not eligibility.
- **Choosing pgvector or Qdrant without an abstraction boundary** —
  rejected: would make a future backend change a recognition-domain
  rewrite rather than a swapped implementation.
- **Selecting Qdrant now, in anticipation of Phase 18 semantic-search
  reuse** — rejected: no measured workload justifies it yet, and no
  Qdrant service exists anywhere in this repository's infrastructure.
- **Reusing Phase 9's `0.6` detection threshold as a recognition
  threshold** — rejected outright.
- **Freezing specific confidence-band cutoffs, minimum-reference counts,
  or clustering thresholds in this ADR** — rejected; the named benchmark
  gaps are real and unfilled.
- **Letting `S03`/`S04`'s clustering/suggestion machinery run against real
  Family Space data before calibration is accepted** — rejected in this
  reconciliation: a placeholder threshold must never silently become a
  live default; §16's `processing_enabled` gate exists specifically to
  prevent this.
- **Letting automatic clustering or candidate generation itself reopen a
  suppressed pair** — rejected outright, mirroring ADR-0010 §9.
- **A lighter, Member-level authorization for cluster merge/split** —
  considered and rejected for consistency and caution; corrected in this
  reconciliation to remove wording that had drifted from this decision
  (§1, §13).
- **Marking a `FaceCluster`'s "active" state as prose/convention only,
  with no enforced database invariant** — rejected in this reconciliation:
  the same partial-unique-index idiom already proven for `PhotoPerson`
  and `FaceIdentityAssignment` makes "one active membership per face"
  enforceable at the database level for negligible additional schema cost.
- **Leaving a colliding `FaceIdentitySuppression` row live, pointed at the
  tombstoned absorbed Person, to preserve its history** — an earlier
  draft's approach; rejected in this reconciliation because it directly
  contradicted the same draft's own absolute invariant that no Phase 10
  live structure may retain a foreign key to the absorbed Person after
  merge. Deleting the live colliding row while preserving its full state
  in the merge's existing before/after snapshot achieves the same
  history-preservation goal — using a delete-then-reinsert-from-snapshot
  shape `PersonMergeManager::restoreState()` already implements today for
  `family_circle_people`/`person_account_links` — without ever leaving a
  live dangling reference.
- **Treating a Person-merge-driven pending-assignment retirement as a
  uniqueness-constraint conflict** — an earlier draft's justification;
  corrected in this reconciliation: `face_identity_assignments_active_unique`
  is keyed by `FaceObservation`, not by Person, so no such conflict
  actually exists. The real reason a pending assignment must be withdrawn
  *before* its foreign key is reconciled to the survivor is semantic — it
  represents a machine opinion computed against a gallery that no longer
  independently exists — not a constraint violation.
- **Leaving a withdrawn-then-repointed pending assignment's `person_id`
  pointed at the absorbed Person, to avoid conflating "repointed" with
  "reactivated"** — an earlier draft's approach; rejected in this
  reconciliation because it directly violates the absolute invariant that
  no live Phase 10 row may retain a foreign key to the absorbed Person
  after merge. The correct distinction is not *whether* the foreign key is
  repointed, but *when* (only after withdrawal) and *what a withdrawn row
  means* (inert bookkeeping, never an active suggestion) — not leaving a
  dangling reference.
- **A second, parallel non-judgmental-retirement status specific to
  Person merge**, rather than reusing `withdrawn` — rejected: both
  consent-driven exclusion and merge-driven obsolescence retire the same
  kind of thing (a pending machine opinion, for an administrative reason,
  with no identity judgment involved); one generally-defined `withdrawn`
  status (§3) covers both without duplicating identical semantics under
  two names.
- **Two cluster states (`active`/`retired`) with no distinct machine-
  driven supersession state** — an earlier draft's shape; rejected in this
  reconciliation: it could not distinguish a human's deliberate retirement
  from a machine rebuild silently replacing a still-unreviewed cluster,
  and gave no enforceable way to guarantee only one clustering generation
  is ever operational at a time.
- **A single `clustering_generation` label column on `face_clusters`, with
  no separate generation table** — an earlier draft's shape; rejected in
  this reconciliation in favour of a small `face_cluster_generations`
  table, specifically because activating a new generation needs to be one
  atomic transaction over a well-defined set ("every cluster in the
  previous active generation") — an invariant
  (`UNIQUE(family_space_id) WHERE status = 'active'`) a bare label column
  cannot express or enforce.
- **A parallel, Phase-10-specific merge mechanism**, rather than extending
  `PersonMergeManager`'s existing capture/restore transaction — rejected:
  the existing mechanism already reconciles `PhotoPerson` correctly and
  already supports guarded reversal; duplicating it would risk the two
  mechanisms drifting out of sync with each other.

## Consequences

### Positive

- `FaceIdentityAssignment` closes the actual cardinality gap Phase 9's
  discussion deliberately deferred, without touching ADR-0008's already-
  shipped `PhotoPerson` schema, authorization, or audit behaviour at all.
- Keeping the pgvector representation in a disposable projection table,
  never on `FaceObservation` itself, means ADR-0011 §13's immutability
  guarantee needs no exception anywhere in this ADR.
- The deterministic `PhotoPerson`-transition rules (§5) replace an
  ambiguous "find or create" with four exhaustive, testable cases, closing
  a real correctness gap before any code is written against it.
- Extending `PersonMergeManager`'s existing capture/restore mechanism
  (§12), rather than inventing a parallel one, means Phase 10's merge
  integration inherits guarded reversal for free and cannot silently drift
  from how every other Person-referencing domain already behaves under
  merge.
- Making cluster "active" state enforceable at the database level (§9),
  and distinguishing human-driven `retired` from machine-driven
  `superseded`, closes a gap between what the ADR's prose claimed and what
  the schema could actually guarantee — an unreviewed cluster is never
  silently treated as though a human had judged it, and a rebuild can
  proceed without asking a human's permission first.
- Deleting-with-snapshot for the `FaceIdentitySuppression` merge collision
  (§12), rather than leaving a live absorbed-Person reference, makes "no
  Phase 10 live structure retains a foreign key to the absorbed Person
  after merge" unconditionally true, closing a real internal contradiction
  an earlier draft had between its own §12 prose and its own stated
  invariant.
- The `processing_enabled` gate (§16) means the calibration requirement
  this ADR already stated in principle is now something a test can
  actually assert, not merely something later stages are trusted to
  respect.

### Negative

- `face_embedding_projections`, `face_identity_assignments`,
  `face_clusters`/`face_cluster_members`, and `FaceIdentitySuppression`
  are four genuinely new tables (plus supporting constraints on
  `face_observations` and `people`) — real, if justified, additional
  schema surface, larger than the previous draft's three-table shape.
- The `FaceIdentitySuppression` merge-collision path (§12) is a
  delete-plus-snapshot operation rather than a bare field update — more
  machinery than a simple repoint, though it reuses a shape
  (`family_circle_people`/`person_account_links`) `PersonMergeManager`
  already has, rather than inventing a new one.
- `face_cluster_generations` is a fourth new table this reconciliation
  adds (fifth counting `face_embedding_projections`), specifically to
  make generation activation/supersession one atomic, enforceable
  transaction — real additional schema surface, chosen because a bare
  label column could not give the same guarantee.
- The `processing_enabled` gate is one more piece of operational state to
  set correctly and remember to flip — a real, if small, operational
  responsibility for whoever runs `FPA-P10-S07`.

### Risks

- If `face_observations`/`people` do not receive their additive
  `UNIQUE (id, family_space_id)` constraints (§4) before the composite
  foreign keys that depend on them, those migrations fail outright rather
  than silently — worth sequencing correctly in the same migration batch,
  mirroring `media_uploads`' own fix.
- If a projection rebuild (§2) is ever implemented as an update to
  `face_observations` rather than to `face_embedding_projections`, ADR-0011
  §13's immutability guarantee is silently broken — worth a direct test
  asserting `face_observations.embedding` never changes across a
  projection rebuild.
- If recognition-domain code is ever written against pgvector-specific SQL
  directly, the "Qdrant is a rebuildable projection, never a rewrite"
  guarantee would be false in practice.
- If the four `PhotoPerson`-transition cases (§5) are implemented as a
  single ambiguous upsert rather than the explicit branches specified,
  a pending human proposal could be silently approved, or a rejected
  history row could be misread as a permanent block — worth a direct test
  for each of the four cases.
- If Person-merge (§12) is implemented without extending
  `PersonMergeManager`'s actual `captureState`/`restoreState`, a merge
  reversal could silently fail to restore Phase 10 state even though it
  correctly restores everything Phase 6 already owns — worth a direct
  test merging two Persons with confirmed faces and suppressions, then
  reversing, and asserting Phase 10 state matches pre-merge state exactly.
- If the `FaceIdentitySuppression` merge-collision rule (§12) is
  implemented as "repoint and let the unique constraint fail," the merge
  transaction breaks instead of completing; if it is instead implemented
  as "leave the colliding row live, pointed at the absorbed Person," the
  absolute no-dangling-FK invariant is silently violated — worth a direct
  test constructing the two-independent-rejections-before-merge scenario
  and asserting zero live Phase 10 rows reference the absorbed Person's id
  afterward, not merely that the merge transaction completes.
- If a merge-driven pending-assignment retirement is ever implemented as a
  bare repoint "for simplicity" — skipping the `withdrawn` transition
  entirely, or repointing before capturing pre-merge state — a stale,
  never-actually-computed machine opinion could survive the merge still
  `pending` and be presented as though it meant something about the
  survivor, or its original absorbed-Person state could become
  unrecoverable on reversal — worth a direct test asserting a pending
  `face_identity_assignments` row naming the absorbed Person is
  `withdrawn` *before* its `person_id` is repointed, and that its
  pre-merge `person_id`/status are both present in the merge snapshot.
- If cluster-generation supersession (§9) is ever implemented so that it
  touches a `retired` cluster — flipping it to `superseded` or reopening
  it — a human's deliberate naming decision would be silently overwritten
  by an automatic rebuild — worth a direct test asserting a `retired`
  cluster's status is untouched across a generation supersession.
- If `processing_enabled` defaults to `true`, or `FPA-P10-S03`/`S04`'s
  tests exercise the gated automatic-dispatch path instead of calling
  services directly, placeholder calibration values could reach real
  family data before `FPA-P10-S07` — worth a direct test asserting no
  automatic suggestion or cluster is generated for ordinary Family Space
  content while the gate is `false`.
- If the calibration gate itself (§16) is skipped or treated as optional
  under schedule pressure, an untested recognition threshold could ship
  despite the roadmap's own precision-over-recall requirement.

## Implementation notes

- **`FPA-P10-S02`** implements §2 in full: `face_observations_id_family_space_unique`
  and `people_id_family_space_unique` (both, sequenced before any
  composite foreign key referencing them), the local/test/production
  pgvector provisioning described there, `face_embedding_projections` and
  its deterministic rebuild/drift-detection mechanism, and the Fambam-
  owned similarity-search abstraction and its scoping rules. No HNSW/
  IVFFlat tuning is chosen here.
- **`FPA-P10-S03`** implements §9 in full: `face_cluster_generations`/
  `face_clusters`/`face_cluster_members`, their enforceable
  `active`/`retired`/`superseded` lifecycle; a build whose candidate
  population is every recognition-eligible, unassigned `FaceObservation`
  within one compatible embedding space — including observations
  currently held by the generation being replaced, and excluding only
  observations with human-retired protection history; non-operational
  (`is_active = false`) membership creation during build; the six-step
  atomic activation transaction (deactivate old, supersede old clusters,
  activate new generation, activate new memberships, supersede old
  generation — never touching an already-`retired` cluster); and
  re-clustering without image inference — gated by §16's
  `processing_enabled` for real Family Space data; exercised directly
  against benchmark/fixture data and unit tests
  in the meantime.
- **`FPA-P10-S04`** implements §3, §4, §5, §6, §7: `face_identity_assignments`
  (including the `withdrawn` status), its integrity constraints, the four
  deterministic `PhotoPerson`-ensuring transitions, trusted-gallery
  derivation, and candidate generation with confidence bands — likewise
  gated by §16 for real data.
- **`FPA-P10-S05`** implements §8 and §9's merge/split/naming-resolves-to-
  assignments behaviour (`FaceIdentitySuppression`, reopening, cluster
  merge/split/naming actions), **and the Person-merge integration of §12**
  — extending `PersonMergeManager`'s existing `captureState()`/
  `restoreState()`/`reconcile*()` methods to cover `face_identity_assignments`
  (status-dependent handling: `approved`/`rejected`/already-`withdrawn`
  repoint directly; a `pending` row is captured, transitioned to
  `withdrawn`, and only then repointed — never left `pending` and never
  left pointing at the absorbed Person) and `FaceIdentitySuppression`
  (repoint, or delete-with-snapshot on collision, per §12's corrected
  rule), since both tables must exist before this integration can be
  implemented.
- **`FPA-P10-S06`** implements §10: `recognition_allowed` (with its
  automatic schema-level backfill), its full exclusion behaviour, and the
  `withdrawn`-transition/bundled-audit mechanics that depend on it.
- **`FPA-P10-S07`** implements §16 in full: the calibration pass against
  the extended private benchmark corpus, recording a named
  `calibration_profile`, and — as its own explicit, final step — setting
  `processing_enabled = true` to switch on automatic Phase 10 processing
  for real Family Space data for the first time.
- **Required regression tests**: (1) two `FaceObservation`s of the same
  Person in one Photo must each be representable as an independent
  `FaceIdentityAssignment`, and both must resolve to exactly one
  `PhotoPerson` row; (2) approving a `FaceIdentityAssignment` when an
  active claim already exists for that face must be rejected by §4's
  partial unique index; (3) each of §5's four `PhotoPerson` transition
  cases (no row, active-approved, active-pending, historical-rejected-
  only) must resolve exactly as specified; (4) a similarity query must
  never return or compare a `face_embedding_projections` row from a
  different Family Space or a different embedding-space identity; (5)
  rebuilding a projection must never alter the parent `FaceObservation`
  row; (6) generating a shortlist must create zero `FaceIdentityAssignment`
  rows; selecting one candidate must create exactly one; (7) rejecting a
  suggestion must create a `FaceIdentitySuppression` row that prevents
  that exact pair from being suggested again until explicitly reopened;
  (8) reopening a suppression must be Owner/Administrator-only, audited,
  and transition the existing row; (9) cluster merge/split must never
  alter any `face_observations` row, and must be rejected against a
  `retired` cluster; (10) naming a cluster must produce ordinary
  `FaceIdentityAssignment` rows, retire the cluster, and leave it with no
  residual identity authority; (11) disabling recognition for a Person
  must transition their pending assignments to `withdrawn` (never
  `rejected`, never creating suppression), remove them from active
  matching, and leave every approved assignment, `PhotoPerson` fact, and
  `FaceObservation` untouched, as one audited action; (12) re-enabling
  recognition must not resurrect any `withdrawn` row; (13) merging two
  Persons must repoint `approved`/`rejected`/already-`withdrawn`
  `face_identity_assignments` unconditionally, and must handle a
  `pending` row in the fixed order §12 requires — capture its pre-merge
  `person_id`/status, transition it to `withdrawn`, and only then repoint
  its `person_id` to the survivor — never leaving it `pending`, and never
  leaving its `person_id` naming the absorbed Person once the merge
  transaction completes; must also apply §12's corrected collision rule
  for `FaceIdentitySuppression` exactly (repoint when no collision exists;
  delete the absorbed-side row and preserve it only in the merge snapshot
  when a survivor-side row already exists for the same face); asserting,
  after the merge transaction completes, that **no** live
  `face_identity_assignments`/`FaceIdentitySuppression` row anywhere
  still references the absorbed Person's id, and that a repointed,
  formerly-`pending` row is never re-read as an active suggestion for the
  survivor; (14) reversing that merge must restore Phase 10 state to
  exactly its pre-merge shape — including the original `person_id` and
  `pending` status of any withdrawn-then-repointed assignment, and
  reinserting any row §12's collision rule deleted — all sourced from the
  merge snapshot, never inferred from the rows' post-merge values, using
  the extended `captureState`/`restoreState` mechanism; (15) no automatic
  process may
  write `reopened_at` on a `FaceIdentitySuppression`, transition a
  `FaceIdentityAssignment` to `approved` directly, or set a cluster's
  status to `retired` — only a human naming/review action may do so; (16)
  an automatic clustering rebuild activating a new generation *may*
  transition a previously-`active` cluster to `superseded`, must leave
  every `retired` cluster in that generation completely untouched, and
  must not itself be recorded as an audited human decision; (17) while
  `config('face_recognition.processing_enabled')` is `false`, no automatic
  clustering or suggestion generation may occur against non-benchmark,
  non-fixture Family Space data; (18) at most one `face_cluster_generations`
  row per Family Space may be `active` at a time, and activating a new
  generation must leave no cluster from the previous generation still
  holding an active membership; (19) a building generation's candidate
  population must include `FaceObservation`s currently holding an active
  membership in the generation being replaced — current active membership
  must never exclude an observation from a rebuild; (20) a failed build
  must leave the currently-active generation and all of its active
  memberships completely untouched, and the failed generation must never
  reach `active`; (21) atomic activation must be all-or-nothing — no
  intermediate committed state may show both the old and new generation
  as `active`, nor leave neither generation `active`; (22) it must be
  impossible, at any point, for a single `FaceObservation` to hold two
  simultaneously-`is_active = true` cluster memberships; (23) after a
  successful activation, every newly-built cluster's memberships must
  actually be `is_active = true` and reachable by suggestion generation
  and review; (24) an observation whose only clustering history is under
  a currently-`retired` cluster must be excluded from every subsequent
  rebuild's candidate population, even though it carries no active
  `FaceIdentityAssignment`.
- ADR-0011 §19's tenancy discipline and §13's `FaceObservation`
  immutability guarantee remain cross-cutting: every stage above should be
  checked against both during review, alongside this ADR's own §4/§9
  constraints.

## Review triggers

- **When Phase 11 (search) is scoped**: confirm search treats each
  `FaceIdentityAssignment`/cluster as exactly what it is, and inherits the
  same Family-Space/embedding-identity scoping §2 fixes.
- **When Phase 13 (export/portability) is scoped**: confirm
  `face_identity_assignments`, `FaceIdentitySuppression`,
  `face_embedding_projections`, and `face_clusters`/`face_cluster_members`
  are treated as recognition data excluded from export by default;
  `PhotoPerson` facts, being human truth, are not subject to that
  exclusion.
- **When Phase 14 (product UI/UX) is scoped**: design the actual review
  wording and visual treatment; polish the suppression-reopen surface this
  ADR deliberately left backend-only.
- **When Phase 15 (Platform Administration) is scoped**: consider whether
  flipping `processing_enabled` and recording `calibration_profile`, or
  bulk/administrative recalibration, belong on that surface.
- **When Phase 16 (security/privacy hardening) is scoped**: revisit
  whether `recognition_allowed`'s default and disclosure need to be more
  visible to family members than an Owner/Administrator-only setting
  otherwise implies.
- **When Phase 18 (semantic image search) is scoped**: decide, with real
  evidence at that time, whether a shared vector-search backend with face
  recognition is useful reuse or harmful coupling — this ADR's abstraction
  boundary (§2) is what makes that decision safe to defer.
- **If real Family Space usage shows §4's one-active-claim-per-face model
  is genuinely insufficient** — revisit deliberately, as its own decision.
- **After the calibration pass (§16)**: record the chosen thresholds, the
  evidence behind them, and the `calibration_profile` identifier in
  `docs/IMPLEMENTATION_GUIDE.md`.

## Deferred concerns

- Exact physical schema, column types, and indexing for
  `face_identity_assignments`, `face_embedding_projections`,
  `face_cluster_generations`/`face_clusters`/`face_cluster_members`, and
  `FaceIdentitySuppression` — the shape is fixed, the columns are not.
- The exact pgvector column type/index configuration (HNSW vs. IVFFlat,
  distance operator choice) — implementation detail behind §2's
  abstraction.
- Exact confidence-band cutoffs, minimum-reference-count thresholds, and
  clustering-similarity thresholds — §16's calibration gate.
- The exact best-match-vs-top-k aggregation choice for gallery matching
  (§6) — calibration, unless evidence resolves it during implementation.
- The exact review-surface wording and visual design for suggestions,
  shortlists, and clusters — Phase 14.
- Whether bulk/administrative recognition operations belong on a future
  Platform Administration surface — Phase 15.
- The exact mechanics of flipping `processing_enabled` in each environment
  (manual operator action vs. an `FPA-P10-S07`-owned command) —
  implementation detail, not architecture.

## Resolved decisions

1. **Scope** — Phase 10 suggests and groups; only Owner/Administrator
   confirms, rejects, reopens, merges, splits, and excludes; Member
   proposes and views. Every path ends at a human confirmation.
2. **Vector storage** — PostgreSQL remains authoritative for embeddings
   and provenance; the searchable vector lives in a separate, disposable
   `face_embedding_projections` table, never on `FaceObservation` itself,
   preserving ADR-0011 §13's immutability guarantee without exception;
   pgvector is the initial implementation behind a narrow Fambam-owned
   abstraction; Qdrant, if ever justified, is a rebuildable projection,
   never a second source of truth; every similarity operation is scoped
   by Family Space and compatible embedding-space identity, always;
   provisioning (local image, Postgres-only test path, production
   requirement) is explicitly `FPA-P10-S02`'s.
3. **`FaceIdentityAssignment`** — a new, face-level identity-claim table,
   distinct from `PhotoPerson`; four statuses (`pending`/`approved`/
   `rejected`/`withdrawn`), with `withdrawn` reserved for non-judgmental
   administrative retirement of a pending assignment (consent-driven
   exclusion, or a Person merge making the candidate obsolete) — never
   rejection, and never creating suppression.
4. **`FaceIdentityAssignment`/projection integrity** — at most one active
   claim per face; tenant-consistent composite foreign keys throughout;
   `face_observations` and `people` both receive the same additive
   `UNIQUE (id, family_space_id)` constraint `media_uploads` already did,
   sequenced before their dependent composite foreign keys, with no
   change to either table's identity or lifecycle semantics.
5. **PhotoPerson-ensuring, deterministic** — an approved
   `FaceIdentityAssignment` resolves through exactly one of four
   exhaustive transitions (no row → create approved; active approved →
   reuse; active pending → resolve to approved as the same authoritative
   action; historical rejected only → create a new approved row,
   preserving the old one untouched); no ambiguous "conflict" state is
   needed because every case is already deterministic under existing
   authority and existing schema semantics.
6. **Trusted gallery** — derived live from approved
   `FaceIdentityAssignment`s; no centroid, ever; reference count feeds
   confidence, never gates eligibility outright.
7. **Candidate suggestions** — three confidence bands; shortlists are
   ephemeral, never persisted as competing rows; precision over recall
   throughout.
8. **Rejection/suppression** — a durable `FaceIdentitySuppression` record
   modelled on `DuplicateDecision`; reopening is Owner/Administrator-only,
   audited, same-row; entirely distinct from, and never triggered by,
   consent-driven withdrawal.
9. **Clustering, with enforceable lifecycle** — machine-derived, mutable,
   restricted to unconfirmed observations, carrying no identity field;
   three cluster states (`active`/`retired`/`superseded`) distinguish
   human-driven retirement (naming, audited) from machine-driven
   supersession (a newer clustering generation replacing an older one,
   not audited); `face_cluster_generations` makes "at most one active
   generation at a time" and atomic activation/supersession enforceable
   invariants, not prose; supersession never touches an already-`retired`
   cluster; naming resolves into ordinary `FaceIdentityAssignment`s and
   retires the cluster.
10. **Consent/exclusion** — `recognition_allowed` defaults `false` for
    every Person, including a schema-level backfill of every pre-existing
    Person to `false`; Owner/Administrator-only to change; manual tagging
    always available; exclusion withdraws (never rejects or deletes)
    dependent pending suggestions, as one audited action, without ever
    touching approved assignments, confirmed `PhotoPerson` facts, or
    `FaceObservation` provenance.
11. **Model/version coexistence** — recognition operates against exactly
    one compatible embedding-space identity, inherited from Phase 9's own
    active generation identity; migration uses Phase 9's existing explicit
    reprocessing trigger, never automatic mass rescanning.
12. **Person-merge integration** — extends `PersonMergeManager`'s existing
    capture/reconcile/restore transaction directly: `approved`/`rejected`/
    already-`withdrawn` `face_identity_assignments` repoint unconditionally;
    a `pending` row is first transitioned to `withdrawn` (its pre-merge
    `person_id`/status captured in the snapshot beforehand) and only then
    repointed to the survivor for relational integrity — repointing the
    foreign key is not repointing the suggestion's meaning, so a
    withdrawn, survivor-pointing row is never read or reactivated as a
    live candidate; `FaceIdentitySuppression` repoints where no collision
    exists, or is deleted from the live table and preserved only in the
    merge snapshot where a survivor-side row already exists — **no Phase
    10 live structure ever retains a foreign key to the absorbed Person
    after merge, without exception**; clusters need no reconciliation;
    guarded automatic reversal restores both the withdrawn-then-repointed
    assignment's and the deleted suppression's original pre-merge state
    from the snapshot, never inferred from live post-merge values, and is
    inherited for free.
13. **Authorization** — a direct application of ADR-0006 §4/§14's
    propose-vs-confirm pattern and ADR-0010 §10's suggestion-review shape;
    no Member authority beyond proposing and viewing.
14. **Audit** — automatic scoring/clustering generates no audit rows;
    every deliberate human action is audited, with a consent change and
    its bundled dependent withdrawals recorded as one action.
15. **Deletion/reprocessing** — soft-delete/restore, identity-profile
    changes, Person merge, and recognition exclusion each interact with
    Phase 10 structures exactly as specified, with no historical fact ever
    destroyed by an automatic process.
16. **Calibration and safe activation** — no threshold of any kind is
    fixed by this ADR; Phase 9's `0.6` detection threshold is explicitly
    not a recognition threshold; automatic clustering and suggestion
    generation are gated off (`processing_enabled = false`) against real
    Family Space data until `FPA-P10-S07` calibrates and explicitly
    activates a named `calibration_profile`; `S03`/`S04` build and test the
    machinery against benchmark/fixture data and direct service calls in
    the meantime.
17. **Scale and UI boundary** — realistic Family Space scale, not
    internet scale; Phase 10 builds functional review UI only; final UX
    belongs to Phase 14.
