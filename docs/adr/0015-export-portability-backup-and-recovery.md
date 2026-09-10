# ADR-0015: Export, Portability, Backup and Recovery

- Status: Accepted
- Date: 2026-09-10
- Decision owners: David
- Related stages: FPA-P13-S01 (accepting this ADR completes that stage),
  implemented by FPA-P13-S02, FPA-P13-S03, FPA-P13-S04, FPA-P13-S05,
  FPA-P13-S06

## Context

A core reason Fambam exists is that a family's photographs and memories
should remain under the family's own control: private, protected from
social-platform exposure, safely preserved, recoverable, and portable
without lock-in. Phase 13 makes that promise concrete. `PROJECT_ROADMAP.md`'s
own exit criteria fix the bar: "a complete family archive can be exported
without proprietary tooling," "restore is tested, not merely documented,"
"backup health is observable," and "deletion semantics cover primary
data, derivatives and backups."

**Three genuinely different concerns are addressed here and must not be
conflated into one mechanism**: family export/portability; platform
backup; and Family Space deletion (already governed by ADR-0005's
`FamilySpaceDeletionManager`, which this ADR extends at specific points
without redesigning).

**`EventExportManager` is the mechanical template for both export types —
for its mechanics only, not for per-actor authorization filtering.**
It already proves queued generation, per-file SHA-256 re-verification, ZIP
assembly, a `Pending → Processing → Ready | Failed → Expired` lifecycle
with claim-based idempotency, `finalizeWriteOnce()`, a scheduled expiry
job, and minimal audit events. It does not prove per-item authorization
filtering: `EventExportManager::snapshot()` fetches every Photo belonging
to the target Event, tenant-scoped only, because Event export
authorization is coarse by design — `EventExportController` gates every
action behind a single Owner/Administrator-level Event policy check, and
once authorized, every Event Photo is included unconditionally.
Personal Export's finer-grained, per-item, currently-authorized-content
filtering (§5-§7) is new logic this ADR specifies, modelled on ADR-0013
§9's existing authorization entry points — not a reuse of an existing
per-actor pattern.

**A completed archive cannot contain its own checksum.** `EventExportManager`
demonstrates the correct pattern: it closes the ZIP first, then computes
the archive checksum and stores it on the `EventExport` row itself —
outside the archive entirely. §14 follows this pattern.

**Phase 12 is now complete in the live repository, and this ADR describes
that live state directly rather than a prior, superseded assumption.**
ADR-0014 is `Accepted`, and direct inspection of
`apps/api/app/Services/FamilySpaceDeletionManager.php`'s current
`teardown()` method confirms all six of Phase 12's tables are already
present in its explicit per-table delete list:
`FamilyActivity`, `NotificationDelivery`, `FamilyNotification`,
`NotificationCandidate`, `NotificationPreference`, and
`ContributionGroup`. Deletion completeness through Phase 12 is verified,
not assumed and not merely anticipated. Phase 13's own obligation (§18,
§37) is narrower and still open: add *this ADR's* new export-tracking
table and its storage objects to the same list — nothing about Phase 12
remains to be reconciled here.

**Backup creation has no implementation owner in the original stage
allocation, and this reconciliation assigns one.** The original draft
required automated database backups, object-storage versioning, and
restore testing, but no stage actually *created* any of these — S04 only
monitored evidence, S05 assumed usable backup inputs already existed, and
S06 depended on versioning "once enabled" with nothing enabling it.
Direct inspection of `infrastructure/docker/localstack/init/10-bootstrap.sh`
confirms the local development bucket is provisioned with CORS, a
public-access block, and a lifecycle rule — **no versioning is enabled
anywhere**. §24-§25 and §38 correct this: Phase 13 must provision a
provable local/development analogue of backup creation and object
versioning, and define the production requirement precisely, without
pretending either already exists.

**The Phase 13 stage sequence itself had a real ordering defect.** The
original draft assigned working export generation to `FPA-P13-S02`
before the archive format it must produce was defined in
`FPA-P13-S03` — a working exporter cannot safely be built ahead of the
format it is required to emit. §38 corrects the internal boundary between
these two stages without renaming either in `tasks.json`.

**`preserved` is a transitional `MediaUploadState`, not a durable
property, and export eligibility must not be keyed to it directly.**
Direct inspection of `apps/api/app/Enums/MediaUploadState.php` confirms
the full state set: `Initiated, Uploaded, Verifying, Preserved,
Processing, Ready, Quarantined, Abandoned, Degraded`. Once an upload's
original passes verification and reaches `Preserved`, it remains
byte-for-byte immutable (ADR-0007 §6) regardless of what happens to
derivative processing afterward — an upload can legitimately sit in
`Processing`, `Ready`, or `Degraded` while still possessing the exact
same checksum-verified preserved original a `Preserved`-state upload has.
Conversely, `MediaValidationManager` sets `Quarantined` *instead of*
transitioning to `Preserved` when validation flags a file — a quarantined
upload never reached a preserved original at all. `Abandoned` uploads
were rejected before reaching irreplaceable archival status and their
storage is not retained (ADR-0007 §18). §3 restates export eligibility
around the durable property — "possesses a valid, checksum-verified
preserved original" — using state only to rule out the cases where that
property cannot hold.

**"Transactional" cannot mean the email send itself is atomic with a
database commit — no external delivery ever can be.** §19 restates what
the settled product rule ("export-ready/export-failed are direct,
unconditional, workflow-completion notifications, not preference-gated
family activity") means mechanically: a durable notification *intent* is
recorded in the same transaction as the export's terminal state
transition; email delivery is queued after that commit, idempotent and
retryable, using the export's own id and terminal outcome as its stable
identity.

**Presentation visibility and preserved-original download authority are
two distinct, separately-checked permissions in the live authorization
model, and Personal Export must not conflate them.** Direct inspection of
`apps/api/app/Policies/MediaUploadPolicy::downloadOriginal()` (the
canonical, live entry point for preserved-original download — attached to
`MediaUpload`, not `Photo`, since that is where this authorization
already lives) confirms two concrete cases exactly matching what a naive
"include every Photo's original that the Album/Event container rule
already authorized" implementation would get wrong: a **Contributor**
role is entirely excluded from `downloadOriginal()`'s non-Guest branch
(`hasPhaseFiveMediaAccess()` only permits Owner/Administrator/Member), so
a Contributor who can *view* a Photo through an `AlbumGrant` still cannot
download its original; and a **Member** who can view a `Private` Photo
through Album-visibility widening (`PhotoPolicy::hasAlbumAccess()`'s
`family_space`-visibility-Album branch) still cannot download that
Photo's original, because `downloadOriginal()`'s Member branch requires
`photo->visibility === PhotoVisibility::FamilySpace` specifically — a
`Private` Photo never satisfies it regardless of how the Member came to
see it. §6-§7 require these to be evaluated as two independent
authorization decisions per Photo, reusing `MediaUploadPolicy::downloadOriginal()`
exactly rather than introducing a parallel export-specific policy with
subtly different semantics.

**Standing constraints this ADR inherits and does not revisit**: ADR-0007
established preserved-original immutability and SHA-256 as the standing
integrity primitive; ADR-0006 §12 requires every Person-referencing table
to integrate with `PersonMergeManager`; ADR-0008/0012/0013/0014
repeatedly drew the human-fact-vs-machine-artifact boundary this ADR
applies again in §26, which accounts for the mandatory cascading foreign
keys `face_identity_assignments` and `face_identity_suppressions` both
hold to `face_observations`. ADR-0012 §8 established `FaceIdentitySuppression`
as a durable human rejection record, the same standing as an approved
`FaceIdentityAssignment`.

## Decision

### 1. Scope

This ADR settles: what a full Family Space export and a Personal Export
each contain, including a precise, state-machine-aware definition of
"complete"; one shared, versioned archive format for both, built across
two internally distinct stages (§38); secure generation with a defined
authorization-consistency guarantee; retention; the export-before-deletion
interaction; a mechanically precise export-completion notification model;
and platform backup policy — including explicit implementation ownership
for actually creating backups and proving object-storage versioning in
development, not only monitoring and testing them. It does not redesign
Family Space deletion itself, does not build an import engine, and does
not select a specific production database/storage service.

### 2. Full Family Space export: authorization

Requesting a full Family Space export is **Owner-only**, matching the
existing precedent that only Owner may request or cancel Family Space
deletion. Administrator, Member, Contributor, and Guest cannot request a
full export merely because they can view parts of the archive.

### 3. Full Family Space export: contents and completeness

A complete administrative archive, not a recreation of the Owner's own
browsing visibility:

- **Included by default**: preserved original media and checksums; all
  Photo metadata; tags; People; confirmed relationships; approved
  `PhotoPerson` associations; Albums with membership and order; Events;
  Stories; comments; reactions; Person-account links (name only); every
  creator's saved searches (§6); **private Photos**, in full.
- **Excluded**: every machine-derived face-recognition artifact (§12);
  duplicate-detection candidates/decisions/holds; `AuditEvent`; Phase
  12's derived projections (`FamilyActivity`, `FamilyNotification`,
  `NotificationDelivery`, `NotificationCandidate`, `ContributionGroup`) —
  each a redundant restatement of a fact already exported elsewhere;
  `recognition_allowed` consent state; and `NotificationPreference` —
  excluded from the portable archive for a different reason than the
  tables just listed: it is operational product configuration about how
  Fambam should behave, not portable family-history content, exactly
  like `recognition_allowed`. **This export-scope exclusion is entirely
  orthogonal to its backup classification** — `NotificationPreference`
  remains authoritative user configuration for backup purposes (§23,
  §27), never a "redundant/derived" table; the two concerns (what belongs
  in a family archive vs. what must never be lost on restore) are
  answered independently, not by the same reasoning.

**"Complete" is resolved precisely, against the live `MediaUpload` state
machine, rather than a shorthand check against one transient state**:

- **Preserved originals with no promoted Photo.** A `MediaUpload`
  qualifies for inclusion under `media/unattached/{media_upload_id}.{ext}`
  when it **possesses a valid, checksum-verified preserved original and
  no `Photo` row currently references it** (`Photo.media_upload_id`).
  Concretely, this means its state is `Preserved`, `Processing`, `Ready`,
  or `Degraded` — every state reachable only *after* the original passed
  verification — and excludes `Initiated`, `Uploaded`, and `Verifying`
  (preservation not yet reached), `Quarantined` (validation diverted it
  away from preservation entirely), and `Abandoned` (rejected, storage
  not retained). A pending duplicate-resolution hold on a `Ready` upload
  does not disqualify it — an upload awaiting a duplicate decision still
  possesses its preserved original and is not yet promoted, so it
  qualifies exactly like any other unattached upload. **Once a
  `MediaUpload` is promoted (a `Photo` row references it), it is exported
  exactly once, under `media/originals/{photo_id}`, never additionally
  under `media/unattached/`** — promotion status, not upload state
  alone, is what determines which single path an original's bytes appear
  under, guaranteeing the same underlying original is never duplicated in
  the archive.
- **Soft-deleted, still-restorable records.** `Photo`, `FamilyEvent`, and
  `Person` are all soft-deletable and remain restorable until purged. A
  full export **includes** soft-deleted records (`withTrashed()`), each
  carrying its own `deleted_at` value so the archive honestly represents
  its state — never silently presented as active, never silently
  omitted.

### 4. Personal Export: authorization and purpose

Available to any authenticated Family Space member, subject to their
**current** role and authorization. It is a structurally different,
narrower export scoped to what the requester meaningfully created, owns,
or contributed, plus the minimum currently-authorized context needed to
make that content coherent outside Fambam. It must never become a route
to bulk-export Family Space content the requester could not otherwise
access — current authorization is decisive at every step (§7).

### 5. Personal Export: container qualification

Three containers qualify a requester's ownership, using the live
schema's actual ownership fields:

```text
Photo   qualifies when photos.created_by = requester
Album   qualifies when albums.created_by = requester
Event   qualifies when events.created_by = requester
```

**Qualification is scoped to the requester's current, active content
only — never their soft-deleted or tombstoned records, even ones they
originally created.** Personal Export reuses `PhotoQuery::visibleTo()`/
`FamilyEventQuery::visibleTo()`/the equivalent Album authorization
exactly as they behave today, and confirmed directly, neither calls
`withTrashed()` — Eloquent's default soft-delete scope already excludes
trashed rows, and Personal Export introduces no special tombstone bypass
around it. This is a deliberate scope boundary, distinct from §3's full
Family Space export rule: Personal Export is portability of the
requester's currently active, currently accessible content, not a
recovery or undelete tool. A future "export my deleted/recoverable
content" capability, if ever needed, is a separately scoped decision with
its own authorization semantics — not something this ADR opens a door to
now. **This is a current-content rule, not exclusively a soft-delete
rule** — Photo, `FamilyEvent`, `Person`, `PhotoStory`, and `PhotoComment`
are genuinely soft-deletable in the live schema and are excluded from
Personal Export in their trashed state exactly as above; Album and
`PhotoReaction` carry no `SoftDeletes` trait at all (confirmed directly —
neither model uses it) and so have no trashed state to exclude in the
first place. The same underlying principle — Personal Export reflects
only what currently, actively exists and is currently authorized —
applies uniformly across every domain it can reference, whether that
domain is soft-deletable or not, unless an already-settled domain rule in
this ADR explicitly says otherwise.

### 6. Personal Export: inclusion rules

For each qualifying container, content is pulled in **live, filtered
through the requester's current authorization** (§7):

- **A qualifying Photo, or a Photo pulled in via a qualifying Album/Event
  below**: full metadata; tags; approved `PhotoPerson` associations; a
  minimal typed reference to any Album/Event it belongs to; **its
  preserved original and checksum only where the requester separately
  holds preserved-original authority for that specific Photo (§7)** —
  presentation-level inclusion in the export and preserved-original
  inclusion are two distinct authorization decisions, never one implying
  the other.
- **A qualifying Album**: its metadata and ordering; a minimal typed
  reference to its linked Event; every Photo in the Album the requester
  is currently authorized to view, regardless of who uploaded it — each
  such Photo's preserved original included or omitted per §7
  independently of the Album's own ownership.
- **A qualifying Event**: the same principle one level up — Event
  metadata; every linked Album; every currently-authorized Photo within
  those Albums, each again subject to §7's independent original-authority
  check.
- **Once a Photo is included by any rule above**, every Story, Comment,
  and Reaction on it that the requester is currently authorized to see
  travels with it, regardless of who authored it.
- **Additive rule for the requester's own scattered authorship**: every
  Story, Comment, and Reaction the requester personally authored is
  included even on content they do not own, with minimal typed context,
  while current authorization holds.
- **Saved searches**: the requester's **own** saved-search definitions
  only — the portable definition itself, never a snapshot of query
  result rows. An unresolvable reference is exported honestly marked
  unresolved, never silently dropped or re-resolved against access the
  requester no longer has.
- **Contextual People**: every Person with an approved `PhotoPerson`
  association on an included Photo, as a full typed Person record —
  except for a Contributor or Guest requester, whose People context is
  bounded to only Persons confirmed in Photos they are already authorized
  to see.
- **Account and profile**: the requester's own name, timezone, and their
  own `PersonAccountLink`; never another User's email, credentials, or
  security secrets, never audit/security internals.

### 7. Personal Export: authorization consistency and lifecycle

A per-item authorization check made only once, whenever an item is first
considered, does not protect against revocation that occurs after that
item is already written into the archive but before generation
completes — and, separately, presentation-level inclusion and
preserved-original download authority are two distinct permissions that
must never be conflated. This ADR requires one explicit, verifiable,
two-dimensional guarantee, authoritative at a single point: **immediately
before the archive is sealed, never earlier.**

1. **Resolution.** At the start of assembly, the full authorized Photo/
   Album/Event/context set is resolved via one live pass over the entry
   points ADR-0013 §9 already established. For each Photo in that set, a
   preliminary preserved-original authorization decision is also
   resolved via `MediaUploadPolicy::downloadOriginal()` (§ Context) —
   used only to decide whether it is worth copying that Photo's original
   bytes into the working archive at that point, as a build-time
   optimization, **never treated as the authoritative decision in either
   direction** — resolution deciding "not yet authorized" does not
   permanently foreclose the original being included if authority is
   granted before sealing, exactly as resolution deciding "authorized"
   does not guarantee it survives to sealing.
2. **Assembly.** The archive is built from that resolved set: Photo/
   Album/Event/Story/comment/reaction context per §6, and preserved
   originals copied for Photos whose preliminary check in step 1 passed.
3. **Final pre-seal reconciliation — authoritative.** Immediately before
   checksumming, sealing, or persisting anything as the immutable `Ready`
   artifact, both authorization dimensions are re-evaluated live, fresh,
   for every Photo currently in the resolved set:
   - **(A) Presentation/content inclusion.** Is the Photo still currently
     authorized for inclusion at all (the same ADR-0013 §9 entry points)?
     **If no**: remove the Photo from the export dataset entirely, remove
     its preserved original if one was copied, remove its checksum/
     manifest entries, and remove any contextual/domain record (a Person,
     an Album, a Story) that exists in the working archive *solely*
     because this Photo needed it — recomputing that closure over
     whatever content remains, so a context record still needed by
     another surviving Photo is correctly retained. **This dimension is
     removal-only** — a Photo absent from the resolved set built in steps
     1-2 is never newly added at reconciliation; only a Photo already
     present can be removed.
   - **(B) Preserved-original authority — evaluated symmetrically, in
     either direction, for every Photo that remains presentation-authorized.**
     Is the requester's `MediaUploadPolicy::downloadOriginal()` authority
     for it current **right now**, regardless of what the step-1
     preliminary check decided or whether an original was already
     copied? **If yes and an original is already copied**: it, its
     checksum, and its path metadata remain; `original_included: true`.
     **If yes and no original was copied** (the step-1 preliminary check
     had decided against it, but authority has since been granted): the
     reconciliation copies the original now, verifying its checksum
     against `original_sha256` exactly as ordinary assembly does, and
     sets `original_included: true` — the newly-copied file is included
     in the archive precisely as if it had been copied at assembly time.
     **If no**: `original_included: false`; any already-copied original
     file is deleted from the working archive along with its checksum/
     path metadata; the Photo's authorized context itself is retained
     regardless. **Unlike (A), this dimension is bidirectional** — it can
     add an original the preliminary check skipped, exactly as readily as
     it can remove one the preliminary check copied, because the only
     thing that matters for what ships in the sealed archive is
     `downloadOriginal()`'s answer at this final moment, never the
     preliminary answer.
   - Presentation inclusion (A) only ever narrows the resolved set;
     preserved-original inclusion (B) is fully re-decided, in whichever
     direction current authorization requires, for whatever Photos remain
     after (A). A Personal Export that reconciles down to few or even
     zero remaining Photos is still a valid, honestly `Ready` archive,
     never a `Failed` one — an empty or reduced result is not an error,
     it is what current authorization actually permits at the moment of
     sealing.
4. **Only after step 3 completes** may the manifest and `checksums.json`
   be materialized from the reconciled content, the archive sealed and
   checksummed (§14), and the export marked `Ready`.

**This entire per-Photo original-authorization dimension, and the
two-part reconciliation above, apply to Personal Export only.** Neither
narrows the Owner-authorized full Family Space export (§3), which
already, deliberately, includes every preserved original in the Family
Space under Owner's separately-settled administrative authority — a full
export's reconciliation, if performed at all, can only ever confirm
`original_included: true` throughout, never remove anything.

For a requester's own created Photos, this ADR does not assume creation
implies original-download authority — that result, where the live policy
already grants it (e.g. `photo.created_by === user.id` is one of
`downloadOriginal()`'s own existing branches), is derived by calling the
same policy, never duplicated or re-asserted independently in export
code.

**Download-time authorization is new logic this ADR requires, and it
differs by export type — the two must not share one boundary.**
`EventExportManager::authorizeDownload()` checks only the export's own
`state`/`expires_at`; membership authorization for Event export happens
separately, at the controller, which re-runs `Gate::authorize('manageExports',
$target)` on every action including `download()` — the requester's
management authority is re-verified, not merely assumed to still hold
from request time. This ADR's own download authorization mirrors that
same principle, precisely, for each export type:

- **Full Family Space export**: at download time, the requester must
  still be (a) the original requester of this specific export, (b) an
  active member of that Family Space, **and** (c) still hold the Owner
  role — the full authority `manageExports`-equivalent check re-run, not
  a weaker "still a member" substitute. If any of these fail — the
  requester was demoted from Owner after requesting the export, for
  example — no signed download URL is issued, and the response follows
  this repository's ordinary authorization-failure semantics, never
  disclosing more about the export's existence or contents than the
  actor's own current standing would otherwise reveal. **The export
  artifact itself is not destroyed by a lost Owner role** — it remains
  in `Ready` state and expires normally (§18); losing authority denies
  future download, it does not trigger early deletion.
- **Personal Export**: at download time, the requester must still be (a)
  the original requester, and (b) still hold whatever current Family
  Space membership/access the Personal Export contract itself requires
  (§4) — ordinarily, still an active member. **Personal Export never
  requires the Owner role** — that would contradict its own purpose as a
  capability available to every role.

Once `Ready`, an archive's contents are immutable; the bounded, short
retention window (§18) is the accepted limit on how stale "authorization
was valid at generation, and still is at download" can become.

### 8. One shared, versioned archive schema

Personal Export and full Family Space export are the same archive
specification. `manifest.json` carries an `export_scope` field
(`family_space_full` | `personal`) and, for `personal`, the requesting
user's identity; every per-domain file uses the identical schema in both
cases.

### 9. Archive format and layout

```text
family-export.zip
├── manifest.json          schema_version, export_scope, generated_at,
│                          Family Space identity, requester identity,
│                          byte counts (never the archive's own checksum
│                          — see §14)
├── checksums.json          per-file SHA-256 for every other file in the
│                          archive; never a checksum of itself
├── people.json
├── relationships.json
├── photos.json
├── albums.json
├── events.json
├── stories.json
├── comments.json
├── reactions.json
├── saved_searches.json     definitions only, never result snapshots (§6)
├── media/originals/{photo_id}.{ext}   present only for a Photo whose
│                                       manifest entry in photos.json
│                                       carries original_included: true
└── media/unattached/{media_upload_id}.{ext}   preserved, unpromoted
                                                uploads (§3), full export
                                                only
```

**`original_included: true | false` is part of the one stable archive
schema (§8) and appears on every `photos.json` entry in both export
scopes — its presence never varies by `export_scope`, only its value
does.** When `true`, that Photo's original path, checksum, and
preserved-original media metadata are present, both in the manifest
entry and as a real file under `media/originals/`. When `false`, those
fields are absent — never `null` placeholders masquerading as present
data, and never a fabricated placeholder file. In a **full Family Space
export**, every Photo's original is included under Owner's administrative
authority (§3), so `original_included` evaluates `true` for every Photo
there — the field is still present, structurally, exactly as in a
Personal Export; a parser never needs a different code path per
`export_scope` to read it. In a **Personal Export**, the value is decided
per Photo by the final pre-seal reconciliation (§7), reflecting the
requester's `MediaUploadPolicy::downloadOriginal()` authority at the
moment the archive is sealed, not at any earlier moment.

### 10. Stable portable identity

Fambam's own ULIDs remain the cross-file reference identity within the
archive.

### 11. Comments and reactions: honest legacy context

Included by default. **`album_id` is honestly `null` for legacy rows
that predate Album-scoped conversation** — confirmed directly that both
`photo_comments.album_id` and `photo_reactions.album_id` were added as
nullable columns after the original Photo-only conversation model. The
export never invents a synthetic Album to fill this gap.

### 12. Biometric and machine-recognition data: excluded from every export

Human family truth is portable; machine-derived biometric machinery is
not, for any requester, at any privilege level. **Exported by default**:
People; approved `PhotoPerson` associations; human-entered or
human-corrected metadata. **Never exported**: `FaceObservation` geometry,
landmarks, and embeddings; `face_embedding_projections`; similarity
scores; `FaceCluster`/`FaceClusterGeneration` structure; machine-generated
suggestions; `FaceIdentitySuppression` internals; model, calibration, and
configuration identity; recognition confidence or processing
diagnostics. No privileged biometric export exists in Phase 13, for any
role, including Owner.

### 13. Original media preservation in export

Only the preserved original and its checksum are exported — never
canonical, presentation, or thumbnail variants.

### 14. Export manifest and integrity

**The archive cannot contain its own checksum, and does not attempt to.**
`manifest.json` carries schema version, `export_scope`, generation
timestamp, Family Space identity, requester identity, and byte counts —
never a checksum of the completed archive. `checksums.json` carries a
per-file SHA-256 for every *other* file in the archive and explicitly
excludes itself. **The archive-level SHA-256 and byte size are computed
once the ZIP is sealed, stored on the export's own database row —
exactly as `EventExport.archive_sha256` already does — and surfaced to
the requester alongside the download authorization, never written into
the archive itself.** Every media checksum inside the archive is
re-verified against the originally-stored `original_sha256` at the
moment it is read for export. **`checksums.json` contains a checksum for
a Photo's original only when that Photo's `original_included` field is
`true` (§9)** — a Personal Export Photo omitted for lack of
preserved-original authority contributes no checksum entry for its
(absent) original, since there is no file to checksum; this omission is
never treated as a missing-object error.

### 15. Export generation

Queued, generalizing `EventExportManager`'s proven mechanics, built
across two internally distinct stages (§38): request → a pending export
row with a pre-assigned, family-scoped storage key → a dispatched job →
resolution of the authorized item set (§7) → a temporary workspace →
checksum-verified media assembly against the archive schema (§8-§9) →
reconciliation immediately before sealing (§7) → the archive's checksum
computed and stored on the export row (§14) → `finalizeWriteOnce()` →
marked `Ready` → the requester notified (§19).

### 16. Export status lifecycle

`Pending → Processing → Ready | Failed → Expired`, identical to
`EventExport`'s existing state machine, exposed through functional
status UI.

### 17. Secure delivery

A narrowly scoped, short-TTL signed URL, re-issued fresh on every
authorized download request, gated on the export's own state/expiry plus
the export-type-specific authorization re-check §7 defines — the full
Owner-role re-verification for a full Family Space export, or the
current-membership re-check for a Personal Export. No password-protected
or encrypted ZIP packaging is added in V1.

### 18. Export retention

A short, fixed expiry governs both export scopes; a scheduled job
deletes the object and marks the row `Expired`. Export rows and objects
are added explicitly to `FamilySpaceDeletionManager::teardown()`'s
per-table delete list, alongside Phase 12's already-present tables (§
Context).

### 19. Export completion notifications

**"Direct, unconditional, transactional" is defined mechanically in this
reconciliation, not left as loose product language.** The settled rule
is unchanged: if a user explicitly requests an export, they are notified
when it becomes `Ready` and if it reaches terminal `Failed`; these are
workflow-completion notifications, never preference-gated, and
self-notification suppression does not apply because the requester is
intentionally the recipient.

**"Transactional" means the durable notification *intent* is recorded
atomically, in the same database transaction that moves the export to
its terminal state (`Ready` or `Failed`) — never that the email itself is
sent inside that transaction, which is impossible for any external
delivery.** The identity that makes this idempotent is the export's own
id paired with its terminal outcome: `Ready` is notified exactly once,
`Failed` is notified exactly once, a retried or redelivered attempt at
recording that same intent is a no-op, and a regenerated export (a new
export id) is a genuinely new, independent notification.

- **In-app**: if Phase 12's notification pipeline offers, or is
  extended with, a category suitable for an unconditional
  workflow-completion record, the durable intent is recorded through
  that pipeline's existing tables and idempotency shape — reusing its
  established mechanism precisely rather than building a parallel
  notification subsystem for Phase 13.
- **Email**: queued for delivery **after** the triggering transaction
  commits, using the existing mail-delivery mechanism, retryable, and
  never gated by category preference for this specific workflow. Retry
  or redelivery of the same export-id-plus-outcome intent must never
  produce a duplicate message.

### 20. Import and round-trip posture

The archive format is designed to be import-friendly, but no import or
migration engine is built in Phase 13.

### 21. Export before Family Space deletion

The deletion request flow strongly, prominently offers "Download your
family archive" during the existing deletion grace period, without ever
making export a prerequisite for deletion.

### 22. Platform backup: a distinct concern

Platform backup is Fambam's own operational disaster-recovery mechanism,
architected, classified, and tested independently of family export.

### 23. Authoritative-vs-regenerable classification

**Preserved family originals and human-created or human-confirmed family
knowledge are Fambam's highest-value data and receive the strongest
durability guarantees; deterministically regenerable derived data is
rebuilt rather than treated as equally irreplaceable backup material.**

This classification guides recovery *prioritization* and any future
backup-tiering decision — it is never a live instruction to selectively
exclude tables from the primary PostgreSQL backup (§24), which remains a
whole-database backup regardless of this table.

| Data | Classification |
|---|---|
| Preserved original media (object storage) | Authoritative, irreplaceable |
| Authoritative PostgreSQL family data (People, relationships, Photo/Album/Event/Story metadata, approved `PhotoPerson`, saved searches) | Authoritative |
| Approved `FaceIdentityAssignment` rows, and any decided (non-`pending`) `FaceIdentitySuppression` row | Authoritative — each a durable human decision (§26) |
| `NotificationPreference` | Authoritative — explicit user configuration |
| Canonical/presentation media variants | Regenerable |
| `FaceAnalysisRun`/`Attempt`, `FaceObservation` rows *not* referenced by any authoritative row above, embedding projections, clusters and cluster generations | Regenerable |
| `FamilyActivity`, search vectors and other generated columns | Regenerable projection |
| `FamilyNotification`, `NotificationDelivery`, resolved `NotificationCandidate` rows, temporary export archives | Expendable/transient |

### 24. Backup provisioning: policy, local proof, and production requirement

**Corrected and expanded in this reconciliation — this section now
requires backups to be created, not only monitored.** Three tiers are
distinguished explicitly:

- **Backup policy** (what must exist, stated here as a binding
  requirement): an authoritative PostgreSQL backup/snapshot mechanism
  running on a regular schedule; object-storage versioning or equivalent
  accidental-deletion protection for preserved originals; recorded
  evidence of both; and restore inputs suitable for §30's drill.
- **Local/development proof** (owned by `FPA-P13-S04`, §38): Phase 13
  establishes a genuinely testable analogue of the policy above against
  this project's local infrastructure — enabling and proving
  object-storage versioning on the local bucket (confirmed absent from
  `infrastructure/docker/localstack/init/10-bootstrap.sh` today),
  producing a real, inspectable database backup artifact from the local
  Postgres instance, and producing real, inspectable object-storage
  backup/version evidence — so that §30's restore drill operates against
  **backup inputs this repository's own Phase 13 mechanism actually
  created**, never an assumed or externally-imagined artifact.
- **Production deployment**: this ADR does not pretend production AWS
  backup infrastructure already exists — none is committed anywhere in
  this repository today. It fixes the requirement (automated snapshots,
  point-in-time recovery for authoritative data, a defined retention
  window, encryption at rest) and assigns implementation ownership to
  `FPA-P13-S04` for whatever the local/development proof establishes as
  the pattern, while leaving the exact managed-service selection and
  numeric RPO/RTO targets deferred until a concrete production
  persistence architecture is chosen (§28, § Deferred concerns).

### 25. Object-storage durability and deletion completeness

Preserved originals warrant object-storage versioning — protection
against accidental overwrite or deletion during a Family Space's active
life. **This durability posture and genuine, complete deletion are
reconciled by extending Family Space teardown, never by declining
versioning.** `S3FamilyMediaStorageCleaner`'s current
`listObjectsV2`/`deleteObjects`-by-key approach only removes the current
version on a versioned bucket, leaving every historical version
recoverable indefinitely. **Family Space teardown's object-storage
cleanup must therefore enumerate and permanently purge every version of
every object under that family's storage prefix**, not merely the
current one, once versioning is enabled — owned by `FPA-P13-S06` (§38),
which depends on `FPA-P13-S04` having already enabled versioning in the
environment teardown runs against. Versioning protects against
*accidental* loss during normal operation; deliberate teardown purges
every version, because deliberate deletion is categorically different
from accidental loss.

### 26. Face-analysis and recognition data recovery

Machine-derived recognition artifacts are reproducible by rerunning
analysis — but "regenerable" cannot be applied uniformly to the whole
face-recognition table family, because `face_identity_assignments` and
`face_identity_suppressions` both hold a mandatory, cascading foreign key
to `face_observations`. Deleting or wholesale-regenerating a
`FaceObservation` row that an approved assignment or a decided
suppression depends on would cascade-delete that human decision. Approved
`FaceIdentityAssignment` rows, decided `FaceIdentitySuppression` rows,
and the specific `FaceObservation` rows either references are backed up
with the same durability as other authoritative data, as part of the
ordinary whole-database backup — never selectively excluded or pruned on
the theory that the surrounding table is machine-derived.

### 27. Phase 12 derived-state recovery classification

**This section describes the live, existing Phase 12 tables directly —
Phase 12 is complete and these tables exist today, confirmed in
`FamilySpaceDeletionManager::teardown()` (§ Context).** Search vectors
and other generated columns (ADR-0013) are regenerable projections.
Saved searches (ADR-0013) are authoritative user data. `FamilyActivity`
is a rebuildable, non-authoritative projection. `NotificationPreference`
is authoritative explicit user configuration (§23). `FamilyNotification`,
`NotificationDelivery`, and `NotificationCandidate` are operational/
delivery state whose loss has no family-history consequence —
expendable.

### 28. Recovery objectives

No numeric RPO/RTO is fixed here — no production database/object-storage
architecture has yet been concretely selected. The qualitative hierarchy
in §23 governs whatever numbers are set once that architecture is chosen
and measured.

### 29. Backup health evidence contract

Regardless of which production services are ultimately selected,
"backup health is observable" means the platform can answer, at any
time: the timestamp of the last successful database backup and whether
its age has exceeded a defined threshold; the timestamp of the last
verified object-storage backup or versioning-health signal and whether
its age has exceeded a defined threshold; the timestamp and pass/fail
result of the last restore drill (§30); and a single derived health
state driven by whether any of the above has breached its threshold.
**This evidence is generated from backups `FPA-P13-S04` actually creates
(§24) — it is not a dashboard over backups that do not yet exist.**

### 30. Restore testing

A concrete, reproducible, scriptable restore procedure, run against a
named, concrete input: **the most recent database backup and
object-storage backup/version state that `FPA-P13-S04`'s mechanism
actually produced** (§24) — never an assumed external timestamp. The
procedure: restore into an isolated environment; verify a sample of
preserved originals against their stored SHA-256; run consistency checks
(every `MediaUpload` with a valid preserved original resolves to an
actual stored object; every approved `PhotoPerson` resolves to an
existing Photo and Person); and — per §31 — reconcile against any Family
Space deleted after the restored snapshot's point-in-time. The drill's
outcome is recorded as the evidence §29 requires.

### 31. Restore reconciliation and deletion permanence

A database restore recovers data as of its snapshot's point-in-time,
which may predate a Family Space deletion that has since completed.
Restoring that snapshot alone would silently resurrect deliberately
deleted data. The restore procedure requires a durable **deletion
ledger** — retained independently of, and for at least as long as, the
primary backup retention window — recording every Family Space id whose
deletion has completed and when. After any database restore, the
procedure reconciles the restored state against this ledger: any Family
Space recorded as deleted after the restored snapshot's point-in-time
has `FamilySpaceDeletionManager::teardown()` re-applied against it,
before the restored environment is considered complete.

### 32. Cross-store consistency and orphan recovery

PostgreSQL and object storage are backed up independently; their restore
points will not always coincide exactly. The existing checksum and
state-machine discipline is reused to detect and reconcile drift after a
restore, resolved according to §23's classification.

### 33. Disaster scenarios

The restore procedure and classification must cover, at minimum: total
PostgreSQL loss; accidental object-storage deletion; a single Family
Space's data corruption without a wider outage; an application bug that
deletes or corrupts metadata; a failed migration; a compromised
credential; an export-generation failure; and a broader region or
service outage.

### 34. Deletion completeness

`FamilySpaceDeletionManager::teardown()` is verified correct through
Phase 12 (§ Context) — every Phase 6-12 domain, including all six of
Phase 12's own tables, is confirmed present in its explicit delete list
today. **Phase 13's own obligation is narrower**: add this ADR's new
export-tracking table and its storage objects to that same list (§18),
and extend the object-storage cleanup to purge every version once
versioning is enabled (§25).

### 35. Proof of deletion and security/audit boundary

Deletion completion remains an `AuditEvent` (`family_space.deleted`).
Export request, download authorization, and lifecycle transitions are
audited with minimal metadata, never per-file detail or archive content.

### 36. Phase 15 and Phase 16 boundaries

Phase 13 builds backend and operational mechanisms with functional-only
UI. Phase 15 exposes safe operational controls (§29's health evidence,
restore-drill status, export visibility) through the Platform
Administrator UI. Phase 16 hardens without deferring this ADR's own
correctness requirements to it.

### 37. Product premise: no lock-in

Families remain in control of their memories. Fambam must not create
social-platform-style data lock-in: family Photos, metadata,
relationships, Stories, and interactions must remain safely preservable
and meaningfully portable outside the application.

### 38. Phase boundaries and stage ownership

**Corrected in this reconciliation: `FPA-P13-S02`/`FPA-P13-S03`'s internal
boundary was reversed, and `FPA-P13-S04` gains explicit backup-creation
ownership it previously lacked. `tasks.json`'s stage titles are
unchanged; the work each stage actually owns is corrected below.**

- **`FPA-P13-S02`** ("Implement personal and family exports") — **export
  domain and lifecycle foundation**: the export request/status model;
  full-vs-Personal-Export scope and authorization (§2, §4); the
  inclusion-selection services that resolve *which* Photos/Albums/Events
  qualify per §5-§7's live authorization rules (the "resolution" step of
  §15) without yet depending on the archive format; the queued
  lifecycle/state machine (§16, and only that lifecycle — no `Cancelled`
  state or cancellation capability exists in this ADR, per § Alternatives);
  requester ownership and expiry; typed status endpoints; and the
  generalisation boundary from `EventExportManager`. This stage does
  **not** claim to produce a final, accepted portable archive — that
  requires the format `FPA-P13-S03` defines.
- **`FPA-P13-S03`** ("Implement metadata manifest") — **archive format
  and generation**: the versioned archive schema (§8-§9); `manifest.json`
  and every per-domain JSON file; cross-domain ULID references (§10);
  honest comment/reaction legacy handling (§11); the biometric exclusion
  policy applied to manifest content (§12); the resolved meaning of
  "complete" (§3); checksums and the corrected integrity model (§14);
  actual ZIP assembly, the §7 reconciliation-before-sealing step, and
  write-once finalisation for both export scopes. After this stage, a
  real, accepted, portable export archive exists — `FPA-P13-S02` alone
  does not produce one.
- **`FPA-P13-S04`** ("Implement backup monitoring") — **backup
  classification, creation, and health evidence**: the corrected
  authoritative-vs-regenerable classification (§23, §26-§27); enabling
  and proving object-storage versioning on the local development bucket;
  provisioning a real, inspectable database backup mechanism and a real,
  inspectable object-storage backup/version mechanism for local/
  development use (§24); and the concrete, provider-neutral backup
  health evidence contract (§29), generated from the backups this stage
  itself creates. This stage creates and proves backup inputs — it does
  not merely observe backups assumed to exist elsewhere.
- **`FPA-P13-S05`** ("Perform tested restore exercise") — restore testing
  against the concrete inputs `FPA-P13-S04` actually produced: recovery
  objectives (§28), the restore procedure (§30), the deletion-ledger
  reconciliation requirement (§31), cross-store consistency handling
  (§32), and disaster-scenario coverage (§33).
- **`FPA-P13-S06`** ("Implement deletion request lifecycle") — the
  export-before-deletion prompt (§21); extending
  `S3FamilyMediaStorageCleaner` to purge every object version once
  `FPA-P13-S04` has enabled versioning (§25); and adding this ADR's own
  export-tracking table/objects to `FamilySpaceDeletionManager::teardown()`'s
  delete list (§34) — depends on `FPA-P13-S04` for versioning to already
  exist, not the other way around.

## Alternatives considered

- **Building a single mechanism that serves family export, platform
  backup, and Family Space deletion at once** — rejected: different
  audiences, authorization models, and lifecycles.
- **Claiming `EventExportManager`'s existing authorization as a reusable
  per-actor pattern** — rejected: it is coarse and unconditional once
  authorized; Personal Export's filtering is new logic.
- **Assuming presentation-level Photo inclusion implies preserved-original
  authority in Personal Export** — rejected: confirmed directly against
  `MediaUploadPolicy::downloadOriginal()` that a Contributor viewing via
  an `AlbumGrant`, and a Member viewing a `Private` Photo through
  Album-visibility widening, both lack original-download authority
  despite being able to view the Photo. Presentation inclusion and
  original inclusion are evaluated as two independent decisions per
  Photo (§7).
- **A parallel, export-specific preserved-original authorization rule**
  — rejected: `MediaUploadPolicy::downloadOriginal()` already exists as
  the live, canonical entry point; reusing it exactly avoids a second
  policy silently drifting from the first.
- **Omitting a Photo entirely from Personal Export when its preserved
  original is unauthorized** — rejected: the Photo's presentation-level
  authorization is a separate, already-satisfied decision; withholding
  the whole Photo would discard authorized metadata, tags, People
  context, and conversation the requester is entitled to for no reason
  connected to why the original itself is restricted.
- **A fabricated placeholder file or a silently absent field for an
  omitted original** — rejected: the archive schema states
  `original_included: false` explicitly (§9); a missing field with no
  explanation would look like a defect rather than an intentional scope
  result.
- **A single per-item authorization check with no final reconciliation**
  — rejected: does not protect content already written before a later
  revocation.
- **Treating a copy-time original-authorization check as the final word,
  separately from the presentation-visibility reconciliation** — rejected:
  a Photo's original could be copied while authorized and remain in the
  working archive after that authority is revoked but before sealing.
  Both dimensions — presentation inclusion and preserved-original
  authority — are now reconciled together, live, in one authoritative
  pass immediately before sealing (§7).
- **Failing the entire export when the pre-seal reconciliation finds any
  authorization drift** — superseded by trimming the working archive down
  to whatever the final reconciliation still authorizes, then sealing
  that reduced result as a valid `Ready` archive: failing outright on any
  drift, however small, would make Personal Export unreliable in an
  active family archive where some unrelated access change during
  generation is unremarkable; a trimmed-but-honest archive is safe and
  useful, an outright failure is neither.
- **Making `original_included`'s presence in `photos.json` conditional on
  `export_scope`** — rejected: one archive schema (§8) must not require a
  different parser per scope; the field is present in both scopes, always
  `true` in a full export and per-Photo in a Personal Export.
- **Requiring only active Family Space membership at full-export download
  time** — rejected: request-time authority is Owner-only, but membership
  alone would let a since-demoted former Owner still download a complete
  administrative archive. Download re-verifies the requester still holds
  the Owner role, mirroring `EventExportController`'s re-run of
  `Gate::authorize('manageExports', ...)` on every action including
  download, never weakened to a membership-only check for this export
  type.
- **`state = preserved` as shorthand for "has a preserved original"** —
  rejected: `Processing`/`Ready`/`Degraded` uploads possess the identical
  immutable original; the eligibility rule is stated around the durable
  property, using state only to exclude genuinely incompatible cases
  (`Quarantined`, `Abandoned`, pre-preservation states).
- **Exporting a promoted `MediaUpload`'s original under both
  `media/originals/` and `media/unattached/`** — rejected: promotion
  status, not upload state, decides the single path an original appears
  under, preventing duplication.
- **Treating "transactional" export notification as meaning the email
  send occurs inside the database transaction** — rejected as
  mechanically impossible for any external delivery; the durable intent
  is what is transactional, recorded atomically with the export's
  terminal state change, with email queued and idempotent after commit.
- **A parallel, Phase-13-specific notification subsystem for export
  completion** — rejected: reuse Phase 12's existing tables and
  idempotency shape, extended narrowly if needed, rather than building a
  second mechanism.
- **Leaving S04 as monitoring-only and assuming S05 has something to
  restore from** — rejected: no stage created backup inputs at all;
  S04 now owns provisioning and proving them, in local/development
  infrastructure, so S05 has a real target.
- **Silently omitting preserved-but-unpromoted `MediaUpload`s from a
  "complete" full export** — rejected: real, preserved bytes; included
  under `media/unattached/`.
- **Silently omitting soft-deleted, still-restorable records from a full
  Family Space export** — rejected: included with an honest `deleted_at`
  marker, per Owner's broader administrative authority (§3).
- **Including the requester's own soft-deleted content in Personal
  Export** — rejected: `PhotoQuery::visibleTo()`/`FamilyEventQuery::visibleTo()`
  exclude soft-deleted rows by default (confirmed directly — neither
  calls `withTrashed()`), and Personal Export must reuse those boundaries
  exactly, not introduce a tombstone bypass around them. Personal Export
  remains portability of current, active, authorized content — a
  recovery/undelete capability, if ever needed, is a separately-scoped
  decision with its own authorization semantics.
- **Adding a `Cancelled` export state, a cancellation endpoint, or
  worker-abort machinery in Phase 13** — rejected: no product requirement
  currently calls for cancellable export generation, and none of
  cancellation authority, timing, partial-object cleanup, idempotency, or
  audit semantics were ever settled for it. An unwanted export simply
  expires under the normal short retention window (§18); a future
  explicit requirement would trigger its own review, not a default
  addition here.
- **Embedding the completed archive's own SHA-256 inside a file the
  archive contains** — rejected as structurally impossible; computed
  after sealing, stored outside the archive.
- **Treating `FaceObservation` as uniformly regenerable regardless of
  what references it** — rejected: the cascading FK chain makes an
  observation referenced by an approved assignment or decided suppression
  inseparably authoritative.
- **Classifying `FaceIdentitySuppression` as recognition machinery** —
  rejected: ADR-0012 §8 establishes it as a durable human rejection
  record.
- **Classifying `NotificationPreference` as expendable** — rejected:
  losing explicit user configuration could silently reverse an opt-out.
- **Enabling object-storage versioning without extending Family Space
  teardown to purge historical versions** — rejected: would leave deleted
  family media recoverable indefinitely.
- **Treating a database restore as automatically consistent with current
  deletion state** — rejected: requires reconciliation against a durable
  deletion ledger.
- **Two separate archive formats for full and personal export** —
  rejected: one versioned specification with an `export_scope` field.
- **Password-protected or encrypted ZIP packaging in V1** — rejected: no
  vetted archive-encryption library exists in this stack.
- **Building an import/migration engine now** — rejected.
- **Inventing numeric RPO/RTO targets now** — rejected.
- **Making export a prerequisite for Family Space deletion** — rejected.
- **Renaming `tasks.json`'s Phase 13 stage titles to reflect the
  corrected S02/S03 boundary or S04's expanded scope** — not adopted:
  the existing titles remain reasonable short labels; the corrected
  internal ownership is stated in this ADR and `IMPLEMENTATION_GUIDE.md`
  without renumbering or renaming stages.

## Consequences

### Positive

- Assigning explicit backup-creation ownership, rather than leaving it
  implicit, means S05's restore drill has a real artifact to restore
  from rather than an assumed one.
- Correcting the S02/S03 boundary before implementation begins prevents
  a working exporter from being built against a format that does not yet
  exist.
- Stating the `MediaUpload` eligibility rule around the durable
  "possesses a preserved original" property, rather than one transient
  state, avoids silently excluding legitimate content (`Processing`/
  `Ready`/`Degraded` uploads) or silently including illegitimate content
  (`Quarantined`/`Abandoned` uploads).
- Defining "transactional notification" mechanically prevents an
  implementation from either attempting an impossible atomic email send
  or, at the other extreme, treating the whole notification as
  best-effort and losing the durable intent if delivery fails.
- Describing Phase 12 as the live, complete baseline it now is keeps this
  ADR accurate and removes a class of stale-assumption risk for anyone
  implementing against it.

### Negative

- Backup creation/proof is now real Phase 13 implementation scope, not a
  documentation-only monitoring task — a larger S04 than originally
  scoped.
- The two-phase resolve/assemble/reconcile generation flow, split across
  two stages, and the version-aware teardown extension are more
  implementation surface than the simpler (but incorrect) designs they
  replace.
- A durable deletion ledger, retained independently of the primary
  backup window, is new operational state this project did not
  previously need to maintain.

### Risks

- If Personal Export ever includes a Photo's preserved original merely
  because the Photo itself is presentation-authorized (via ownership, an
  Album grant, or visibility widening), a Contributor or Member could
  obtain original bytes `MediaUploadPolicy::downloadOriginal()` would
  deny them directly — worth a direct test for each confirmed live case
  (Contributor via `AlbumGrant`; Member via a `Private` Photo's Album
  widening).
- If original-download authority is ever treated as settled once copied
  into the working archive, rather than re-verified again at the final
  pre-seal reconciliation, a revocation between copy time and sealing
  would leave an unauthorized original in the delivered archive — worth a
  direct test revoking authority after copy but before sealing and
  asserting the sealed archive omits it.
- If a Photo's presentation authorization is revoked between resolution
  and sealing and the pre-seal reconciliation does not remove it (and its
  now-orphaned context), the sealed archive would disclose content the
  requester is no longer authorized to see — worth a direct test for
  exactly this case, including that any contextual record kept solely for
  the removed Photo is also removed unless another surviving Photo still
  needs it.
- If an unauthorized original is ever treated as a missing-object error
  or a degraded-archive condition rather than an intentional,
  `original_included: false` scope result, tooling or support processes
  could misdiagnose correct behaviour as a defect.
- If full-export download authorization is ever implemented as a
  membership check alone, a requester demoted from Owner after
  requesting the export could still download the complete administrative
  archive — worth a direct test demoting the requester between `Ready`
  and download and asserting the download is refused.
- If Personal Export's inclusion queries are ever implemented with
  `withTrashed()` or an equivalent tombstone bypass, soft-deleted content
  the requester once owned would leak into an export meant to reflect
  only current, active, authorized state — worth a direct test that a
  soft-deleted Photo/Event the requester created is absent from their
  Personal Export.
- If `FPA-P13-S04` is ever implemented as monitoring only, without
  actually provisioning a backup mechanism, `FPA-P13-S05` has nothing
  real to restore from — worth a direct test that the restore drill
  operates against an artifact this repository's own tooling produced.
- If Personal Export generation is ever implemented without the §7
  reconciliation pass, a revoked-mid-generation item could still leak.
- If the archive-level checksum is ever written into a file the archive
  itself contains, the archive becomes internally inconsistent by
  construction.
- If export eligibility is ever implemented as a literal
  `state = 'preserved'` filter, `Processing`/`Ready`/`Degraded` uploads
  with perfectly valid preserved originals would be silently excluded —
  worth a direct test exporting an unattached upload in each eligible
  state.
- If a promoted `MediaUpload`'s original is ever exported under both
  `media/originals/` and `media/unattached/`, the archive contains a
  duplicated original — worth a direct test asserting exactly one path
  per original.
- If export-completion email is ever implemented inside the same
  transaction as the export's state change, the transaction either hangs
  on external delivery or the notification is lost on any transaction
  rollback — worth a direct test that the durable intent survives even
  when the delivery attempt itself is delayed or retried.
- If any `FaceObservation` referenced by an approved assignment or a
  decided suppression is ever pruned or regenerated as part of routine
  backup optimization, a human identity decision is destroyed.
- If Family Space teardown is ever run against a versioned bucket without
  purging historical versions, deleted family media remains recoverable
  indefinitely.
- If a restore is ever performed without deletion-ledger reconciliation,
  a deliberately deleted Family Space could resurface.

## Implementation notes

- Stage ownership is fixed in §38: the internal S02/S03 boundary is
  corrected without renaming `tasks.json`'s stage titles; S04 gains
  explicit backup-creation ownership; S05 restores from S04's real
  output; S06 depends on S04 for versioning before it purges versions.
- **Required regression tests**: (1) an unattached `MediaUpload` in each
  of `Preserved`/`Processing`/`Ready`/`Degraded` state is included in a
  full export; one in `Quarantined` or `Abandoned` state is not; (2) a
  promoted `MediaUpload`'s original appears under `media/originals/`
  exactly once and never additionally under `media/unattached/`; (3) a
  Personal Export generation run that has access revoked partway through
  fails safely rather than delivering a mismatched archive; (4) no file
  inside any export archive contains that archive's own SHA-256; (5)
  `checksums.json` never lists a checksum for itself; (6) a full export
  includes soft-deleted Photos/People/Events with an honest `deleted_at`,
  while a Personal Export of the same requester's soft-deleted Photo,
  Event, Album, Story, comment, or reaction excludes it entirely; (6a) a
  full Family Space export requester who is demoted from Owner to any
  other role after the export reaches `Ready` cannot download it, while
  the export itself remains `Ready` and unexpired; (6b) a Personal Export
  requester who loses Family Space membership entirely after the export
  reaches `Ready` cannot download it, but a Personal Export requester who
  merely changes role (e.g. Member to Contributor) while remaining an
  active member can still download it; (6c) a Contributor who can view a
  Photo through an `AlbumGrant` gets that Photo's authorized context in
  their Personal Export with `original_included: false` and no original
  file, checksum, object key, or signed URL present anywhere in the
  archive; (6d) a Member who can view a `Private` Photo only through
  Album-visibility widening gets the same result — Photo/context present,
  original absent; (6e) a requester who does hold
  `MediaUploadPolicy::downloadOriginal()` authority for an included Photo
  gets `original_included: true` with a correct path and checksum; (6f) a
  Photo the requester cannot presentation-view at all does not appear in
  their Personal Export in any form; (6g) a Photo whose original is
  copied while the requester holds current original-download authority,
  followed by that authority being revoked before the archive seals,
  results in the sealed archive containing that Photo's authorized
  context with `original_included: false` and no preserved-original file,
  checksum, or path anywhere in it — the pre-seal reconciliation, not the
  earlier copy-time check, is authoritative; (6g-2) a Photo whose
  preliminary check at resolution time found no original-download
  authority (so its original was never copied during assembly), followed
  by that authority being granted before the archive seals, results in
  the sealed archive containing that Photo's original — copied and
  checksum-verified during the final reconciliation itself, not assembly
  — with `original_included: true`; (6h) a Photo whose
  presentation authorization is revoked before sealing is removed from
  the sealed archive entirely, including any contextual record kept
  solely to support it, unless another surviving Photo still needs that
  record; (6i) a Photo whose authorization (both presentation and
  original) is unchanged throughout generation seals with
  `original_included: true` and its original intact; (6j) Owner's full
  Family Space export is unaffected by any of the above — every Photo's
  `original_included` evaluates `true`, and the field's presence in the
  schema does not vary between export scopes; (7) a saved search
  referencing a
  now-inaccessible entity exports its
  definition with that reference honestly marked unresolved; (8) no
  face-recognition field, including `FaceIdentitySuppression` internals,
  appears in any export; (9) an approved `FaceIdentityAssignment` and its
  referenced `FaceObservation` are never excluded from backup scope; (10)
  `NotificationPreference` rows survive a full disaster-recovery restore
  cycle unchanged; (11) after Family Space teardown on a versioned
  bucket, zero object versions of any kind remain under that family's
  storage prefix; (12) restoring a database snapshot that predates a
  completed Family Space deletion results in that deletion being
  re-applied, not resurrected; (13) the backup health evidence contract
  reports a degraded state when any threshold is breached, using
  evidence from a backup this repository's own mechanism created; (14) a
  restore drill against an isolated environment succeeds against a named,
  real backup input, with its outcome recorded; (15) deleting a Family
  Space leaves zero export rows and zero export objects, at every
  historical version, in storage; (16) export-ready and export-failed
  notifications each fire exactly once per export id and terminal
  outcome, are idempotent under retry/redelivery, and reach the requester
  even when they are also the actor; (17) no archive content or biometric
  data appears in any audit record, trace, or metric label beyond bounded
  counts/sizes/checksums.

## Review triggers

- **When a production database/object-storage architecture is concretely
  selected**: fix numeric RPO/RTO targets against it, and confirm the
  production backup mechanism matches the pattern `FPA-P13-S04`'s
  local/development proof established.
- **When Phase 15 is scoped**: expose §29's backup health evidence and
  restore-drill status through the Platform Administrator UI.
- **If a genuine biometric-portability requirement emerges**: scope it
  separately.
- **If a concrete encrypted-archive requirement emerges with a vetted
  implementation approach**: revisit password/encryption packaging.
- **When import/migration is ever genuinely scoped**: resolve duplicate
  reconciliation, checksum-based deduplication, Person-merge interaction,
  and ID collision handling.

## Deferred concerns

- The specific production database and object-storage service selection,
  and the numeric RPO/RTO targets that depend on it.
- The exact local/development backup-creation mechanism `FPA-P13-S04`
  implements (e.g., `pg_dump`/snapshot tooling, LocalStack versioning
  configuration) — the requirement that it exist and produce a real,
  restorable artifact is fixed; the exact tooling is not.
- The deletion ledger's exact storage mechanism and retention duration.
- Exact physical schema/column types for export-tracking tables.
- Exact export retention duration and download URL TTL.
- Whether downloaded archive encryption ever becomes V2 scope.
- The full design of any future import/migration engine.
- Phase 15's specific operational-control surface for backup/export
  health.
- The exact mechanism by which Phase 12's notification pipeline is
  extended (if needed) to support an unconditional workflow-completion
  category for export notifications — the behavioural contract (§19) is
  fixed; whether this is a new category, a preference-bypass path, or
  direct reuse of the existing tables is implementation detail for
  `FPA-P13-S02`.

## Resolved decisions

1. **Three distinct concerns** — family export/portability, platform
   backup, and Family Space deletion are related but never one mechanism.
2. **`EventExportManager` as mechanical template only** — its queued
   generation, checksum, state-machine, and delivery pattern are reused;
   its coarse, unconditional-once-authorized model is not a per-item
   authorization precedent.
3. **Full Family Space export** — Owner-only, a complete administrative
   archive including private Photos, comments, reactions, soft-deleted
   records, and unattached preserved uploads.
4. **Personal Export** — scoped by container ownership (`created_by`),
   never narrow authorship-only rows.
5. **Personal Export inclusion** — an owned Album/Event pulls in every
   currently-authorized Photo regardless of uploader; saved searches
   export as definitions, never result snapshots; scoped strictly to
   current, active, authorized content via the existing `visibleTo()`
   boundaries — soft-deleted Photos, Events, Stories, and comments are
   excluded via their real tombstone state, and Albums/reactions (which
   carry no `SoftDeletes` trait and so have no trashed state at all) are
   excluded simply by no longer existing to be selected — in every case
   even when the requester originally created them, with no special
   recovery bypass introduced.
5a. **Preserved-original authorization is independent of presentation
    inclusion, and both are reconciled live in one authoritative pass
    immediately before sealing** — every Photo in the resolved set is
    re-checked, at that single pre-seal moment, for (A) continued
    presentation authorization, removal-only, never adding a Photo absent
    from the resolved set; and (B) continued
    `MediaUploadPolicy::downloadOriginal()` authority, evaluated
    symmetrically in either direction regardless of the earlier
    resolution-time preliminary check or whether an original was already
    copied — a newly-revoked original is deleted from the working archive
    (`original_included: false`, context retained); a newly-granted
    original the preliminary check had skipped is copied and
    checksum-verified during reconciliation itself and included
    (`original_included: true`) — so the sealed archive always matches
    current authorization exactly, never a stale decision from either
    direction, and never decided finally by an earlier copy-time check. A
    reconciled-down archive still seals as a valid `Ready` result, never
    `Failed` — this reconciliation never fails the export outright on any
    difference. `original_included` is part of the one stable archive
    schema, present on every Photo in both export scopes — always `true`
    in the Owner-authorized full Family Space export, which this entire
    reconciliation otherwise leaves unaffected.
6. **Export download authorization differs by type** — a full Family
   Space export requires the requester still be the original requester,
   an active member, **and** still hold the Owner role, mirroring
   `EventExportController`'s re-run of its management-authority gate on
   every action including download; a Personal Export requires only that
   the requester still be the original requester with current, valid
   Family Space membership/access — never an Owner requirement. Both
   share the same resolve/assemble/reconcile-before-sealing generation
   guarantee (§7).
7. **One shared, versioned archive schema** — `export_scope` distinguishes
   scopes within one specification.
8. **Archive format** — a versioned ZIP with a JSON manifest, per-domain
   JSON files, a checksums file excluding itself, and preserved
   originals/unattached uploads as real files.
9. **Stable portable identity** — Fambam ULIDs remain the cross-file
   reference identity.
10. **Comments/reactions** — included by default, with honest `album_id`
    values including real `null` for legacy rows.
11. **Biometric exclusion** — total and unconditional, explicitly
    including `FaceIdentitySuppression` internals, for every role.
12. **Face-recognition backup classification** — approved
    `FaceIdentityAssignment`, decided `FaceIdentitySuppression`, and the
    `FaceObservation` rows either references are authoritative.
13. **Original media preservation** — only preserved originals are
    exported.
14. **Export integrity** — the archive-level checksum is computed after
    sealing and stored outside the archive.
15. **`MediaUpload` export eligibility** — determined by "possesses a
    valid, checksum-verified preserved original" (`Preserved`,
    `Processing`, `Ready`, or `Degraded` state), never a literal
    `state = 'preserved'` filter; a promoted upload's original is
    exported exactly once, never duplicated across `originals/` and
    `unattached/`.
16. **S02/S03 boundary** — S02 owns export domain/lifecycle/authorization
    foundation; S03 owns the archive format and the generation work that
    depends on it; S02 never claims to produce a final accepted archive.
17. **S04 backup ownership** — S04 creates and proves backup inputs
    (local/development database and object-storage versioning
    mechanisms), not merely monitors evidence of backups assumed to
    exist; S05 restores from S04's real output.
18. **Object-storage durability and deletion** — versioning protects
    against accidental loss; Family Space teardown, extended once S04
    enables versioning, purges every object version, so deliberate
    deletion remains complete.
19. **Export notifications** — a durable notification intent is recorded
    atomically with the export's terminal state transition; email is
    queued and idempotent after commit, never part of that transaction;
    identity is the export id plus terminal outcome.
20. **Import posture** — format import-friendly; no engine in Phase 13.
21. **Export-before-deletion** — strongly prompted, never required.
22. **Backup classification** — preserved originals, authoritative
    PostgreSQL data, human-confirmed metadata, and `NotificationPreference`
    receive the strongest durability; regenerable derived data is
    rebuilt; delivery/operational history is expendable.
23. **Backup health evidence contract** — a concrete, provider-neutral
    set of observable signals, generated from backups this repository's
    own mechanism creates.
24. **Recovery objectives** — a qualitative durability hierarchy is fixed
    now; numeric RPO/RTO are deferred until a production architecture is
    selected and measured.
25. **Restore testing and reconciliation** — mandatory, concrete, run
    against real inputs `FPA-P13-S04` produces, and required to
    reconcile against a durable deletion ledger.
26. **Phase 12 baseline** — Phase 12 is complete; all six of its tables
    (`FamilyActivity`, `NotificationDelivery`, `FamilyNotification`,
    `NotificationCandidate`, `NotificationPreference`, `ContributionGroup`)
    exist today and are already integrated into
    `FamilySpaceDeletionManager::teardown()`; Phase 13 adds only its own
    new tables to that same list.
27. **Phase boundaries** — Phase 13 builds functional backend mechanisms
    only; Phase 15 owns operational/admin UI; Phase 16 hardens without
    introducing new fundamental correctness.
