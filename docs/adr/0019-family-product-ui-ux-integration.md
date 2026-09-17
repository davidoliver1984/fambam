# ADR-0019: Family Product UI/UX Integration

- Status: Accepted
- Date: 2026-09-16
- Decision owners: David
- Related stages: FPA-P14-S06 (accepting this ADR completes that stage),
  implemented by FPA-P14-S07 through FPA-P14-S12

## Context

Phase 14 exists to turn the completed Family Space capabilities into one
coherent, welcoming, navigable product experience. A frozen visual/product
reference (the Phase 14 UI Reference V1 mini-site) already exists and is
the accepted visual/interaction source of truth for every surface it
covers; the repository remains the architectural/domain source of truth.
Comparing the reference against the live, accepted domain model found
several reference behaviours with no accepted domain/API support yet:
richer Album metadata and a cover image, Event RSVP as a concept distinct
from admission, a personal Photo-curation tool ("Collections"), a
lightweight "Love" reaction across several content types, a nondestructive
Photo editor with an automatic "Restore" operation, and export/download
semantics that must resolve to a Photo's current edited presentation
rather than only its untouched original. This ADR is that reconciliation:
it defines the **minimum** domain/API additions required for the
production application to faithfully implement the frozen reference,
without redesigning the reference, without reopening ADR-0021's Story
architecture, and without pulling Phase 15 Platform Administration
scope into Phase 14.

**ADR-0019 is the correct, previously-reserved number for this decision.**
`tasks.json`'s ADR-tracking array already reserves `ADR-0019` —
`"Family product UI/UX integration"`, `roadmap_phase: "P14"`,
`required_by_stage: "FPA-P14-S06"` — confirmed directly; no `docs/adr/0019-*.md`
file exists yet, so this is that ADR's first draft, not a new number.
`ADR-0021` (First-class interactive Stories) is `Accepted` and governs the
Story domain exclusively; this ADR does not reopen it, and where Story
touches this ADR's scope (Love, deriving a heading, mentions) it is only
ever restated, never changed (§37).

**This reconciliation closes four further implementation-readiness
blockers found by a second repository-grounded review**, each requiring
direct code-grounding before correction, not assumed:

- **A boolean pending-cover flag on `MediaUpload` cannot prevent a stale,
  still-processing cover choice from overwriting a newer one.** If a User
  starts an uploaded cover, then picks a different cover before the first
  upload finishes, nothing in the first draft's model stopped the first
  upload's later finalization from winning merely by arriving last — a
  timing/order outcome, not an authoritative one. §9-§11 replace the
  boolean with one authoritative `albums.current_cover_intent_id` column,
  compared by identity at finalization, with contribution authority
  explicitly insufficient on its own to manage the cover (§10).
- **`FamilyArchiveBuilder::buildAndStore()` writes the archive object to
  storage before `FamilyExportManager::generate()` calls `markReady()`.**
  Confirmed directly (`apps/api/app/Exports/FamilyArchiveBuilder.php:88`,
  `FamilyExportManager::generate()`): a `Processing` export can already
  have a real storage object even though its row has not yet reached
  `Ready`. A Collection deletion that assumes `Processing` means "no
  object exists yet" can leave an orphan. §27-§29 add an explicit
  fence-and-recheck interlock, and correct `collection_id`'s FK strategy
  and `selection_checksum`'s source of truth.
- **`love_notification_groups`' original `target_type`/`target_id` pair
  is an unvalidated generic reference, contradicting this ADR's own
  standing typed-FK discipline, and `NotificationCandidate` alone cannot
  answer "how many distinct actors."** §35 replaces the generic pair with
  typed, tenant-consistent, cascading target columns matching `reactions`'
  own shape, and adds a small, normalized actor-membership table rather
  than inferring a count from notification-delivery rows.
- **A single-column `active_photo_version_id` FK is `SET NULL`-safe but
  does not, by itself, prove the referenced version belongs to *this*
  Photo** — the first draft's wording overclaimed structural impossibility
  where only a transitive, service-enforced guarantee actually existed.
  §39-§40 replace this with a database-enforced same-Photo, same-tenant
  composite foreign key, following this project's stated preference for
  database-enforced tenant consistency wherever a valid design exists.

**This reconciliation also retains the eight blockers closed by the first
review round**, summarized here for continuity:

- **Album cover upload is asynchronous, not synchronous.** Direct
  inspection of `MediaUploadController`, `AlbumContributionFinalizer`
  (`apps/api/app/Services/AlbumContributionFinalizer.php`), and
  `DuplicateHoldManager` confirms cover upload actually flows through the
  same `MediaUpload → processing → AlbumContributionFinalizer::finalize()`
  pipeline every ordinary Album contribution uses, which may itself pause
  for exact-duplicate resolution (`DuplicateHoldManager::resolve()`). The
  original draft's "the resulting Photo, once promoted, is associated with
  the new Album and set as its cover" glossed over this entirely — §8-§11
  now define the pending-intent/finalization model this actually requires.
- **`AlbumManager::addPhoto()`'s existing safeguards are more specific
  than "Album-create authority."** Confirmed directly
  (`apps/api/app/Services/AlbumManager.php:129-151`): adding a Photo
  requires an explicit `$confirmed` flag when doing so would widen a
  private Photo's audience, and additionally requires
  `$actor->can('update', $photo)` in that same widening case — both
  preserved unchanged by every cover-finalization path (§10).
  `AlbumContributionFinalizer::completeNewContribution()` and
  `DuplicateHoldManager::resolve()`'s `Use existing Photo` branch (which
  itself already calls `AlbumManager::addPhoto()` with a
  `confirm_visibility_widening` input) are the two paths a pending cover
  intent must finalize through, confirmed directly.
- **`Photo` uses `SoftDeletes`, and `album_photos` is never itself
  soft-deleted.** Confirmed by re-inspection: a soft-deleted Photo's
  `album_photos` row is left completely in place. A naive
  "does an `album_photos` row exist" cover-membership check would
  therefore wrongly treat a trashed Photo as a valid cover — §11-§12
  correct this explicitly.
- **`albums.id`/`photos.family_space_id` are both `NOT NULL`, ruling out
  a composite `SET NULL` strategy for either the cover-membership
  invariant or `active_photo_version_id`.** A composite FK's `ON DELETE
  SET NULL` nulls *every* referencing column at once; nulling a primary
  key (`albums.id`) or a required tenant column (`photos.family_space_id`)
  is impossible and would corrupt the row. This is the same structural
  trap in both places — corrected once, explicitly, in §12 and §40, rather
  than patched independently.
- **`EventAdmissionManager::admit()` (`apps/api/app/Services/EventAdmissionManager.php:16-31`)
  reuses the same `EventAdmission` row across a revoke/readmit cycle** via
  `updateOrCreate(['event_id', 'family_space_membership_id'], [...])` —
  confirmed directly. A naive `rsvp_status` column would silently carry a
  pre-revocation answer into a brand-new admission period, since
  `updateOrCreate()`'s update array does not touch it. §17-§18 correct
  this explicitly.
- **No `AlbumPerson`/`EventPerson`-shaped table integrates with
  `PersonMergeManager` today, and both are genuinely new Person
  references.** ADR-0006 §12's standing obligation — "every future
  Person-referencing table must integrate with merge behaviour when it is
  introduced" — was not yet honoured for `album_people`/`event_people` in
  the first draft. §20 closes this using the exact collision-aware
  repoint/guarded-reversal pattern `saved_search_people` and ADR-0021's
  own mention tables already establish.
- **`FamilyExportSelection` (`apps/api/app/Exports/FamilyExportSelection.php`)
  is an ephemeral value object, never persisted**, and
  `FamilyArchiveBuilder`'s existing behaviour is archival-snapshot
  semantics (originals preserved per existing authority, never
  re-validated after build) — confirmed directly. A Collection is not an
  archival snapshot; it is live, frequently-mutated, personal state, so
  "reuse `FamilyArchiveBuilder` unchanged" was inaccurate. §27-§29 define
  Collection-specific selection, packaging, and download-time
  revalidation, reusing `FamilyExportManager::authorizeDownload()`'s
  already-existing re-check-at-download checkpoint
  (`apps/api/app/Services/FamilyExportManager.php:135-161`, which already
  re-validates `expires_at` at exactly this moment) rather than inventing
  a new one.
- **`ContributionGroup` (`apps/api/app/Models/ContributionGroup.php`,
  natural key `family_space_id, actor_user_id, upload_batch_id, album_id`)
  batches one actor's simultaneous uploads — it cannot represent "several
  different actors Loved the same target."** Confirmed directly: its
  natural key is actor-and-batch-centric, the opposite dimension Love's
  batching needs (target-centric, actor-agnostic). §35 defines a small,
  genuinely new, target-keyed grouping table instead of claiming
  `ContributionGroup`'s methods apply verbatim.
- **Photo Love is gated by `PhotoPolicy::interact()` plus, for
  Contributor, `AlbumPolicy::contribute()` on the specific Album
  context — never merely `PhotoPolicy::view()`.** Confirmed directly
  against `PhotoConversationController::react()`/`authorizeInteraction()`/
  `canInteract()` (`apps/api/app/Http/Controllers/PhotoConversationController.php:78-85,140-152`):
  every reaction requires an Album context and passes through
  `canInteract()`, which checks `Gate::allows('interact', $photo)` and,
  for Contributor, `Gate::allows('contribute', $album)`. The first draft's
  claim that Photo Love already used only `view()` was wrong and is
  corrected in §33.
- **`NotificationManager::authorized()` and `NotificationController::visible()`
  (`apps/api/app/Http/Controllers/NotificationController.php:66-82`) are
  two separate, independently-gapped methods** — confirmed directly, the
  controller's `visible()` has exactly the same missing-`event_id`-branch
  gap as the manager's `authorized()`, and both must be closed together
  (§53-§54) or an Event-linked notification could be delivered correctly
  but remain visible in the inbox after access is revoked, or vice versa.
- **A database default that initializes `rsvp_status` on every existing
  `EventAdmission` row is a data-initialization step, not "no backfill."**
  The first draft's literal claim is corrected in §56.

**A fourth review round found that neither Collection deletion's own
immediate object-deletion attempt, nor the worker's post-write recheck, is
individually sufficient**, because a worker can write the object *after*
deletion's one-shot attempt already ran and then crash before its own
recheck, leaving nothing scheduled to ever revisit it. **A fifth review
round then found that the fourth round's own fix — marking a
`cancelled_at`-fenced export's cleanup terminal the moment any single
delete attempt returned success-or-not-found — repeated the identical
mistake one level up**: "the object was absent when checked" does not
prove "this deterministic key can never be written later," so the exact
same worker could still write the object *after* that terminal marker was
already set, this time with no reconciliation left to ever find it. §28's
final design closes both gaps together: extending this project's existing
scheduled export-cleanup infrastructure — confirmed directly,
`app_due_family_exports()`
(`apps/api/database/migrations/2026_09_10_000000_create_family_exports.php:75-85`)
is the SQL function `DispatchDueFamilyExports`'s existing scheduled
command already uses to discover exports needing cleanup — with a second
query branch covering `cancelled_at IS NOT NULL AND storage_reconciled_at
IS NULL`, where `storage_reconciled_at` is set only once generation is
independently confirmed *quiescent* (no worker can possibly still write
that key) **and** the object is then confirmed absent — never from a
delete outcome alone. This is the actual, durable correctness guarantee;
every immediate, one-shot attempt (deletion's own, and the worker's)
is explicitly described as a promptness optimization only, never as
proof of terminal cleanup.

**A sixth review round found that `generation_finished_at` itself could
go stale across a retry.** `FamilyExportManager::beginGeneration()`
already allows a `Failed` (non-cancelled) export to begin again; if that
retry set `generation_started_at` for the new attempt without also
clearing `generation_finished_at` back to `null`, a quiescence check run
while the retry's own worker is still actively writing would incorrectly
read the *previous* attempt's leftover completion timestamp as proof the
*current* attempt had already concluded — precisely the same class of
"one observation doesn't prove no writer remains" mistake the fourth and
fifth rounds already corrected elsewhere, recurring one level up in the
retry lifecycle. §28 corrects this with one additional, atomic invariant:
`beginGeneration()` clears `generation_finished_at` to `null` in the same
transaction as setting `generation_started_at` and transitioning to
`Processing`, every time it starts an attempt — so a non-null
`generation_finished_at` always, unambiguously, describes the current
attempt, never a stale one.

**Live-code grounding retained from the first draft, unaffected by the
above**:

- `Album` (`apps/api/app/Models/Album.php`,
  `apps/api/database/migrations/2026_08_24_020000_create_albums.php`)
  today carries only `name`, `description` (already a rich-text document,
  ADR-0021 §11), `visibility`, `event_id`, `guest_participation` — no
  date, location, tags, People association, or cover of any kind exist.
- `FamilyEvent` (table `events`) already carries `starts_on`/`ends_on`
  (plain nullable `date` columns with a `CHECK (ends_on >= starts_on)`)
  and `location` (a plain nullable `string(255)`) —
  confirmed in `apps/api/database/migrations/2026_08_25_010000_create_events_and_event_references.php`.
  This is the exact "smallest repository-consistent representation" the
  Context asks Album's date/location to reuse (§3-§4) — there is no place/
  geography abstraction anywhere in this codebase to generalize instead.
- `Tag`/`photo_tag` (`apps/api/database/migrations/2026_08_24_000000_create_photos_and_provenance.php:58-82`)
  is already a Family-Space-scoped, deduplicated-by-`normalized_label`
  vocabulary with a simple pivot (`family_space_id, photo_id, tag_id,
  added_by, created_at`) — the direct precedent Album tags reuse (§5).
- `AlbumGrant` (`can_view`/`can_contribute` per `family_space_membership_id`)
  is Album's only existing per-Person-shaped table, and it is
  authorization-bearing. No `AlbumPerson`/`EventPerson`-shaped
  descriptive-only association exists anywhere — confirmed by direct
  search — so "Album People"/"a historical Person connected to an Event"
  (§6, §19) are genuinely new, small, explicitly non-authorization-bearing
  tables, never a reuse of `AlbumGrant`.
- `EventAdmission` (`apps/api/app/Models/EventAdmission.php`) is keyed by
  `family_space_membership_id`, carries `admitted_at`/`revoked_at`, and —
  per ADR-0009 and `AlbumPolicy`'s identical Guest-gating pattern — exists
  specifically for the Guest/Contributor population that needs an explicit
  per-Event access grant; Owner/Administrator/Member get default access
  with no `EventAdmission` row at all. This is the direct precedent for
  where RSVP state lives (§14-§15).
- `Invitation` (`status`: pending/accepted/revoked/expired) governs joining
  a Family Space or an Event's Guest/Contributor role — a different
  lifecycle from attendance response, confirmed by inspecting its fields;
  RSVP is not layered onto `Invitation`.
- `PhotoReaction`/`photo_reactions`
  (`apps/api/database/migrations/2026_08_24_030000_create_photo_conversations.php:41-46`,
  re-scoped to `(photo_id, album_id, user_id)` by
  `2026_08_26_010000_add_exact_duplicate_detection.php:19-25`) already
  implements a 4-value reaction vocabulary (`love`, `smile`, `laugh`,
  `remember`) for Photo, Album-context-scoped, governed by **ADR-0008 §12**
  (not ADR-0010, which only later re-scoped its `(photo_id, album_id)`
  keying — corrected here for citation accuracy). This is the direct,
  already-shipped precedent Love reuses for the Photo target (§32), rather
  than a new competing mechanism.
- `MediaVariant` (`apps/api/app/Models/MediaVariant.php`) is exclusively
  a fixed, regenerable **delivery-size** projection (thumbnail/card/
  display) of the canonical asset — ADR-0007 §11 states variants "carry no
  archival authority whatsoever." It has no concept of a user-authored
  edit and is not touched by this ADR; the nondestructive editor (§38-§46)
  introduces a genuinely new, separate concept.
- `MediaUploadPolicy::downloadOriginal()` and `FamilyArchiveBuilder`'s
  existing `original_included` computation
  (`apps/api/app/Exports/FamilyArchiveBuilder.php:157,254`) are the exact,
  unchanged authority this ADR's export reconciliation (§49-§51) builds
  on — original-download authority is never revisited.
- `NotificationCategory` (`apps/api/app/Enums/NotificationCategory.php`)
  currently has five cases (`Comment`, `Contribution`, `Story`, `Identity`,
  `Export`); `NotificationManager`'s `recipients()`/`typedSubject()`/
  `authorized()`/`url()` methods are per-category `match`/`if` dispatches,
  and `registerContribution()`/`finalizeContribution()` already establish
  a durable, delayed-batch-then-consolidate pattern for grouping several
  related actions into one notification — the direct architectural
  precedent Love's own, differently-keyed grouping table follows (§35).

**Standing constraints this ADR inherits and does not revisit**: Family
Space tenancy and RLS (ADR-0005); User ≠ Person and `PersonAccountLink`
(ADR-0006); ADR-0007's immutable-original/regenerable-canonical-and-variant
media split; ADR-0008 Photo ownership and the existing reaction vocabulary;
ADR-0009 Event admission/participation authorization; ADR-0010's
Album-scoped comment/reaction conversation semantics; ADR-0012 face
identity; ADR-0014 notification architecture and durable candidate
idempotency; ADR-0015 export/original-download authority; ADR-0021's
first-class Story domain in full; the existing, unchanged
`PersonMergeManager` contract, including its standing prohibition on an
active chained merge. Platform Administration remains Phase 15. Stripe,
Family Tree, and Neighbourhoods are separate, later V1 feature passes,
explicitly out of scope here.

## Decision

### 1. Scope

This ADR adds the minimum domain/API surface the frozen Phase 14 UI
Reference V1 requires and does not yet have: Album metadata and an
optional cover, including its asynchronous upload/finalization contract
and membership-invariant enforcement (§2-§12); Event RSVP as a concept
independent of admission, including revocation/readmission semantics
(§14-§21); a private, per-User Collection domain with a Collection-aware
export lifecycle (§22-§29); a shared "Love" reaction across
Photo/Album/Event/Story with accurate per-target authority and its own
notification aggregation (§31-§36); a restatement of ADR-0021's Story
contract for production use (§37); a nondestructive Photo editor and
version model with a same-Photo integrity invariant, authorized version
delivery, and structured Restore provenance (§38-§48); reconciled
export/download semantics (§49-§51); and closed Event-notification
authorization gaps spanning both delivery and inbox visibility (§52-§54).
It does not redesign the reference UI, does not change any existing
authorization boundary beyond what is explicitly stated, and does not
introduce Platform Administration, Stripe, Family Tree, or Neighbourhoods
scope.

### 2. Album metadata: overview

Album gains five independent, optional capabilities — date/date-range,
location, tags, a People association, and a cover — each additive to the
existing `albums` table or a small new table, none changing `AlbumPolicy`,
`AlbumVisibility`, `AlbumGrant`, or any existing Album authorization rule.
None of these fields is required; an Album with none of them set behaves
exactly as it does today.

### 3. Album date range

`albums` gains `starts_on`/`ends_on` (nullable `date` columns), reusing
`events`' exact representation and its `CHECK (ends_on IS NULL OR
starts_on IS NULL OR ends_on >= starts_on)` constraint verbatim. A
single-day Album sets only `starts_on`, leaving `ends_on` null — the UI
never requires an end date merely because a start date is present. No
Album currently has any date; this is a purely additive, always-optional
column pair.

### 4. Album location

`albums` gains `location` (a plain nullable `string(255)`), reusing
`events.location`'s exact representation. No geography/place abstraction
is introduced — this project has none today, and one is not warranted for
a free-text descriptive field used identically to Event's own.

### 5. Album tags

Album tags reuse the existing, Family-Space-scoped `Tag` model and its
`normalized_label` deduplication/autocomplete precedent exactly — the
same vocabulary Photo tags already draw from, so a tag typed for a Photo
is immediately available to autocomplete for an Album and vice versa. A
new `album_tag` pivot mirrors `photo_tag`'s shape exactly: `family_space_id,
album_id, tag_id, added_by, created_at`, `PRIMARY KEY (album_id, tag_id)`,
cascadeOnDelete on both `albums` and `tags`. Album tags are descriptive/
discovery metadata only: they never gate `AlbumPolicy::view()`/`contribute()`,
never imply a Person appears in the Album, and never imply Event
membership — exactly as Photo tags already carry no authorization
weight.

### 6. Album People association

A new, small, explicitly non-authorization-bearing `album_people` table —
`id, family_space_id, album_id, person_id, added_by, created_at`,
`UNIQUE(album_id, person_id)`, tenant-consistent composite FKs to
`albums(id, family_space_id)` and `people(id, family_space_id)`,
cascadeOnDelete on both. It states "this Person is relevant to this
Album" — a description, not a fact about any specific Photo. It never
implies the Person appears in every (or any) Photo in the Album, never
grants or bounds authorization (it is not consulted by `AlbumPolicy`,
`PhotoPolicy`, or mention/People-directory bounding — matching this
project's standing rule that a purely descriptive association is never
consulted by authorization, the same discipline `FamilyCircle` already
established in ADR-0006), and never substitutes for `PhotoPerson`'s
confirmed, per-Photo human-reviewed identity. Add/remove authority mirrors
`AlbumPolicy::update()` (the Album's manage-authority) — no new role
matrix. Being a genuine Person reference, it is integrated into
`PersonMergeManager` in the same stage it is introduced (§20).

### 7. Album cover: schema and focal position

`albums` gains three nullable columns: `cover_photo_id` (typed FK,
tenant-consistent composite to `photos(id, family_space_id)`), `cover_focal_x`,
`cover_focal_y` (`numeric(4,3)`, each constrained `0 <= value <= 1` — a
fractional position within the cover image, translated directly to CSS
`object-position` percentages by the frontend; this is "a presentation
focal position," never a second stored image, satisfying the instruction
not to create a duplicate cover asset). Both focal columns default to
`0.5`/`0.5` (center) whenever a cover is set without an explicit
reposition. **`cover_photo_id`'s foreign key uses `ON DELETE RESTRICT`,
never `SET NULL`** — `albums.id` is a primary key and can never be nulled
by any FK action, so a composite `SET NULL` strategy is not just
undesirable here but structurally invalid; `RESTRICT` is also correct in
practice, since `photos` is never hard-deleted outside Family Space
teardown (§36/ADR-0021 §36's own established convention), by which point
the owning `Album` row (and therefore `cover_photo_id` itself) has already
been removed in the same teardown transaction (§55). **A non-null
`cover_photo_id` must reference a Photo currently, and validly, associated
with that Album** — the full membership invariant, including why it is
enforced at the service layer rather than a declarative constraint, is
specified once, completely, in §12, covering every lifecycle path that
could otherwise invalidate it.

### 8. Album cover: synchronous creation-time choices

Two of the reference's three `Add cover photo` choices are synchronous and
require no pending-intent tracking:

- **Choose from Fambam** — browse/search the creating User's currently-
  authorized Photos (the existing Photo `visibleTo()` boundary, unchanged).
  Selecting an existing Photo never duplicates it: on successful Album
  creation, that Photo is associated with the new Album (an `album_photos`
  row is created for it if one does not already exist for this creation,
  through the ordinary `AlbumManager::addPhoto()` path with its existing
  safeguards, §10) and set as `cover_photo_id`, atomically with Album
  creation.
- **Not now** — the Album is created with `cover_photo_id` left null. An
  Album with no cover is a fully valid, permanent state, not a transient
  "incomplete" one.

No new authorization is introduced for either choice: creating an Album
this way requires only `AlbumPolicy::create()`, exactly as Album creation
already does. The third choice, **Upload a photo**, is asynchronous and is
specified separately in §9-§10, because — corrected in this reconciliation
— it cannot complete synchronously with Album creation at all.

### 9. Album cover: authoritative current-cover-intent identity

**Corrected in this reconciliation: a boolean flag on `MediaUpload` cannot
prevent a stale, still-processing cover choice from overwriting a newer
one.** If a User starts an uploaded cover, then changes their mind and
picks a different cover (another upload, or an existing Photo) before the
first upload finishes, the first upload's later finalization must not be
able to win merely by arriving after the Album already has an
authoritative choice — no timing/order heuristic is used to prevent this.

**The Album itself is created immediately, before an uploaded intended
cover has finished processing.** Reusing the existing pipeline exactly —
`MediaUpload` → processing → `AlbumContributionFinalizer` — `albums`
gains one nullable column that is the Album's single, authoritative
record of *which* pending choice currently has the right to become the
cover: `current_cover_intent_id` (a plain `char(26)`, not a foreign key —
it names whichever `MediaUpload` is presently authoritative, and a
`MediaUpload` may legitimately finish, be superseded, and later be purged
by unrelated retention independently of this column's own lifecycle, so
it is compared by value at finalization, never dereferenced as a live
join). `cover_photo_id` itself remains `null` on the Album for the entire
pending window; this is the only new column the pending-intent model
requires — no new table, and no flag on `MediaUpload` itself.

**Corrected in this reconciliation: setting, replacing, or intentionally
clearing `current_cover_intent_id` — including choosing an existing Photo
as the cover candidate — always requires the acting User's *current*
`AlbumPolicy::update()` authority on the Album, never contribution
authority alone.** The ordinary Album upload endpoint authorizes
contribution, not Album management, and confirmed directly, contribution
authority is strictly weaker than update authority for at least
Contributor and some Guest participation levels — without this check, a
Contributor able to contribute a Photo could overwrite an Owner or
Administrator's already-current cover intent, even though finalization
would later fail their own attempt for lack of update authority (§10).
**This is a separate, additional authorization check from the ordinary
upload/contribution authorization that gates the underlying `MediaUpload`
itself** — the two are never conflated into one check:

- **The ordinary upload proceeds under contribution authority exactly as
  it always does**, regardless of whether it is also being proposed as a
  cover candidate — a Contributor without Album-update authority may
  still upload a Photo to the Album normally.
- **Marking that upload — or an existing Photo — as the Album's current
  cover intent is a distinct action, gated by `AlbumPolicy::update()`.**
  If the acting User lacks it, that specific action is rejected: the
  upload (if one is involved) proceeds as an ordinary contribution, but
  `current_cover_intent_id` is neither set nor replaced by it.
- **Starting an uploaded cover choice** — having passed the
  `AlbumPolicy::update()` check above — sets `current_cover_intent_id` to
  that upload's own id, in the same transaction the upload is initiated
  with its cover intent.
- **Starting a different, later cover choice — another upload, or an
  existing Photo — immediately supersedes it**, subject to the identical
  `AlbumPolicy::update()` check: `current_cover_intent_id` is overwritten
  to the new upload's id, or cleared to `null` if the new choice is an
  existing Photo (§8, which resolves synchronously and needs no pending
  window at all). The superseded upload is never cancelled or otherwise
  interfered with by this — it continues processing normally and, if it
  completes successfully, still becomes an ordinary Photo through the
  unmodified contribution pipeline (§25's ordinary bulk-population
  reasoning applies here too: a Photo's existence is never contingent on
  whether it ever becomes anyone's cover) — it simply loses the right to
  also become the Album's cover.

### 10. Album cover: uploaded-cover finalization

Whenever `AlbumContributionFinalizer` (or `DuplicateHoldManager::resolve()`,
for the exact-duplicate case) is finalizing a Photo that originated from a
cover-intending upload, the **existing** membership safeguards apply
completely unchanged — an uploaded cover is never exempt from them — and
a **new supersession check gates cover assignment specifically**, in this
order:

1. the ordinary Album Photo-add path runs (`AlbumContributionFinalizer::completeNewContribution()`
   or `AlbumManager::addPhoto()`, depending on path), exactly as it would
   for any other contribution, regardless of what happens to the cover
   question below — the Photo is created/resolved and its Album
   membership established on its own merits;
2. `AlbumManager::addPhoto()`'s existing private-Photo-visibility-widening
   confirmation requirement, and its `$actor->can('update', $photo)`
   authority check for that same widening case, apply exactly as they do
   for any other contribution;
3. **the Album row is locked and reloaded, and `current_cover_intent_id`
   is compared against this upload's own id**:
   - **if it no longer matches** — this cover intent has been superseded
     (§9) — `cover_photo_id` is **not** touched, and this old intent
     terminates cleanly with nothing further to do; the Photo and its
     Album membership from step 1 remain completely intact, exactly as
     an ordinary contribution's would;
   - **if it still matches** — this is still the Album's authoritative
     cover choice, and finalization proceeds to step 4;
4. **the requesting actor's *current* `AlbumPolicy::update()` authority on
   the Album is re-checked** — explicitly, and separately from both the
   contribution authority step 1 already required and the
   `AlbumPolicy::update()` check §9 already required at the moment the
   intent was originally set. **Contribution authority alone is not
   sufficient to manage the cover, and neither is having once held update
   authority**: an actor able to contribute a Photo to an Album is not
   necessarily still able to manage that Album's cover by the time a
   delayed upload resolves, and an actor who genuinely held update
   authority when the intent was set may have lost it since (role or
   grant changes are possible over an unbounded processing/duplicate-
   resolution window), so this finalization-time check is never skipped
   or assumed satisfied by either of the earlier checks;
5. **only once both step 3 (still current) and step 4 (still authorized)
   pass** does `cover_photo_id` get set, to this Photo, with default focal
   position, in the same transaction;
6. `current_cover_intent_id` is cleared to `null` once this intent
   resolves either way (finalized in step 5, or terminated in step 3) —
   an already-resolved or already-superseded intent can never be
   re-consumed by a later, unrelated event.

**Exact-duplicate resolution integrates identically**: if the actor
chooses `Use existing Photo`, that existing Photo becomes the
finalization candidate — `DuplicateHoldManager::resolve()`'s existing
call to `AlbumManager::addPhoto()` (which already threads through the
same `confirm_visibility_widening` input) is the path step 1 runs
through, and no duplicate Photo is ever created — steps 3-6 above then
apply identically to that existing Photo. If the actor instead chooses
`Create separate Photo`, the newly-created Photo (via
`AlbumContributionFinalizer::completeNewContribution()`) follows the
exact same steps 3-6 once its own Album membership succeeds. Either way,
cover assignment is a small, final, doubly-gated step appended to an
existing, unmodified contribution flow — never a parallel authority path,
and never claimed to require only "Album-create" or mere contribution
authority.

### 11. Album cover: upload failure and cancellation

If MediaUpload processing fails, the upload is cancelled, or Photo
association otherwise cannot safely complete, cover finalization (§10)
never runs at all for that upload. Cleanup is scoped to *this* intent
only, never a newer one that may already have superseded it: the Album is
locked and reloaded, and **only if `current_cover_intent_id` still equals
this upload's own id** is it cleared back to `null` — if it has already
been superseded (§9), it is left completely untouched, since clearing it
now would incorrectly erase a newer, unrelated intent that has nothing to
do with this failure. **The existing `cover_photo_id` is never altered by
a failed or cancelled upload** — a failed *replacement* attempt leaves
whatever cover the Album already had (if any) exactly as it was; nothing
is cleared merely because a new candidate failed to materialize. No
special storage or MediaUpload-state cleanup step is required beyond this
project's existing handling of failed/cancelled `MediaUpload` states,
since nothing was ever written to the Album beyond the intent-identity
column itself. The User may retry the same upload (a fresh attempt is a
fresh intent, handled identically) or choose a different cover entirely
at any later time, through the ordinary post-creation lifecycle (§13).

### 12. Album cover: membership-invariant enforcement across the full lifecycle

**A declarative database constraint cannot express "an active Album cover
must always be a Photo currently, validly, belonging to that Album"** —
the same structural reason a composite `SET NULL` is unusable here (§7):
the natural composite-FK shape, `(id, cover_photo_id) REFERENCES
album_photos(album_id, photo_id)`, would need `ON DELETE SET NULL` to
clear a removed membership's cover, but that action would attempt to null
`albums.id`, its own primary key. **The invariant is therefore enforced
entirely at the service layer, locked and covered by a direct regression
test for every lifecycle path that could otherwise invalidate it**:

- **Assigning a cover** — requires an existing `album_photos` row for
  `(album_id, cover_photo_id)`, checked in the same transaction (§7, §10).
- **`AlbumPhoto` removal** — the guarded removal flow already specified
  (the original §9, retained as §13 below) clears the cover atomically
  when its Photo is removed.
- **Removing the current cover from the Album** without removing
  membership (a plain "unset cover" action) — simply clears
  `cover_photo_id`/focal columns; no membership change is implied or
  required.
- **Photo soft deletion** — **corrected in this reconciliation**:
  because `album_photos` is never itself soft-deleted, a soft-deleted
  Photo's membership row remains present, so cover validity is *not*
  fully captured by "does a membership row exist" alone. Soft-deleting a
  Photo that is currently any Album's cover must, in the same transaction
  as the soft delete, clear that Album's `cover_photo_id`/focal columns —
  mirroring §13's guarded-removal clearing exactly, triggered by
  deletion instead of explicit removal.
- **Photo restoration** — does **not** automatically restore a
  previously-cleared cover; consistent with this ADR's standing rule that
  no automatic replacement cover is ever chosen (§13), a User must
  explicitly re-set the cover after restoring the Photo.
- **Album deletion** — `cover_photo_id` disappears with the Album row
  itself; no separate action is required.
- **Family Space teardown** — the Album row (and therefore
  `cover_photo_id`) is already removed by `FamilySpaceDeletionManager`
  before `Photo::withTrashed()->forceDelete()` runs (confirmed ordering,
  §55), so no dangling reference is ever possible.

### 13. Album cover: post-creation lifecycle

After creation, a cover may be added (if none exists), changed, removed,
or repositioned (focal `x`/`y` updated) at any time by anyone who holds
`AlbumPolicy::update()` authority on that Album — no new role matrix.
Setting or changing a cover to a Photo not yet in the Album first adds it
via the ordinary `AlbumPolicy::addPhoto()` path, then sets the cover,
atomically. **If the Photo currently serving as an Album's cover is
removed from that Album** (via the ordinary `AlbumPolicy::removePhoto()`
flow), the removal flow explicitly warns that doing so will also clear the
Album's cover, and — if the User proceeds — the `album_photos` row removal
and the Album's `cover_photo_id`/focal-position clear happen in one
transaction; the Album is left cover-less (§8's "no cover" state), never
silently defaulted to some other Photo. This ADR does not introduce any
rule that automatically selects a replacement or first-Photo cover; no
such rule exists in the accepted product design today.

### 14. Event RSVP: a concept independent of admission

ADR-0009's admission/participation semantics are unchanged and remain
authoritative: `EventAdmission` continues to be exactly what grants a
Guest or Contributor access to see/contribute to an Event, gated exactly
as `AlbumPolicy`/`EventAccess` already gate it. RSVP is a new, independent
piece of state answering a different question — "is this admitted person
actually planning to attend" — that never grants, revokes, or substitutes
for admission. **RSVP's population is exactly `EventAdmission`'s existing
population**: Owner/Administrator/Member have default, admission-free
access to a visible Event and are out of RSVP's scope entirely, matching
the Context's own framing of Event *access* as "Guest, Contributor" —
this ADR does not introduce Family-Space-wide RSVP tracking for roles
that were never gated by admission in the first place.

### 15. Event RSVP: schema

`event_admissions` gains `rsvp_status` (`string(20)`, `CHECK (rsvp_status
IN ('pending', 'going', 'not_attending'))`, default `'pending'`, set at
the same moment an `EventAdmission` row is created) and `rsvp_responded_at`
(nullable timestamp, set the first time `rsvp_status` moves away from
`'pending'`, updated again on any subsequent change). `maybe` is
explicitly not a V1 value — adding it later is a closed, reviewable
vocabulary change (mirroring ADR-0021 §7's schema-versioning discipline
for exactly this kind of controlled vocabulary growth), never a silent
addition. This directly reuses "the most repository-native existing
Event... admission relationship," per the Context's own instruction, and
was chosen over a separate `EventRsvp` object because `EventAdmission`'s
row population already matches RSVP's intended population exactly (§14) —
there is no strong reason for a separate object once that scoping is
made explicit, and §17-§18 show that reuse remains sound once revocation/
readmission is handled explicitly rather than assumed away.

### 16. Event RSVP: authorization

Setting `rsvp_status` is **self-service only**: the acting User must be
the `user_id` behind the `EventAdmission`'s own `family_space_membership_id`
— no manages-members override, and no organiser-entered response on
another User's behalf. This is a deliberate departure from this
project's more common "Owner/Administrator may act on behalf of others"
pattern, justified because RSVP is a personal attendance statement, and
because no existing architecture requires or implies an organiser-entered
RSVP capability (the Context's explicit instruction). Viewing the RSVP
groupings (§17) for an Event one already has view authority over requires
no new authorization — it is a read of already-visible `EventAdmission`
rows.

### 17. Event RSVP: effective population and UI-derived groupings

"Going", "Awaiting reply", and "Not attending" (the reference's three
groupings) are **derived at read time** from `rsvp_status` — `going`,
`pending`, and `not_attending` respectively — never a separately stored
grouping or duplicated state. **Corrected in this reconciliation: these
groupings include only currently-effective `EventAdmission` rows** —
`revoked_at IS NULL`, and not expired under whatever expiry rule ADR-0009
already applies to admissions generally. A revoked or expired admission's
`rsvp_status` is **retained** on its row (never destructively cleared)
for audit/history, consistent with this project's general preference for
retaining historical fact over deleting it, but is excluded from every
active RSVP grouping read — an Event's "Going" list can never include
someone whose access has since been revoked.

### 18. Event RSVP: revocation and readmission

**Revocation never mutates `rsvp_status`/`rsvp_responded_at`** — only
`EventAdmissionManager::revoke()`'s existing `revoked_at`/`revoked_by`
fields change; the prior RSVP answer is preserved as historical fact
(§17) precisely so it remains available if ever needed, without being
live-readable as a current answer. **Readmission after a genuine prior
revocation resets RSVP.** Concretely, since `EventAdmissionManager::admit()`
already reuses the same row via `updateOrCreate()`: when `admit()` is
called for an `EventAdmission` whose *current* `revoked_at` is non-null
(a genuine re-admission, distinct from an idempotent re-call on an
already-active admission, which must **not** reset an in-progress RSVP),
its update payload additionally sets `rsvp_status = 'pending'` and
`rsvp_responded_at = null` — a fresh admission period never silently
inherits a stale `going`/`not_attending` answer from before. An idempotent
`admit()` call on an admission that was never revoked leaves `rsvp_status`
completely untouched, since no new admission period has begun.

### 19. Historical or unlinked Person connected to an Event

Mirroring §6 exactly: a new, small, non-authorization-bearing
`event_people` table — `id, family_space_id, event_id, person_id,
added_by, created_at`, `UNIQUE(event_id, person_id)`, tenant-consistent
composite FKs, cascadeOnDelete on both. This is how a Person with no
`PersonAccountLink` (a deceased relative, a young child, anyone without a
User account) is recorded as connected to an Event for descriptive/
genealogical purposes, entirely independent of `EventAdmission`/RSVP —
such a Person **cannot** personally RSVP, precisely because RSVP requires
an actual linked `User` behind an `EventAdmission` row, and this
association never fabricates one. Add/remove authority mirrors
`FamilyEventPolicy`'s existing manage-authority — no new role matrix.
Being a genuine Person reference, it is integrated into `PersonMergeManager`
in the same stage it is introduced, alongside `album_people` (§20).

### 20. Album People and Event People: Person-merge integration

**`album_people`/`event_people` are genuine Person references and are
integrated into `PersonMergeManager`'s existing capture/reconcile/
guarded-reversal transaction in the same stage each table is introduced**
— the same standing obligation ADR-0006 §12 already imposed on every
prior Person-referencing table, and the exact pattern `saved_search_people`
(ADR-0013 §15/§18) and ADR-0021's own mention tables already establish:
for a merge `A → B`, every `album_people`/`event_people` row referencing
`A` repoints its `person_id` to `B`; if the same Album (or Event) already
has a row referencing `B`, a collision exists — `UNIQUE(album_id,
person_id)`/`UNIQUE(event_id, person_id)` would otherwise be violated —
and is resolved identically to every other collision-prone table in this
project: **repoint where no collision would result; where one would,
delete the now-redundant `A`-side row**, with the merge operation's own
provenance snapshot recording enough before/after state (the deleted
row's full field values, and which row survived) for guarded reversal to
restore both original rows exactly. Reversal respects every existing
guarded-reversal rule — it restores only what *this* merge operation
changed, and never resurrects a row that would conflict with valid state
created after the merge (the identical discipline `PersonMergeManager::restoreState()`
already applies elsewhere). The existing prohibition on an active chained
merge (`B → C` rejected while `A → B` remains active) is completely
unaffected — this ADR does not touch `PersonMergeManager::validatePair()`.
A merge attempt that is itself rejected (for any existing reason) leaves
every `album_people`/`event_people` row completely unchanged, since no
transaction was ever committed.

### 21. Event RSVP: notifications

A restrained, existing-architecture-consistent notification to the
Event's organiser (`FamilyEvent.created_by`, the same recipient
`Contribution`'s Event-Album branch already notifies) when an invitee's
`rsvp_status` changes away from `pending` — self-notification suppressed
(the general rule), and **no `family_activities` row is ever created for
an RSVP change** — RSVP is exactly the kind of lightweight, per-User state
change this project has repeatedly kept out of the shared family-activity
feed (matching Comments' and reactions' existing, identical exclusion).
This reuses a new `NotificationCategory::Attendance` case (§53) — not
`Contribution`, since "someone contributed a photo" and "someone answered
whether they're coming" are different enough concepts to warrant their
own user-configurable preference, consistent with this project's practice
of introducing a new category only when an existing one would be a
poor semantic fit (contrast ADR-0021 §31's opposite conclusion for Story
comments, where an existing category *was* a good fit). Delivery and
inbox-visibility authorization for this category are specified together,
completely, in §52-§54.

### 22. Collections: concept and boundary

A Collection is **a private, User-owned working set of Photo identities** —
explicitly not an Album, not an Event, not shared family archival content,
and not a new authorization boundary. It never widens what Photos its
owner can see; it only lets them save a personal, reorderable subset of
Photos they are *already* authorized to view. This is the smallest
possible new domain concept this ADR introduces: two tables, no new
authorization primitive, no interaction with `AlbumGrant`/`EventAdmission`/
`PhotoPerson`/mentions/Search/notifications/the homepage feed in any way.

### 23. Collection schema

```text
collections
    id              ulid, primary key
    family_space_id tenant scope, RLS
    owner_user_id   FK to users, cascadeOnDelete (a User's Collections
                    are personal working state, not archival content —
                    they are meant to disappear with the account that
                    made them, unlike every other tenant-owned table in
                    this project, which is a deliberate, named exception)
    name            string, required
    description     text, nullable — plain text; this is a short personal
                    label, not a candidate for ADR-0021's shared rich-text
                    infrastructure
    created_at / updated_at
```

One User may own many Collections; ownership never transfers.

### 24. CollectionPhoto schema and ordering

```text
collection_photos
    id              ulid, primary key
    family_space_id tenant scope, RLS
    collection_id   typed FK, tenant-consistent composite, cascadeOnDelete
    photo_id        typed FK, tenant-consistent composite, cascadeOnDelete
    position        unsigned integer
    created_at
```

`UNIQUE(collection_id, photo_id)` — a Photo appears at most once in a
given Collection (added twice is a no-op, not an error, matching
`AlbumPhoto`'s equivalent precedent for "add" idempotency at the service
layer). `UNIQUE(collection_id, position)`, mirroring `album_photos`'
exact ordering precedent, reordered via the same position-renumbering
approach already used there. Deleting a Collection never deletes any
`Photo` (cascadeOnDelete only removes the `collection_photos` join rows).
Removing a `CollectionPhoto` never affects `AlbumPhoto`, `PhotoPerson`, or
any other relationship — the two are entirely independent join tables
over the same `Photo`.

### 25. Collection authorization

Creating a Collection requires only ordinary, active Family Space
membership — the same `member()` check `AlbumPolicy`/`PhotoPolicy`
already use — with **no role restriction**: Contributor and Guest may
each keep their own personal Collections, since a Collection can never
contain more than what its owner already sees, and never shares anything
with anyone else. View/rename/delete/reorder/add/remove authority on a
Collection is **owner-only** — not even Owner/Administrator's usual
manages-members override applies, because a Collection is private
working state, not shared archival content, and this project's
manages-members override exists specifically for shared/archival data
stewardship (matching the reasoning in ADR-0015 for why Personal Export
is bounded to its own requester). Adding a Photo to a Collection is
additionally bounded by that Photo's own `PhotoPolicy::view()` at the
moment of adding — a Collection can never retroactively "remember" access
to a Photo the owner has since lost visibility of; a Photo already in a
Collection whose visibility the owner has since lost simply becomes
inaccessible through the Collection like anywhere else it would be
inaccessible, never specially preserved.

### 26. Collection bulk population from Album/Event

"Add photos to collection…" from an Album or Event context adds every
currently-authorized Photo identity from that context, deduplicated by
`photo_id` against the target Collection's existing membership (the
`UNIQUE(collection_id, photo_id)` constraint makes this a natural
upsert-or-skip, never an error). An Event's Photos continue to be derived
exactly as they are today — through its Albums and `primary_event_id`
Photos — **no direct `event_id` column is added to `collection_photos` or
any Photo-Event relationship is introduced merely to support this
flow**; Collections always resolve Event membership through the existing
Album/`primary_event_id` model.

### 27. Collection export: selection, packaging, and a database-enforced tenant-consistent reference

Collection export reuses ADR-0015's `FamilyExport` job/ownership/expiry
*lifecycle* — a new `FamilyExportScope::Collection` value, with
`FamilyExport` gaining a nullable `collection_id`.

**Corrected in this reconciliation: `collection_id` is a genuine,
database-enforced, tenant-consistent composite foreign key —
`(collection_id, family_space_id) REFERENCES collections(id,
family_space_id)`, backed by `collections`' own existing tenant-composite
uniqueness — never a single-column FK whose tenant consistency was only
service-checked at request time.** That earlier design was itself a
correction of the first draft's plain `cascadeOnDelete`, but went too far
the other way: a single-column FK proves nothing about tenancy at the
database level, the same class of gap already corrected for
`active_photo_version_id` (§40). **This FK uses `ON DELETE RESTRICT`,
never `SET NULL`** — for the structural reason established throughout
this ADR, an automatic multi-column `SET NULL` FK *action* would attempt
to null `family_exports.family_space_id`, a required column. This does
**not** prevent the service layer from explicitly clearing `collection_id`
on its own: Postgres's standard composite-FK matching (`MATCH SIMPLE`,
the default, used throughout this project) exempts a row from FK
enforcement entirely once *any* one of its composite referencing columns
is null — so an ordinary, explicit `UPDATE family_exports SET
collection_id = NULL WHERE ...` (leaving `family_space_id` completely
untouched, at its own correct, real value) is always valid and never
conflicts with this FK. The distinction that matters is between an
**automatic FK action** (which nulls every listed column at once, and is
what `SET NULL` as an FK action would do) and an **ordinary, explicit,
single-column `UPDATE`** issued by application code (which Postgres
freely allows under `MATCH SIMPLE`) — this ADR uses only the latter,
specified completely in §29.

Collection-specific selection and packaging behaviour, which
`FamilyArchiveBuilder` does not have today:

- **At export-execution time** (not merely at request time),
  `FamilyExportSelectionService` gains a `collection()` resolution path
  that resolves the Collection's *current* `collection_photos` membership
  and re-authorizes every one of those Photos against the requester's
  *current* `visibleTo()` boundary — omitting any Photo no longer viewable.
  Collection membership is never treated as retained authorization, even
  though it was already checked once at add-time (§25) — it is checked
  again, fully, at build time, because a Collection is long-lived personal
  state whose owner's own visibility can change after a Photo was added.
- **Collection export packages each Photo's current active presentation
  version by default** (§49), not its preserved original — a Collection
  is a curated, personal *view* of Photos, not an archival record, and
  does not inherit `FamilyArchiveBuilder`'s Full/Personal-export behaviour
  of including permitted originals merely because that behaviour exists
  elsewhere. Original-download authority (§50) remains completely
  separate and is never implicitly granted by a Collection export.

### 28. Collection export: crash-safe fence, scheduled reconciliation, and download-time revalidation

**Corrected in this reconciliation, again: correctness cannot depend
solely on either the worker's post-write recheck, or on Collection
deletion's own one-shot object-deletion attempt.** The immediately
preceding correction fixed the case where a worker crashes *before*
Collection deletion runs; it missed a second, equally real ordering: a
worker may write the object *after* Collection deletion has already made
its own one-shot deletion attempt (deletion ran first, found nothing to
delete, fenced the export) — if that worker then crashes before its own
post-write recheck, **no remaining process is scheduled to ever revisit
that object**, and it becomes a permanent orphan. Neither side's
immediate, one-shot attempt — deletion's or the worker's — can be the
sole guarantee; only a **durable, scheduled, idempotent reconciliation
process** that keeps checking until it succeeds actually closes this.

**The object's storage key is deterministic and known from the moment the
export is requested, not only once generation completes.** Confirmed
directly (`apps/api/app/Services/FamilyExportManager.php:62`):
`object_key` is computed and persisted on the `FamilyExport` row inside
`request()`, before generation ever begins — `FamilyStorageKey::for($familySpace,
"family-exports/{$export->id}.zip")`. This is what makes durable
reconciliation possible without depending on any process's completion:
**any process, at any later time, can attempt `storage->delete()`
against this key with no other information than the row itself** —
`storage->delete()` against a key that was never written, or already
removed, is a safe no-op, exactly as it already is for `expire()`'s own
unconditional call.

**A dedicated, durable, non-retryable fence — not an ordinary `Failed`
state — marks a deletion-cancelled export.** `FamilyExport` gains one
additional nullable column, `cancelled_at` (a timestamp, matching this
project's established `revoked_at`/`resolved_at` convention for "this
row's normal lifecycle was deliberately ended," rather than a bare
boolean). **This is a structured marker, never textual `failure_reason`
matching**: `FamilyExportManager::beginGeneration()`'s existing
retry-eligibility check (`in_array($export->state, [Pending, Processing,
Failed])`) gains one additional condition — `$export->cancelled_at ===
null` — so a deletion-fenced export can never re-enter generation, even
though an ordinary, transient `Failed` export remains retryable exactly
as it does today. Setting `cancelled_at` and transitioning `state` to
`Failed` (with `failure_reason` recording that its Collection was
deleted, for human-readable audit only, never for logic) happen together,
as the fence.

**Corrected in this reconciliation, a third time: "the object was absent
when checked" does not prove "this deterministic key can never be written
later" — a single successful-or-not-found delete attempt must never be
treated as terminal proof of cleanup while a worker could still
legitimately be in flight.** The previous correction's `object_deleted_at`,
set the instant any delete call merely completed without error, had
exactly this flaw: if Collection deletion's own immediate attempt (§29)
runs *before* a still-in-flight worker writes the object, it finds
nothing, marks `object_deleted_at`, and the export is never reconsidered
again — even though the worker then writes the object and crashes before
its own recheck, leaving a permanent, silently-excluded orphan. Storage
absence observed at one instant and *final* storage reconciliation — safe
only once generation is conclusively incapable of ever producing another
object — are different facts, and this ADR must not conflate them.

**`FamilyExport` gains two further nullable columns establishing a durable
generation-quiescence contract, reusing this project's existing job
execution guarantees rather than inventing a lease/token abstraction from
scratch:**

- **`generation_started_at`** — set by `beginGeneration()` at the same
  moment it transitions the row to `Processing` (an explicit column,
  never inferred from `updated_at`, which the `cancelled_at` fencing
  `UPDATE` also touches and would otherwise corrupt this signal).
- **`generation_finished_at`** — set whenever generation for the
  *current* attempt concludes for any reason: `markReady()`'s success
  path, `markFailed()`'s failure path (including the job's own `failed()`
  callback, `apps/api/app/Jobs/GenerateFamilyExport.php`), and the
  worker's own cancellation-observed self-cleanup path (§ below) all set
  it as part of concluding.

**Corrected in this reconciliation: `beginGeneration()` must atomically
clear `generation_finished_at` back to `null` every time it starts a new
attempt, in the same transaction as setting `generation_started_at` and
transitioning to `Processing` — never leaving a prior attempt's
completion timestamp in place for a retry to inherit.**
`FamilyExportManager::beginGeneration()`'s existing retry-eligibility
check already allows a `Failed` (non-cancelled) export to begin again; if
that retry's transition set only `generation_started_at` and left
`generation_finished_at` at whatever value the *previous, failed* attempt
recorded, a concurrent quiescence check during the retry would
incorrectly read that stale, non-null value as "the current attempt has
concluded" — while the retry's own worker may still be actively writing.
`generation_finished_at` names *only* the current attempt's own
conclusion; a value left over from an earlier attempt must never satisfy
it. The fix is exactly this one atomic reset, performed as part of the
same state transition `beginGeneration()` already makes — never left to a
later worker step to clear, and never weakening the existing `cancelled_at`
guard that continues to prevent a fenced export from beginning any
attempt, new or retried, at all.

**Generation is conclusively quiescent for a given export's *current*
attempt — no worker can still legitimately write its object — when
either**: `generation_started_at` is still `null` (no attempt was ever
dispatched), **or** `generation_finished_at` is non-null (this same,
current attempt has cooperatively concluded — guaranteed fresh, never
stale, precisely because `beginGeneration()` clears it atomically on
every new attempt, above), **or** the time since the *current*
`generation_started_at` already exceeds the *existing, unchanged*
`GenerateFamilyExport` job's own configured execution bound — confirmed
directly (`apps/api/app/Jobs/GenerateFamilyExport.php`): `$timeout = 900`
seconds per attempt, combined with its `WithoutOverlapping(...)->expireAfter(960)`
lock, together already give a hard, pre-existing upper bound on how long
any single execution of this job could possibly still be running,
uncooperative or not. **No new lease/token mechanism is introduced — this
reuses the job's own, already-configured timeout and overlapping-lock
values as the objective bound for the crash case**, exactly as the
Context requested preferring an existing queue/job execution contract
over inventing one.

**`storage_reconciled_at` (replacing the previous, too-eagerly-set
`object_deleted_at`) is defined narrowly: "final storage absence
confirmed after generation for this export became conclusively
impossible" — never merely "a delete request once returned success or
not-found."** It is set only when both are true in the same reconciliation
pass: generation is quiescent (above), **and** a `storage->delete(object_key)`
attempt made *at or after* that quiescence is confirmed completes without
error. An attempt made *before* quiescence is confirmed — including
Collection deletion's own immediate attempt at fencing time (§29) — may
still delete a real object if one already exists, which is good, prompt
cleanup, but **never sets `storage_reconciled_at` by itself**, since its
finding "absent" proves nothing about a worker that has not yet written.

**Scheduled reconciliation, extending this project's existing export-
cleanup infrastructure rather than introducing a separate one, is the
actual, durable safety net.** Confirmed directly: `app_due_family_exports()`
(`apps/api/database/migrations/2026_09_10_000000_create_family_exports.php:75-85`)
is the existing SQL function `DispatchDueFamilyExports`'s scheduled
command already calls to discover exports needing cleanup — today,
exactly `state = 'ready' AND expires_at <= now()`, each dispatched to the
existing `ExpireFamilyExport` job. This function's `WHERE` clause gains
one additional, independent branch: `cancelled_at IS NOT NULL AND
storage_reconciled_at IS NULL` — every fenced export not yet *terminally*
reconciled, found and re-found on every scheduled run until it is, using
exactly the same discovery/dispatch machinery, on exactly the same
schedule, as the existing Ready-expiry path (a second query branch inside
the same function and command, not a second command, job, or subsystem —
the existing Ready-expiry behaviour itself is completely unchanged). For
each discovered cancelled/fenced export, the dispatched job:

1. makes an idempotent `storage->delete(object_key)` attempt regardless
   (cheap, safe, and closes the common already-quiescent case
   immediately);
2. evaluates quiescence (above);
3. **if not yet quiescent, stops there** — leaves `storage_reconciled_at`
   `null` regardless of what step 1 found, so the row remains eligible
   and is re-examined on the next scheduled run rather than being
   silently dropped;
4. **if quiescent, and step 1's attempt (or a fresh confirmatory one)
   completed without error, sets `storage_reconciled_at`** — only now is
   cleanup considered terminal, since no future writer can possibly exist
   for this export any longer;
5. if quiescent but the delete attempt itself failed (a transient storage
   error), leaves `storage_reconciled_at` `null`, so the very next
   scheduled run retries it, with no additional retry/backoff machinery
   beyond the command's own existing periodic cadence.

This reconciliation needs nothing but the `FamilyExport` row itself —
`object_key`, `family_space_id`, `requested_by`, `cancelled_at`,
`generation_started_at`, `generation_finished_at` — never a join to
`collections`, so it works identically whether or not the Collection, or
even `family_exports.collection_id` itself (already cleared, §29), still
exists.

**The worker's own checks, and Collection deletion's own immediate
attempt, remain valuable — as optimizations that close the common case
promptly, never as the safety mechanism**: the worker still checks
`cancelled_at` before starting a build where practical, and again
immediately after `buildAndStore()` returns and before `markReady()` is
called — deleting the object itself, setting `generation_finished_at`,
and skipping `markReady()` if fenced in the meantime, exactly the
cooperative signal that lets scheduled reconciliation recognize
quiescence immediately rather than waiting out the job's full timeout
window; Collection deletion still makes its own immediate
`storage->delete()` attempt at fencing time (§29), succeeding in the
overwhelmingly common case where no worker is still mid-flight, though
this attempt alone never sets `storage_reconciled_at` (above). **Neither
is required for correctness**: if both are skipped, crash, or race
unfavourably, scheduled reconciliation still — once quiescence is
established, at the latest by the job's own existing timeout bound —
finds and cleans the object, and only then marks it terminally
reconciled. This is what makes the design genuinely crash-safe rather
than merely race-reduced, and what closes the exact race a prior
correction missed: an early absent-object observation can never
permanently suppress the reconciliation a still-in-flight worker's later
write would otherwise require.

**A previously-`Ready` Collection archive must not remain downloadable if
it now contains a Photo the requester can no longer view.** Rebuilding a
ZIP synchronously at download time is not feasible with existing
infrastructure, so the smallest safe policy reuses the exact checkpoint
`FamilyExportManager::authorizeDownload()` already provides — it already
re-validates `expires_at` at this moment (confirmed directly,
`apps/api/app/Services/FamilyExportManager.php:135-161`) — extended, for
Collection scope only, with a genuine content-equality re-check rather
than a mere metadata flag: `family_exports` gains one additional nullable
column, `selection_checksum`. **Corrected in this reconciliation: this
checksum is computed from the final set of Photo ids `FamilyArchiveBuilder`
actually packaged, never from the pre-build `FamilyExportSelection`
`beginGeneration()` resolved before building started.** Between those two
moments, the builder's own reconciliation/deduplication may legitimately
narrow the set further; `buildAndStore()`'s existing return value (already
the source of `markReady()`'s `photoCount` argument) additionally carries
the exact packaged Photo-id list (or an equivalent checksum computed from
it), and it is *this* post-build value — not the earlier selection — that
is persisted as `selection_checksum` when `markReady()` runs.
`authorizeDownload()`, for a Collection-scoped export, recomputes the
Collection's *current* authorized selection and its checksum the same way
and compares it against the stored, post-build value; on any mismatch, it
denies the download with the same "this export is not available" response
already used for an expired export, requiring the requester to request a
fresh export rather than serving stale, over-privileged ZIP contents.
Full/Personal exports are unaffected — they remain intentional,
un-revalidated archival snapshots, exactly as ADR-0015 already
established.

### 29. Collection export: deletion ordering and crash-safe reconciliation

**Deleting a Collection must not strand a stored export object, must not
rely on a blind database cascade to clean one up, and must not depend on
either a live worker or Collection deletion's own immediate attempt
having actually caught the object.** A raw FK action on
`family_exports.collection_id` would touch only the database row, never
the underlying object-storage blob, since a declarative FK action cannot
call out to storage. Collection deletion is a service-level operation
with a fixed ordering, in the same sequence for every associated
`FamilyExport` regardless of its state:

1. **Lock and fence.** Every associated export not already
   `cancelled_at`-fenced has `cancelled_at` set and its `state` moved to
   `Failed` (§28) — this happens uniformly for `Pending`, `Processing`,
   and `Ready` exports alike, before any object cleanup is attempted, so
   `beginGeneration()` can never race a not-yet-fenced export into
   starting fresh generation mid-deletion.
2. **Make one immediate, best-effort object-deletion attempt, for every
   fenced export.** Because `object_key` is deterministic and already
   known from request time (§28), deletion issues `storage->delete()`
   against it regardless of the export's state or whether a worker has
   written anything yet — a `Pending` or not-yet-written `Processing`
   export's delete attempt is a harmless no-op; a `Ready` or
   already-written `Processing` export's delete attempt genuinely removes
   the object. **This attempt never sets `storage_reconciled_at` by
   itself, and its "not found" result is never treated as proof of
   permanent cleanup** — a worker may still write the object *after* this
   exact moment and then crash before its own recheck, and only scheduled
   reconciliation (§28), gated on generation quiescence, may mark cleanup
   terminal. This step exists purely for prompt, best-effort cleanup in
   the common case, never as evidence by itself.
3. **Clear `collection_id` via an explicit, single-column `UPDATE`** on
   each fenced export — never an FK action, and never before step 1's
   fence is in place — valid under `MATCH SIMPLE` composite-FK semantics
   without touching `family_space_id` (§27).
4. **Retain every export row and its `object_key`, `family_space_id`,
   `requested_by`, `cancelled_at`, `failure_reason`,
   `generation_started_at`, `generation_finished_at`, and
   `storage_reconciled_at` as historical record, regardless of whether
   step 2's immediate attempt found anything** — nothing about the
   export's own metadata is deleted merely because its Collection is
   gone, and none of it depends on `collection_id` still being set. This
   is exactly what scheduled reconciliation (§28) needs to determine
   quiescence and find/clean this row later, independent of the
   Collection's own continued existence.
5. **Only once every associated export has been fenced (step 1), had an
   immediate object-deletion attempt made (step 2), and had
   `collection_id` cleared (step 3) is the Collection row itself
   deleted.** The composite FK's `RESTRICT` action (§27) is a database-
   level backstop for this ordering: if step 3's clearing were ever
   skipped by mistake, the database itself would refuse to delete a
   Collection any export still references, rather than silently
   succeeding and leaving a dangling reference. **Deletion never waits for
   `storage_reconciled_at` to be confirmed before proceeding to remove the
   Collection** — scheduled reconciliation (§28) is explicitly designed to
   work correctly whether or not the Collection row still exists, and its
   quiescence gating (§28) means terminal reconciliation may genuinely not
   happen until well after the Collection itself is already gone; this
   ordering never has to, and never does, block on storage.

This ordering means every state converges on the same deterministic,
eventually-consistent outcome — `Pending`/`Processing`-before-write leave
nothing to clean up; `Processing`-after-write and `Ready` are genuinely
cleaned, either immediately (step 2, or the worker's own check, §28) or,
failing both, by the next scheduled reconciliation run (§28) — and
retrying any step (a repeated fence, a repeated delete attempt, a
repeated clear, a repeated scheduled reconciliation pass) is always safe,
since each is idempotent against its own already-applied state.

### 30. Collections: stage hand-off

`FPA-P14-S09` owns the Collection domain model, its API, and Collection
membership (§22-§26) — a Collection export request contract/scope may
be introduced there, and UI may exist only to the extent its behaviour is
truthful about what is actually implemented at that point.
**`FPA-P14-S09` may not claim complete curated export semantics on its
own**: final active-version packaging (§27, which depends on §49's
export/download reconciliation), download-time revalidation, the
scheduled cancelled-export object-reconciliation mechanism (§28 — the
actual crash-safety guarantee, not merely the fence and immediate-attempt
pieces), and the current-vs-original export rules generally are owned by
`FPA-P14-S12`, which depends on `FPA-P14-S11`'s Photo-version work being
complete first (§ Implementation notes).

### 31. Love: concept and boundary

"Love" is a single, lightweight, positive reaction — the frozen
reference's explicit V1 interaction — supported on exactly four target
types: **Photo, Album, Event, Story**. **Person is explicitly excluded** —
loving a Person is not a product concept this reference introduces, and
this ADR does not add one. V1 supports **exactly one** reaction value,
`love`; this is not a generalized reaction system reopened for arbitrary
future vocabulary — if a genuine need for more reaction types ever
emerges, it is a deliberate, separate decision (§ Review triggers),
exactly as ADR-0021 treated Story reactions.

### 32. Love: schema

Love is one product concept implemented across two tables, for
continuity with what is already shipped, not out of inconsistency:

- **Photo** — Love reuses the **already-shipped, unchanged**
  `photo_reactions.reaction = 'love'` value (ADR-0008 §12). No new table,
  no new column, no data migration: a Photo already has Love today, and
  this ADR does not touch `photo_reactions`' existing four-value
  vocabulary, its `(photo_id, album_id)` Album-context scoping, or its
  authorization at all.
- **Album, Event, Story** — a new `reactions` table, following this
  project's standing typed-exactly-one-target discipline (the same
  pattern `stories`' subject columns and `family_activities`' subject
  columns already establish) rather than an unsafe generic polymorphic
  target:

```text
reactions
    id              ulid, primary key
    family_space_id tenant scope, RLS
    album_id        nullable, typed FK, tenant-consistent composite,
                    cascadeOnDelete
    event_id        nullable, typed FK, tenant-consistent composite,
                    cascadeOnDelete
    story_id        nullable, typed FK, tenant-consistent composite,
                    cascadeOnDelete
    user_id         FK to users, cascadeOnDelete
    reaction        string(10), CHECK (reaction = 'love') — a single-value
                    vocabulary column, not a boolean, so a future
                    additional value (were one ever accepted) is an
                    additive vocabulary change, exactly mirroring
                    `photo_reactions.reaction`'s own shape
    created_at
```

A `CHECK` constraint enforces **exactly one** of `album_id`/`event_id`/
`story_id` non-null (Photo is deliberately not a column here — it never
needs to be, since Photo Love already lives in `photo_reactions`).
`UNIQUE(album_id, event_id, story_id, user_id)` is impractical to express
directly across nullable columns in a single constraint the way this
project usually writes tenant-composite uniqueness; instead, three
partial unique indexes (`WHERE album_id IS NOT NULL` etc.), one per
target column plus `user_id`, give the identical "at most one Love per
User per target" guarantee `photo_reactions`' own `UNIQUE(photo_id,
album_id, user_id)` already provides for Photo.

### 33. Love: authorization

**Corrected in this reconciliation: Photo Love's existing authority is
more specific than `PhotoPolicy::view()`, and this ADR must describe it
accurately rather than silently widen it.** Confirmed directly against
`PhotoConversationController::react()`/`removeReaction()`/`authorizeInteraction()`/
`canInteract()`: adding or removing a Photo's Love today requires
`Gate::allows('interact', $photo)` **and**, for a Contributor specifically,
`Gate::allows('contribute', $album)` on the Album context the reaction is
made within — Photo Love is, and remains, an Album-context interaction
requiring `PhotoPolicy::interact()`'s existing contribution-aware
authority, exactly as it already works today. This ADR makes no change to
that authority and does not widen Photo Love to every Photo viewer.

For the three new targets, each uses its own existing, appropriate
authority: `AlbumPolicy::view()` for Album Love, `FamilyEventPolicy::view()`
for Event Love, and a Story's own subject-derived view authority (ADR-0021
§24) for Story Love — no new role matrix, and Love never widens access to
its target, exactly as ADR-0021 §24 already establishes for Story
mentions/comments. The frontend may present one coherent Love control
across all four targets; the backend authority behind each remains
exactly as specified per target, never unified into a single check. A
User may remove only their own Love; there is no manages-members override
to remove someone else's Love, consistent with Love being a personal
expression rather than moderatable content — mirroring how this project
already treats `PhotoReaction` ownership.

### 34. Love: reactor list and Person-link disclosure

A target's Love count is always available to anyone with view authority
over it. **A list of reactors may be shown, but a reactor's linked Person
must only be disclosed when the current viewer independently holds
authorization to see that specific Person** — reusing
`Gate::allows('view', $person)` (the same boundary ADR-0021 §17's mention
autocomplete, and its own review-round correction to mention rendering,
already established) — never merely because the reactor is a User the
viewer can otherwise identify. **This is a deliberately explicit
requirement, not a restatement for its own sake**: this project's most
recent review of ADR-0021's mention-rendering implementation found
exactly this class of gap (a resolved display value shown without
checking the current viewer's authorization for the underlying Person) —
Love's reactor list must not repeat it. A reactor with no
`PersonAccountLink`, or one the viewer isn't authorized to see, is shown
only by their User display name, never a Person link.

### 35. Love: notification aggregation

**Corrected in this reconciliation: Love cannot reuse `ContributionGroup`'s
methods verbatim.** `ContributionGroup`'s natural key
(`family_space_id, actor_user_id, upload_batch_id, album_id`) batches one
actor's simultaneous uploads — an actor-and-batch-centric dimension. Love
needs the opposite: grouping *several different actors'* Loves on the
*same target* within a short window into one notification. Reusing the
Phase 12 candidate/evaluation *architecture* — durable, idempotent
candidate creation, then a delayed job that evaluates the group's full,
current state — a new, small, genuinely different table carries this
target-centric identity.

**Corrected in this reconciliation: the target is typed, not a generic
`target_type`/`target_id` pair** — this ADR's own standing discipline
(§32's `reactions` table, `stories`' subject columns, `family_activities`'
subject columns) requires typed nullable columns with an exactly-one
`CHECK`, never an unvalidated generic reference, and this table is no
exception. Photo Love participates in this same aggregation table for its
notification-batching identity — a separate concern from where its
*reaction data* lives (unchanged, in `photo_reactions`, §32) — so all four
Love targets share one grouping shape:

```text
love_notification_groups
    id                ulid, primary key
    family_space_id   tenant scope, RLS
    photo_id          nullable, typed FK, tenant-consistent composite,
                      cascadeOnDelete
    album_id          nullable, typed FK, tenant-consistent composite,
                      cascadeOnDelete
    event_id          nullable, typed FK, tenant-consistent composite,
                      cascadeOnDelete
    story_id          nullable, typed FK, tenant-consistent composite,
                      cascadeOnDelete
    created_at
```

A `CHECK` constraint enforces exactly one of `photo_id`/`album_id`/
`event_id`/`story_id` non-null — no Person target, matching §31.
`cascadeOnDelete` on every target column is safe here (unlike the
Album-cover/active-version cases): a group row is purely a notification-
batching artifact with no primary-key or required-column collision risk,
so it simply, correctly disappears when its target does, exactly as
ADR-0021's own mention/comment tables already cascade from their owning
Story. Family Space teardown requires no separate entry: every target
table (`photos`, `albums`, `events`, `stories`) is already torn down
explicitly or by established cascade (ADR-0021 §36's confirmed pattern),
and this table's own actor rows (below) cascade from it in turn.

**Corrected in this reconciliation: `NotificationCandidate` records
recipients, not contributing actors, and cannot by itself answer "how
many distinct actors Loved this."** A small, normalized table tracks
exactly that:

```text
love_notification_group_actors
    id              ulid, primary key
    family_space_id tenant scope, RLS
    group_id        typed FK, tenant-consistent composite to
                    love_notification_groups(id, family_space_id),
                    cascadeOnDelete
    actor_user_id   FK to users, cascadeOnDelete
    created_at
```

`UNIQUE(group_id, actor_user_id)` — the mechanism that makes a repeated
Love from the same actor (including retries) a plain idempotent insert
rather than an inflated count: two Loves from the same actor on the same
target, within the same open group, are one row, one contributing actor.
**Self-Love never contributes to a notification delivered to the same
person**: if the acting User *is* the target's own content owner (the
notification's sole recipient, resolved per §21/§33's existing per-target
dispatch), no actor row is inserted for that occurrence at all — the
general self-suppression rule applied at the earliest possible point,
rather than filtered out later.

**Group identity, aggregation window, and finalization**: the first Love
on a target with no currently-open group creates one; a subsequent Love
on the same target — from a *different* actor, or a retry from the same
one — while the group's `NotificationCandidate` row(s) remain unevaluated
(`evaluated_at IS NULL` — the same terminal signal
`registerContribution()`'s own `$terminal` check already uses) joins the
same wave, inserting (or, for a retry, no-op-ing against) its own actor
row rather than creating a duplicate group. **If a User removes their
Love before the group is finalized, their actor row is removed with it**
— a not-yet-sent notification must reflect the group's true state at the
moment it actually sends, exactly why the delay exists in the first
place; a Love removed *after* finalization has no retroactive effect on
an already-sent notification, and simply does not touch a new group
unless a fresh Love later reopens one. Once a delayed job evaluates the
group, it counts the *current* distinct rows in
`love_notification_group_actors` — never a `NotificationCandidate` count
— and, recipients resolved exactly as §21/§33 already establish: **for
exactly one qualifying actor**, sends a single-actor message ("Sarah
loved your Story"); **for more than one**, a count-bearing message
("Sarah and 4 others loved your Story"); **for zero** (every contributing
actor removed their Love before finalization), sends nothing at all — a
valid, non-error outcome, not a failure. Any further Love on that target
after finalization begins a fresh group, with its own fresh actor rows.
This is the same *shape* of durable-candidate-then-delayed-finalization
pattern `Contribution` established, deliberately re-keyed and
actor-tracked rather than reused verbatim: idempotent candidate/actor
creation, self-suppression, safe retries, and — like every existing
category — no `family_activities` row of any kind (§36). Cleanup after a
group's finalization follows this project's existing notification
retention rules unchanged — no new retention policy is introduced for
`love_notification_groups`/`love_notification_group_actors` specifically.

### 36. Love: feed exclusion

No individual Love, and no batched Love notification, ever produces a
`family_activities` row. Love is explicitly "a lightweight interaction,
not primary family activity" — the same exclusion this project already
applies to Comments and to `PhotoReaction` today; this ADR does not
reopen that boundary, only extends the identical exclusion to the three
new Love targets.

### 37. Story reconciliation for production use

ADR-0021 remains fully authoritative and is not reopened. This section
only restates, for the avoidance of doubt as production UI work begins,
what the frontend must and must not do: use first-class Story routes
(never legacy Photo-bound Story navigation, which no longer exists after
ADR-0021's `FPA-P14-S04`); respect Story's exactly-one-typed-primary-
subject invariant (Person, Album, Event, or Photo — never more than one,
never a new fifth subject type); never require a Story title, always
using the centralised derived heading (first `heading_2`/`heading_3`, else
sensible opening text, per ADR-0021 §18); support typed @Person mentions
and Story comments exactly as ADR-0021 §12/§21 define them; and integrate
Story Love from this ADR's shared Love domain (§31-§36), which is
additive to, not a change of, ADR-0021's Story schema. The frontend must
not introduce frontend-only draft-state claims (such as an unpersisted
"Saved just now" indicator implying durable state that does not exist)
unless a real, separately-accepted draft domain is introduced — no such
domain exists today, and this ADR does not add one. Every other typed
link or mention a Story's body may contain remains a secondary
relationship, never a second primary subject.

### 38. Nondestructive Photo editor: core principle

**Original media is immutable — ADR-0007's founding invariant, unchanged.**
Ordinary editing never touches `MediaUpload.original_object_key`/
`original_sha256`, and never touches the canonical asset's own generation
pipeline; it creates new, derived **presentation** versions, exactly
parallel to how `MediaVariant` already generates derived presentation
sizes without ever touching the original or canonical asset.

### 39. Photo version schema

```text
photo_versions
    id              ulid, primary key
    family_space_id tenant scope, RLS
    photo_id        typed FK, tenant-consistent composite to
                    photos(id, family_space_id), cascadeOnDelete — this
                    direction is safe: photo_versions is the child row,
                    correctly disappearing when its Photo is ever
                    hard-deleted (Family Space teardown only)
    edit_recipe     jsonb — the complete, self-contained Fambam-owned
                    edit instructions for this version (§42), embedding
                    its own schema_version, following ADR-0021 §6-§7's
                    self-describing versioned-document convention exactly
    restore         jsonb, nullable — structured Restore provenance when
                    this version resulted from Restore (§43); null for an
                    ordinary edit
    derived_object_key  string — the rendered output in object storage,
                    generated from the canonical asset plus edit_recipe
    created_by      FK to users, nullable, nullOnDelete
    created_at
```

`photo_versions` gains one additional constraint beyond its own primary
key: `UNIQUE(id, photo_id, family_space_id)` — a superset unique
constraint over its already-unique `id` plus the two columns that
identify which Photo and Family Space it belongs to. This is what makes
database-enforced same-Photo, same-tenant integrity possible for
`photos.active_photo_version_id` (below), correcting the first draft's
overclaim that a single-column FK alone made cross-Photo/cross-tenant
assignment structurally impossible — it did not; only a transitive,
service-enforced guarantee existed. This project's stated preference is
database-enforced tenant consistency wherever a valid design exists, and
one does here.

### 40. Photo version: database-enforced same-Photo active-version invariant

**`photos` gains one nullable column, `active_photo_version_id`, whose
foreign key proves — at the database level — both that the referenced
`photo_versions` row belongs to *this same Photo* and, therefore,
necessarily to the same Family Space:**

```text
FOREIGN KEY (active_photo_version_id, id, family_space_id)
REFERENCES photo_versions (id, photo_id, family_space_id)
```

Reading this the way Postgres evaluates it: whenever
`active_photo_version_id` is non-null, there must exist a
`photo_versions` row whose `id` equals it **and** whose own `photo_id`
equals *this* `photos` row's `id` **and** whose `family_space_id` matches
too — a cross-Photo or cross-tenant assignment is rejected by the
database itself, not merely by application code that could be bypassed.
This is possible precisely because §39's `UNIQUE(id, photo_id,
family_space_id)` gives Postgres a matching unique target to reference in
that column order.

**This composite FK uses `ON DELETE RESTRICT`, not `SET NULL`** — the
same structural reason established throughout this ADR (§7, §12, §27):
`SET NULL` on a multi-column FK nulls *every* listed referencing column
at once, and this FK's referencing side includes `photos.id` (a primary
key) and `photos.family_space_id` (required) alongside the one column
that is actually optional. `RESTRICT` means Postgres will refuse to
delete a `photo_versions` row while any `photos` row still names it as
`active_photo_version_id` — forcing the deterministic lifecycle below,
rather than allowing an unsafe FK action to silently attempt (and fail)
an impossible null.

**Lifecycle, solved explicitly rather than left to database timing**:

- **Photo creation** — `active_photo_version_id` starts `null`; a Photo
  never needs an active version to exist, and the FK is trivially
  satisfied by a null value.
- **PhotoVersion creation** — always created with `photo_id`/
  `family_space_id` set from the already-existing Photo being edited;
  never created "unattached." No circularity: the version is always
  inserted *after* its Photo already exists, and `photos.active_photo_version_id`
  is only ever pointed at a version *after* that version's own row
  already exists — the two FKs (`photo_versions.photo_id → photos`,
  `photos.active_photo_version_id → photo_versions`) reference each other
  across the two tables, but never within one statement, so no deferred-
  constraint trick is required.
- **Activating a version** — an ordinary `UPDATE photos SET
  active_photo_version_id = ...`, which the database itself now verifies
  satisfies the same-Photo/same-tenant FK above; a cross-Photo assignment
  attempt is rejected by the database, never merely by application code
  that could be bypassed.
- **Replacing the active version** — a new edit (§44) creates a new row
  and then activates it via the same database-checked `UPDATE`; the two
  are never conflated into one unchecked write.
- **Deleting a `photo_versions` row while it is any Photo's active
  version** — rejected by `RESTRICT`. The application must first clear or
  replace `active_photo_version_id` (an explicit `UPDATE ... SET
  active_photo_version_id = NULL` or to a different version) and only
  then delete the now-unreferenced row. Deleting a non-active version is
  unaffected.
- **Photo soft deletion** — does not delete its `photo_versions` rows
  (mirroring §12's Photo-soft-delete-leaves-membership-rows-present
  precedent, here intentionally, since edit history should survive
  alongside the Photo it belongs to) and does not touch
  `active_photo_version_id` — a plain `UPDATE ... SET deleted_at = ...`
  never triggers any FK action.
- **Photo restoration** — does not automatically reactivate any version;
  since neither the Photo nor its versions were ever removed by a soft
  delete, `active_photo_version_id`'s value is simply still there,
  unchanged, and remains valid.
- **Photo hard deletion (Family Space teardown only)** — **explicitly
  ordered to avoid any ambiguity about `RESTRICT`-versus-cascade
  interaction**: teardown first sets `active_photo_version_id = NULL` for
  every Photo about to be hard-deleted, in the same transaction, *before*
  `Photo::withTrashed()->forceDelete()` runs; only then does
  `photo_versions.photo_id`'s `cascadeOnDelete` (§39) remove every version
  row along with its Photo, with nothing left referencing them via the
  `RESTRICT`-guarded column by that point. This is a small, explicit,
  deterministic addition to `FamilySpaceDeletionManager::completeTeardown()`,
  not a reliance on Postgres's cascade-versus-restrict ordering within a
  single statement.

### 41. Authorized PhotoVersion delivery

**Rendered `photo_versions` assets need their own authorized delivery
path — existing `MediaDeliveryManager` methods do not serve them.**
Confirmed directly: `MediaDeliveryManager::canonical()`/`variant()`/
`original()` each resolve and authorize strictly against a `MediaUpload`
(via `MediaUploadPolicy`), and a `photo_versions` row's `derived_object_key`
is not a `MediaVariant` and has no `MediaUpload` of its own to authorize
against. `FPA-P14-S11` adds a new delivery method resolving authority
through the **Photo**, not the upload — `Gate::authorize('view', $photo)`,
the same authority that already gates every other Photo-facing read — and
never bypassing Family-Space tenancy, matching every existing delivery
method's own tenant-scoped lookup discipline. This same authorized path
serves both a Restore preview asset (§48, before it is ever persisted)
and any already-persisted `photo_versions` row, so the editor and Restore
workflows share one delivery contract rather than two. `MediaDeliveryManager`'s
existing canonical/variant/original methods are completely unchanged;
this is a new, additive method, not a generalization of them.

### 42. Edit recipe: closed vocabulary

`edit_recipe` is a small, closed, versioned JSON document — never an
opaque blob and never raw pixel data:

```json
{
  "schema_version": 1,
  "crop": {"x": 0.0, "y": 0.0, "width": 1.0, "height": 1.0} | null,
  "rotate_degrees": 0,
  "flip_horizontal": false,
  "flip_vertical": false,
  "straighten_degrees": 0.0,
  "adjustments": {"brightness": 0, "contrast": 0, "saturation": 0, "warmth": 0},
  "filter": {"name": null, "intensity": 0}
}
```

`crop` coordinates are fractions (0-1) of the canonical asset, never
absolute pixels, so the recipe survives any future canonical
regeneration. `rotate_degrees` is one of `0`/`90`/`180`/`270`;
`straighten_degrees` is a small bounded fine-angle range (the "straighten"
control), independent of the coarse 90-degree `rotate_degrees`. Each
`adjustments` value and `filter.intensity` is bounded to a fixed numeric
range (exact bounds are an implementation-guide detail, not an
architectural one). `filter.name` is one of exactly the five approved V1
filters — `black_and_white`, `sepia`, `warm`, `cool`, `sharpen` — or
`null`; this is a closed vocabulary, the same discipline ADR-0021 §6
applies to its own document schema, not an open string. **Whether this
specific version resulted from Restore, and how, is no longer a single
boolean here** — it is `photo_versions.restore`'s own structured
provenance, specified completely in §43.

### 43. Restore provenance: structured component

**Corrected in this reconciliation: `restore_applied: true` alone cannot
describe, let alone reproduce, what Restore actually did.**
`photo_versions.restore` (§39) is a small, bounded, closed-vocabulary JSON
document — never an opaque blob, and never editor-library-specific state
— present only when the version resulted from Restore:

```json
{
  "schema_version": 1,
  "algorithm_version": "restore-v1",
  "processing_mode": "conservative",
  "parameters": {
    "white_balance_shift": 0.0,
    "exposure_adjustment": 0.0,
    "saturation_recovery": 0.0,
    "denoise_strength": 0.0,
    "sharpen_strength": 0.0
  },
  "outcome": "applied"
}
```

`algorithm_version` and `processing_mode` are enough to know *which*
deterministic process produced this result and how it was configured to
run — sufficient to understand or re-run it later, without storing raw
implementation internals or an editor library's own state. `parameters`
is a small, bounded, closed set of the deterministic values Restore's own
analysis chose (not free-form) — an implementation-guide detail governs
the exact parameter set and bounds, matching `edit_recipe`'s own
adjustment-range treatment (§42). `outcome` is `applied` or
`no_improvement_found` (§48) — the second value is how a
no-meaningful-improvement result is represented when it must be recorded
at all (a `no_improvement_found` outcome never becomes a `photo_versions`
row itself, §48; this vocabulary exists for any surface that needs to log
or report the attempt).

### 44. Edit recipe: source of truth and re-edit rule

**Every edit recipe is complete and self-contained, applied fresh against
the canonical asset — never against a previous derived version.**
Re-editing an already-edited Photo starts from the canonical asset and
the *current* full recipe (loaded, adjusted, re-rendered), producing a new
`photo_versions` row; it never recompresses or re-derives from a prior
`derived_object_key`. This is the direct architectural answer to "re-edit
should derive from the canonical source/edit recipe rather than
repeatedly recompressing previously edited JPEGs" — generational quality
loss is structurally impossible because there is never more than one
generation between canonical and any derived version.

### 45. Reset/revert semantics

Setting `photos.active_photo_version_id` back to `null` reverts a Photo to
its canonical, unedited presentation immediately and losslessly — the
canonical asset was never modified, so "revert" is a plain pointer change,
not a regeneration. Every prior `photo_versions` row remains retained
(§39) and selectable again as the active version, without recomputation,
unless its own `derived_object_key` has since been purged by an
unrelated storage-lifecycle policy (out of this ADR's scope; no such
policy is introduced here).

### 46. Editor architecture boundary

Persisted domain state is `edit_recipe`/`restore` (§42-§43) and
`photo_versions`/`active_photo_version_id` (§39) — never any Ascentspark/
react-image-editor-specific representation. The browser editor library is
a replaceable implementation detail behind a Fambam-owned adapter that
translates the library's own UI/gesture state into and out of the closed
`edit_recipe` vocabulary; swapping the editor library requires no domain
or migration change, only a new adapter. Rendering `edit_recipe` into
`derived_object_key` is a Fambam-owned server-side (or
server-orchestrated) rendering step, not something the browser library's
own export format dictates.

### 47. Restore: scope

Restore is one more operation available within the same nondestructive
version model (§38-§46) — not a separate domain. It targets faded,
discoloured, or otherwise degraded scans and produces a
**conservative, deterministic** improvement using only: colour-cast
correction/white balance, faded-colour recovery, exposure/levels,
contrast/local contrast, saturation/vibrance correction, highlight/shadow
recovery, denoise, and sharpen/deblur within conservative limits. It
**never** performs generative face reconstruction, invention of missing
detail, tear reconstruction, object generation or removal, hallucinated
texture, or automatic colourisation — Restore only recovers information
already latent in the original pixels, never fabricates new information.

### 48. Restore: preview, activation and failure lifecycle

Restore is invoked as an explicit, standalone action with a fully
specified lifecycle:

1. **Request Restore** — the User initiates the operation on a Photo they
   hold edit authority over (the same authority any other edit requires).
2. **Generate a temporary preview** — a candidate `edit_recipe`/`restore`
   pair (§42-§43) and its rendered output are computed, but **no**
   `photo_versions` row is created and `active_photo_version_id` is left
   untouched.
3. **Preview asset authorization** — the preview's rendered output is
   served through the same authorized delivery contract as any other
   `photo_versions` asset (§41), gated by the same Photo-level `view`
   authority, never a separate, less-guarded preview endpoint.
4. **Preview expiry/cleanup** — an unaccepted preview is a temporary
   artifact, not a durable one; it is not retained once the User navigates
   away or after a bounded expiry window (an implementation-guide detail,
   not an architectural one), and never becomes discoverable as a
   `photo_versions` row merely by having been generated.
5. **Keep original** — the preview is discarded; nothing is persisted;
   the immutable original and the current active version (if any) are
   both completely untouched.
6. **Apply restoration** — the preview's recipe/provenance becomes a real
   `photo_versions` row (`restore.outcome = "applied"`) and the new active
   version, via the exact same checked activation path (§40) any other
   edit uses — Restore is not a special case of the activation invariant.
7. **Failure** — if preview generation or application fails outright, no
   `photo_versions` row is created and no active version changes; the
   Photo is left exactly as it was before the attempt.
8. **No meaningful improvement** — a valid, expected, non-error outcome
   (`restore.outcome = "no_improvement_found"` if logged at all, §43): the
   operation reports this explicitly rather than persisting a worse or
   no-op derivative; nothing is applied unless the User has a genuinely
   improved preview to accept.
9. **Retry safety/idempotency** — requesting Restore again (whether after
   "Keep original," a failure, or a "no improvement" outcome) always
   re-runs the same deterministic analysis fresh against the canonical
   asset and never depends on, or is corrupted by, any prior attempt's
   discarded preview state.

### 49. Export/download semantics: active version by default

Every ordinary, user-facing Photo download/export — a single Photo
download, an Album Photo export, an Event Photo export, and a Collection
export (§27) — resolves to each Photo's **current active presentation
version** (`active_photo_version_id`'s `derived_object_key`, or the
canonical asset when null) by default. Crop, rotate, filter, adjustment,
and Restore results are therefore preserved in every normal curated
export, matching what the User currently sees on screen.

### 50. Export/download semantics: explicit original unchanged

`Download original` continues to resolve exclusively to the immutable
original (`MediaUpload.original_object_key`), gated by the existing,
unchanged `MediaUploadPolicy::downloadOriginal()` authority (ADR-0007
§16, ADR-0015 §6) — this ADR adds no new authorization path to the
original and does not weaken the existing one. Ordinary editing can never
destroy, overwrite, or bypass access to the archival original; the two
are, and remain, structurally distinct assets under distinct authority.

### 51. Export/download semantics: archival Family/portability export

The Full Family Space export and Personal Export (ADR-0015) remain
archival exports and are not repurposed as "current view" exports. Each
continues to preserve the original wherever `downloadOriginal()`/
`original_included` already permits it (unchanged), and additionally
preserves enough version/provenance metadata — the Photo's current
`active_photo_version_id`'s `edit_recipe`/`restore` and `derived_object_key`
reference, where one exists — for the archive to convey the relationship
between the preserved original and any active presentation derivative,
without requiring the archive to embed every historical `photo_versions`
row. `FamilyArchiveBuilder`'s existing `original_included` field and
selection logic are unchanged; this is an additive field on the same
per-Photo export row.

### 52. Authorization and RLS: summary

Every new table this ADR introduces (`album_tag`, `album_people`,
`event_people`, `collections`, `collection_photos`, `reactions`,
`photo_versions`, `love_notification_groups`, `love_notification_group_actors`)
is tenant-scoped (`family_space_id`, `FORCE ROW LEVEL SECURITY`, a
tenant-isolation policy matching every other table in this project), uses
ULIDs consistently, and every cross-entity foreign key is a
tenant-consistent composite FK. **Corrected in this reconciliation:
`family_exports.collection_id` (§27) is no longer a single-column
exception** — it is now a genuine, database-enforced, tenant-consistent
composite FK (`(collection_id, family_space_id) → collections(id,
family_space_id)`, `RESTRICT`), the same correction already applied to
`active_photo_version_id` (§39-§40). **Exactly one deliberate,
explicitly-justified exception remains**: the Album-cover **membership**
invariant (§12, distinct from `cover_photo_id`'s own FK, which *is* a
proper tenant-consistent composite FK using `RESTRICT`) is enforced at
the service layer rather than declaratively, because no composite FK
shape can express "belongs to this specific Album" without requiring an
FK action that would null a primary key. No table introduced here
consults, is consulted by, or alters any existing authorization decision
except where explicitly stated (§12's cover-must-be-a-member-Photo
invariant, §33's per-target Love authority, §40's database-enforced
same-Photo active-version invariant, §49's active-version-by-default
export resolution) — every other new table (`album_tag`, `album_people`,
`event_people`, `collections`, `collection_photos`, `love_notification_groups`)
is either descriptive-only, privately-scoped to its owner, or a pure
notification-batching key, never an authorization primitive.

### 53. Notification categories: summary

`NotificationCategory` gains two new cases: `Love` (`'love'`) and
`Attendance` (`'attendance'`), each added to `preferenceCases()` alongside
the existing four (matching `Comment`/`Contribution`/`Story`/`Identity`'s
own user-configurable treatment, not `Export`'s always-on treatment).
`NotificationManager::typedSubject()`/`recipients()`/`authorized()`/
`url()`/`message()` each gain the corresponding new `match` branches,
following exactly the existing per-category dispatch shape.

### 54. Event notification authorization: delivery and inbox visibility

**Corrected in this reconciliation: closing only `NotificationManager::authorized()`'s
missing `event_id` branch is insufficient — `NotificationController::visible()`
(`apps/api/app/Http/Controllers/NotificationController.php:66-82`) has the
exact same gap, confirmed directly, and both must close together.** An
Event-linked notification (Attendance, and Love on an Event target) must
satisfy both: `NotificationManager::authorized()` gates whether it is
*delivered* at all, re-checked immediately before send exactly as every
existing branch already is; `NotificationController::visible()` gates
whether an already-created `FamilyNotification` row remains *visible in
the inbox* on every read, exactly as its existing `photo_id`/`album_id`/
`story_id` branches already do. Both gain an identical new branch —
`Gate::forUser($user)->allows('view', $event)` — so an Event-linked
notification is deliverable only while the recipient is currently
authorized, and disappears from the inbox the moment that authorization
is withdrawn (an Event access revocation, for instance), never lingering
as a stale, unauthorized-but-still-visible row. `FPA-P14-S08` (Attendance)
and `FPA-P14-S10` (Event Love) both exercise this identical boundary —
neither introduces its own.

### 55. Teardown and tenancy for new tables

`album_tag`, `album_people`, `event_people`, `collections`,
`collection_photos`, `reactions`, `photo_versions`, and
`love_notification_groups` all cascade from an already-torn-down parent
(`albums`, `events`, `users`, `photos`) exactly as
`photo_stories`/`photo_comments`/ADR-0021's own tables already do —
confirmed as the established, sufficient pattern in ADR-0021 §36 and
directly against `FamilySpaceDeletionManager::completeTeardown()`'s
existing ordering (Album rows are removed before `Photo::withTrashed()->forceDelete()`
runs, confirmed directly — the exact ordering §7/§12's `cover_photo_id`
`RESTRICT` strategy relies on). No new explicit per-table
`FamilySpaceDeletionManager` entry is required for any of them.
`FamilyExport`'s association with a Collection is a `SET NULL` reference
(§27), not a cascade, so that a Collection's deletion never needs to
race a database cascade against the service-level storage-cleanup
sequence (§29) it must actually run first.

### 56. Migration and backfill accuracy

**Corrected in this reconciliation: not every additive migration in this
ADR is backfill-free, and the first draft's literal "no backfill is
required" was inaccurate for one of them.** Schema additions requiring no
transformation of *historical content* — every new table, and every new
nullable column with no default applied to existing rows (`albums.starts_on`/
`ends_on`/`location`/`cover_photo_id`/`cover_focal_x`/`cover_focal_y`,
`photos.active_photo_version_id`, `family_exports.collection_id`/
`selection_checksum`) — are genuinely backfill-free: existing rows are
simply left `null`, with no behavioural change. **`event_admissions.rsvp_status`
is different**: giving every existing row a `pending` default is a real
data-initialization step, not merely an additive column — it writes a
concrete, semantically meaningful value (`pending`, meaning "no response
recorded") onto every pre-existing `EventAdmission` row, changing what a
read of that row returns from "column does not exist" to "explicitly
pending." This is safe and intentional (no existing RSVP concept existed
for those rows before, so `pending` is the only honest value), but it is
a backfill/data-initialization step and is described as one, not omitted.

### 57. Non-goals restated

This ADR does not: add Collection comments, reactions, tags, People
associations, or sharing/collaboration of any kind (§22); add a `maybe`
RSVP value or organiser-entered RSVP-on-behalf (§15-§16); add reactions on
Person, or any reaction value beyond `love` (§31); add drawing, text
overlays, stickers, layers, background removal, generative editing, or
cutout tools to the Photo editor (§38); perform generative face
reconstruction, tear reconstruction, object generation/removal,
hallucinated texture, or automatic colourisation in Restore (§47); weaken
original-download authority or repurpose archival export as a "current
view" export (§50-§51); or introduce Platform Administration, Stripe,
Family Tree, or Neighbourhoods scope into Phase 14.

## Alternatives considered

- **A generic polymorphic `subject_type`/`subject_id` pair for Love and
  for every descriptive association** — rejected, consistent with every
  prior ADR in this project; typed nullable columns plus an exactly-one
  `CHECK` are used throughout instead.
- **Reusing/mutating `photo_reactions` into one generic four-target
  reactions table** — rejected: `photo_reactions.album_id` already means
  "the Album context a Photo reaction occurred within," a different
  concept from "reacting to the Album itself"; overloading that column
  for a new meaning would be a genuine design smell. Photo Love keeps its
  already-shipped table untouched; Album/Event/Story Love get one small,
  clearly-scoped new table instead (§32).
- **A geography/Place subsystem for Album location** — rejected: no such
  abstraction exists anywhere in this codebase, and Event's own `location`
  field is already the accepted "smallest representation" precedent (§4).
- **A separate `EventRsvp` object, independent of `EventAdmission`** —
  considered and rejected once RSVP's population was scoped explicitly to
  match `EventAdmission`'s own (§14): with that scoping, and with
  revocation/readmission handled explicitly (§17-§18), there is no
  remaining reason for a second object.
- **Organiser-entered RSVP on behalf of an invitee** — rejected: no
  existing architecture requires it, and it would let one User assert
  another's personal attendance intent (§16).
- **Silently carrying a prior RSVP answer across a revoke/readmit cycle**
  — rejected: it would misrepresent a fresh admission period as already
  answered; readmission explicitly resets `rsvp_status` (§18).
- **A composite FK enforcing the Album-cover membership invariant or the
  same-Photo active-version invariant declaratively** — considered and
  rejected in both cases for the identical structural reason: the natural
  composite shape would require an `ON DELETE`/`ON UPDATE` action nulling
  a primary key or a required tenant column, which Postgres cannot do;
  locked, fully-tested service-level enforcement is used instead (§12,
  §40).
- **Collections implemented as a special kind of Album** — rejected:
  Albums are shared, authorization-relevant, archival family content;
  Collections are explicitly private, personal, and non-archival, and
  conflating the two would either weaken Album's authorization model or
  force every Collection to carry Album-shaped ceremony it doesn't need
  (§22).
- **Reusing `FamilyArchiveBuilder` unchanged for Collection export** —
  rejected: it assumes archival-snapshot semantics (permitted originals,
  no re-validation after build) that are wrong for a live, frequently-
  mutated personal Collection; Collection-specific selection, active-
  version-by-default packaging, and download-time revalidation are
  specified instead (§27-§28).
- **A second, wholly separate Collection-specific export pipeline** —
  rejected: ADR-0015's `FamilyExport` job/ownership/expiry lifecycle
  already generalizes cleanly via a new scope value; only its selection
  and packaging behaviour needed to be Collection-aware, not its entire
  infrastructure (§27).
- **Coupling persisted Photo-edit state to a specific browser editor
  library's own format** — rejected: it would make the domain model
  hostage to a third-party library's versioning and export shape; a
  Fambam-owned closed `edit_recipe` vocabulary behind a swappable adapter
  is used instead (§42, §46).
- **Storing edited pixels only, with no recipe** — rejected: it would
  make "reset to original," "re-edit without generational loss," and
  Restore's before/after preview all impossible or lossy; the recipe is
  the source of truth, the rendered asset a regenerable projection of it
  (§39, §44).
- **Chaining edits on top of previous derived versions** — rejected:
  this is exactly the "repeatedly recompressing previously edited JPEGs"
  outcome the Context explicitly warns against; every version is derived
  fresh from canonical plus the complete current recipe (§44).
- **`restore_applied: true` as the entirety of Restore's provenance** —
  rejected: it cannot describe or reproduce what was actually done;
  structured, bounded provenance is stored instead (§43).
- **Automatically colourising, reconstructing, or otherwise inventing
  content during Restore** — rejected outright as a V1 capability; Restore
  is conservative and deterministic only, matching the explicit
  instruction not to fabricate missing historical detail (§47).
- **Defaulting an Album's cover to its first Photo when none is chosen**
  — rejected: no accepted product rule requires this, and silently
  picking one would contradict "an Album may be created with no cover"
  as a genuine, stable, intentional state (§13).
- **Disclosing a Love reactor's linked Person unconditionally** —
  rejected, directly informed by the exact authorization gap this
  project's own most recent ADR-0021 implementation review found in
  mention rendering: display of a resolved Person must always be gated by
  the current viewer's own authorization, never assumed from the
  reactor's own visibility (§34).
- **Widening Photo Love to require only `PhotoPolicy::view()`** —
  rejected: it would silently loosen an existing, more specific
  authority (`interact()` plus Contributor's `contribute()` check) that
  this ADR has no mandate to change (§33).
- **A boolean pending-cover flag on `MediaUpload`, relying on arrival
  order to decide which cover choice wins** — rejected: a timing/order
  heuristic can let a stale upload overwrite a deliberately newer choice;
  one authoritative, explicitly-compared `current_cover_intent_id` on the
  Album is used instead (§9-§10).
- **Treating contribution authority as sufficient to finalize a cover** —
  rejected: an actor able to contribute a Photo is not necessarily still
  able to manage the Album's cover by the time a delayed upload resolves;
  cover finalization re-checks `AlbumPolicy::update()` explicitly (§10).
- **Treating contribution authority as sufficient to *set or replace* the
  cover intent in the first place** — rejected in this reconciliation:
  the ordinary Album upload endpoint authorizes contribution, not Album
  management, confirmed directly; without a separate check, a Contributor
  able to contribute a Photo could overwrite an Owner or Administrator's
  already-current cover intent, even though their own finalization would
  later fail for lack of update authority. Setting, replacing, or
  intentionally clearing `current_cover_intent_id` — including choosing
  an existing Photo as cover — now requires `AlbumPolicy::update()`
  explicitly, separate from and in addition to the ordinary upload's own
  contribution-authority check (§9).
- **Assuming a `Processing` Collection export has no storage object yet**
  — rejected: `FamilyArchiveBuilder::buildAndStore()` writes the object
  before `markReady()` runs, confirmed directly (§28).
- **Relying solely on a live worker's post-write recheck to delete an
  orphan object** — rejected: a worker may crash between writing the
  object and reaching that check, leaving a permanent orphan with no
  process left to clean it up (§28).
- **Relying solely on Collection deletion's own immediate, one-shot
  object-deletion attempt** — rejected in a further pass: a worker may
  still write the object *after* that attempt already ran and then crash
  before its own recheck, leaving nothing scheduled to ever revisit it;
  durable, scheduled reconciliation — extending the existing
  `app_due_family_exports()`/`DispatchDueFamilyExports` export-cleanup
  infrastructure rather than either one-shot attempt alone — is the
  actual correctness guarantee, with both one-shot attempts kept only as
  promptness optimizations (§28).
- **Marking scheduled reconciliation terminal (`object_deleted_at`) the
  moment any single delete attempt returns success-or-not-found** — the
  previous reconciliation's own design, rejected in this final pass: "the
  object was absent when checked" does not prove "this deterministic key
  can never be written later," so an early absent-object observation
  could permanently exclude an export whose worker has not yet written.
  Terminal reconciliation (`storage_reconciled_at`) is now gated on
  generation quiescence first, never on a delete outcome alone (§28).
- **A new lease/token abstraction to detect a stale or dead worker** —
  rejected as unnecessary: `GenerateFamilyExport`'s existing `$timeout`
  and `WithoutOverlapping(...)->expireAfter(...)` configuration already
  gives a durable, repository-native upper bound on how long any single
  execution could still legitimately be running; reusing it, alongside a
  simple cooperative `generation_finished_at` signal, is smaller and more
  consistent than inventing a parallel lease mechanism (§28).
- **Leaving a prior attempt's `generation_finished_at` in place when
  `beginGeneration()` starts a retry** — rejected in a further pass: a
  quiescence check run while the retry's own worker is still writing
  would incorrectly read that stale, leftover timestamp as proof the
  *current* attempt had already concluded. `beginGeneration()` now clears
  `generation_finished_at` to `null` atomically, in the same transition
  that sets `generation_started_at` for the new attempt, so it can never
  describe anything but the current attempt (§28).
- **Marking a deletion-fenced export merely `Failed`, relying on
  `failure_reason` text to distinguish it from an ordinary retryable
  failure** — rejected: a structured, dedicated `cancelled_at` marker is
  used instead, so `beginGeneration()` can reject it deterministically
  without textual matching, while an ordinary `Failed` export remains
  retryable exactly as today (§28).
- **A single-column `collection_id` FK with only service-enforced tenant
  consistency** — the previous reconciliation's choice, rejected in this
  pass as insufficient: it proved nothing about tenancy at the database
  level, the same class of gap already corrected for
  `active_photo_version_id`. A composite `SET NULL` FK is still rejected
  for the same structural reason established throughout this ADR — it
  would attempt to null `family_exports.family_space_id`, a required
  column — but a composite FK using `RESTRICT`, with the service layer
  explicitly clearing `collection_id` via an ordinary single-column
  `UPDATE` (valid under `MATCH SIMPLE` semantics, never an FK action), is
  used instead, giving genuine database-enforced tenant consistency
  (§27, §29).
- **Computing `selection_checksum` from the pre-build selection rather
  than what was actually packaged** — rejected: the builder's own
  reconciliation/deduplication can legitimately narrow the set further,
  and a checksum from an earlier moment would not catch that (§28).
- **An unvalidated generic `target_type`/`target_id` pair for
  `love_notification_groups`** — rejected: it contradicts this ADR's own
  standing typed-FK discipline for exactly this shape of problem; typed,
  tenant-consistent, cascading columns are used instead (§35).
- **Inferring distinct Love actor counts from `NotificationCandidate`
  recipient rows** — rejected: that table records recipients, not
  contributing actors, and cannot answer "how many distinct people loved
  this"; a small, normalized actor-membership table is used instead
  (§35).
- **A single-column `active_photo_version_id` FK described as making
  cross-Photo/cross-tenant assignment structurally impossible** —
  rejected as an overclaim: only a transitive, service-enforced guarantee
  existed. A database-enforced composite FK proving same-Photo and
  same-tenant integrity together is used instead, since this project
  prefers database-enforced tenant consistency wherever a valid design
  exists (§39-§40).
- **Landing `album_people` and `event_people`'s Person-merge integration
  together in one stage** — rejected: `event_people` does not exist until
  `FPA-P14-S08`, so `FPA-P14-S07` cannot depend on it; each table's merge
  integration lands atomically in the stage that introduces it (§
  Implementation notes).

## Consequences

### Positive

- The frozen reference's Album, Event RSVP, Collections, Love, Story, and
  Photo-editing surfaces all now have a concrete, minimal, reviewable
  domain/API contract to implement against, closing the gap identified by
  comparing the reference against the live domain model before broad
  production UI work begins.
- Every new capability reuses an already-proven pattern from this
  project's own history — typed-exactly-one-target `CHECK`s, the existing
  Tag vocabulary, the existing reaction table for Photo, the existing
  notification dispatch/batching architecture, the existing export
  lifecycle, the existing self-describing versioned-document convention,
  the existing collision-aware Person-merge repoint pattern — so very
  little of this ADR is genuinely novel architecture.
- The nondestructive editor's recipe-plus-canonical model means Restore,
  ordinary edits, resets, and re-edits are all the same small mechanism,
  never a special case each, and never accumulate generational quality
  loss.
- Finding and fixing the same structural trap twice independently
  (composite `SET NULL` nulling a required column, for both the Album
  cover and the active Photo version) before implementation, and
  recognizing it as one repeated pattern rather than two unrelated bugs,
  avoids two separate incident-shaped discoveries later.
- Fixing the reactor-Person-disclosure and Photo-Love-authority
  boundaries *before* implementation begins avoids repeating the exact
  classes of gap this project's own review process has already had to
  catch once, after the fact, elsewhere.

### Negative

- Eight new tables (one more than the first draft, `love_notification_groups`),
  two new notification categories, and a new export scope value is a
  substantial single-ADR surface, larger than most prior ADRs in this
  project — accepted because the frozen reference already commits the
  product to all of these capabilities together, and splitting them into
  several smaller ADRs would fragment closely related staging decisions
  without reducing the actual work.
- `photo_versions` plus `reactions` plus the Album/Event association
  tables meaningfully increase Photo/Album/Event read-path complexity
  (resolving an active version, a cover, tags, People, and Love counts
  all add joins) — accepted as the necessary cost of the reference's
  richer card/detail presentation, and bounded by keeping every new join
  optional/nullable rather than required.
- The Album-cover and active-Photo-version membership invariants are both
  enforced at the service layer rather than the database, because a
  declarative composite constraint is structurally unavailable for either
  — this shifts correctness onto locked application code and its test
  suite rather than the schema itself, and both are explicitly listed as
  risks below.
- Collection export requires genuinely new selection/packaging/
  revalidation logic rather than a pure reuse of `FamilyArchiveBuilder`,
  which is more implementation work than the first draft assumed —
  accepted because a Collection's live, frequently-mutated nature makes
  archival-snapshot semantics actually unsafe, not merely inconvenient.

### Risks

- If `reactions`' three partial unique indexes are ever implemented as a
  single non-partial `UNIQUE(album_id, event_id, story_id, user_id)`
  instead, Postgres would allow multiple rows differing only in which
  columns are null for the same User — worth a direct constraint test per
  target type.
- If the Album-cover membership invariant (§12) is ever enforced only at
  the point of setting a cover, and not re-checked on Photo soft deletion
  or every removal path, a cover could point at a Photo no longer validly
  in the Album — worth a direct test covering every listed lifecycle path.
- If the `photo_versions` `UNIQUE(id, photo_id, family_space_id)`
  constraint or the composite FK referencing it (§40) is ever migrated
  incorrectly (wrong column order, or omitted entirely in a later
  migration), the database-enforced same-Photo/same-tenant guarantee
  would silently revert to a service-only one — worth a direct migration
  test asserting the constraint and FK both exist with the exact expected
  shape, in addition to the functional rejection tests.
- If Family Space teardown's explicit `active_photo_version_id = NULL`
  clearing step (§40) is ever reordered to run after
  `Photo::withTrashed()->forceDelete()` instead of before it, the
  `RESTRICT`-guarded FK would block the teardown transaction entirely —
  worth a direct teardown-ordering test.
- If `edit_recipe`/`restore` validation is ever skipped and an
  out-of-vocabulary `filter.name`, unbounded adjustment value, or
  unbounded restore parameter is accepted, it would reopen exactly the
  "closed vocabulary" guarantee this ADR relies on — worth the same
  server-side validation-before-persistence discipline ADR-0021 §8
  already established for rich-text documents.
- If Restore's preview generation is ever wired to create a
  `photo_versions` row before the User accepts it, an abandoned preview
  would litter version history — worth a direct test confirming no row
  exists unless/until "Apply restoration" is chosen.
- If Collection Photo authorization is ever checked only at add-time and
  never reconsidered at build/download time, a Photo whose visibility the
  owner has since lost could leak through a stale Collection or a stale
  archive — worth direct tests at add, build, and download time
  independently.
- If the `event_id` branch added to `NotificationManager::authorized()`
  and `NotificationController::visible()` (§54) is ever implemented in
  only one of the two, an Event-linked notification could be delivered
  after authorization was withdrawn, or remain visible in the inbox after
  delivery despite no longer being authorized — worth a direct test
  exercising both independently.
- If `EventAdmissionManager::admit()`'s RSVP-reset logic (§18) is ever
  triggered on every call rather than only a genuine post-revocation
  readmission, an in-progress RSVP could be silently wiped by an
  unrelated, idempotent re-admit call — worth a direct test distinguishing
  the two cases explicitly.
- If a cover-upload's finalization check (§10) is ever implemented to
  compare against a stale, previously-loaded copy of
  `current_cover_intent_id` rather than a freshly locked/reloaded one, a
  superseded intent could still win the race it was designed to lose —
  worth a direct test exercising the lock/reload path specifically, not
  just the comparison logic in isolation.
- If setting/replacing `current_cover_intent_id` (§9) is ever wired to
  only the ordinary upload/contribution authorization middleware, without
  the separate, explicit `AlbumPolicy::update()` check, a Contributor
  could again silently overwrite an Owner/Administrator's current cover
  intent — worth a direct test exercising the intent-setting endpoint in
  isolation from the ordinary upload endpoint.
- If a Love actor row is ever inserted before self-suppression is
  evaluated, rather than being skipped outright for a self-Love (§35), a
  transient row could still exist even if never counted at finalization —
  worth a direct test asserting no actor row is created at all for a
  self-Love, not merely that it is excluded from the final count.
- If Collection deletion's immediate object-deletion attempt (§29 step 2)
  is ever implemented to run only for exports believed to have a written
  object, rather than unconditionally against every fenced export's
  deterministic key, its value as a promptness optimization regresses —
  worth a direct test asserting the attempt is made for every state, not
  conditionally skipped based on assumed worker progress; this does not
  by itself threaten correctness, since scheduled reconciliation (§28)
  remains the actual guarantee regardless.
- If `beginGeneration()`'s retry check is ever updated to consult only
  `state` and not `cancelled_at`, a deletion-fenced export could be
  resurrected by a retry — worth a direct test asserting a `cancelled_at`
  export is rejected regardless of its `state` value.
- If the widened `app_due_family_exports()` query, or the scheduled
  command that calls it, is ever deployed without its new
  `cancelled_at`/`storage_reconciled_at` branch — or if that branch is
  ever removed under the mistaken belief that deletion's own immediate
  attempt is sufficient — a worker crash between object write and its own
  recheck once again has no process left to clean it up, silently
  reopening the exact hole this correction closes — worth a direct test
  asserting the branch exists and is exercised, not merely that the
  overall command runs without error.
- If scheduled reconciliation is ever implemented to require
  `family_exports.collection_id` or the Collection row to still exist,
  cleanup would silently stop working the moment the ordinary deletion
  sequence (§29) clears that reference or removes the Collection — worth
  a direct test running reconciliation after both have already happened.
- **If reconciliation is ever implemented to set `storage_reconciled_at`
  from a single delete attempt's outcome, without first checking
  generation quiescence, the exact race this final correction closes
  would reopen**: an absent-object observation made while a worker could
  still legitimately be in flight would once again permanently exclude
  the export from further reconciliation, even though that worker later
  writes the object and crashes — worth a direct test asserting
  `storage_reconciled_at` is never set while `generation_started_at` is
  recent and `generation_finished_at` is still null.
- If `generation_finished_at` is ever left unset on some conclusion path
  (a new failure branch added later that bypasses `markFailed()`, for
  instance), quiescence detection would silently fall back to waiting out
  the job's full timeout bound in every case — not incorrect, but a
  needless delay — worth a direct test asserting every path that
  concludes generation sets it.
- **If `beginGeneration()`'s retry path is ever implemented to set
  `generation_started_at` without also clearing `generation_finished_at`
  in the same transition, a stale completion timestamp from an earlier,
  failed attempt could satisfy quiescence for a newer, still-active retry
  — worth a direct test asserting the clear happens atomically with the
  start, not as a separate, skippable step, and specifically exercising a
  retry that begins while the prior attempt's `generation_finished_at` is
  still set.

## Implementation notes

- **Stage ownership**: this ADR's domain/API work is implemented as six
  new Phase 14 stages, inserted between accepting this ADR (`FPA-P14-S06`)
  and the existing product-integration stages (renumbered accordingly,
  mirroring exactly how ADR-0021 staged its own domain work before its
  UI-facing consumers). Ownership is stated explicitly per stage so no
  stage can claim a contract it does not actually complete:
  - **`FPA-P14-S07` — Implement Album metadata, cover and People
    association**: §2-§13 in full, including the asynchronous
    cover-upload pending-intent/finalization model with current-cover-
    intent supersession (§9-§11), the membership-invariant service-level
    enforcement across every lifecycle path (§12), and `album_people`'s
    own, complete Person-merge integration (§20) — capture, collision
    reconciliation, provenance, guarded reversal, and tests all land
    atomically in this stage, since `album_people` is introduced here.
    **Corrected in this reconciliation: this does not depend on, or land
    jointly with, `event_people`**, which is not introduced until
    `FPA-P14-S08` — the sequential `FPA-P14-S07` → `FPA-P14-S08`
    dependency remains correct, but `FPA-P14-S07` never depends on a
    table that does not exist yet.
  - **`FPA-P14-S08` — Implement Event RSVP**: §14-§21 in full, including
    revocation/readmission semantics (§18), and `event_people`'s own,
    complete Person-merge integration (§19-§20) — capture, collision
    reconciliation, provenance, guarded reversal, and tests, landing
    atomically in this stage exactly as `album_people`'s did in
    `FPA-P14-S07`, and the `Attendance` category's delivery-and-inbox-
    visibility authorization (§54).
  - **`FPA-P14-S09` — Implement the Collection domain**: §22-§26 and
    §30's explicit hand-off — the Collection model, API, and membership
    are owned here; a Collection export request contract/scope may be
    introduced, but `FPA-P14-S09` may not claim complete curated export
    semantics on its own (§30).
  - **`FPA-P14-S10` — Generalize Love across Album, Event and Story**:
    §31-§36 in full, including the corrected per-target authorization
    (§33, Photo's own authority explicitly unchanged) and the new
    `love_notification_groups` aggregation table (§35) — Photo's own Love
    requires no code change, since it already exists. Event Love
    exercises the same Event-notification authorization boundary `FPA-P14-S08`
    establishes (§54), never a second one.
  - **`FPA-P14-S11` — Implement the nondestructive Photo editor, version
    model and Restore**: §38-§48 in full, including the same-Photo
    active-version invariant (§40), authorized PhotoVersion delivery
    (§41), and structured Restore provenance and preview lifecycle
    (§43, §48).
  - **`FPA-P14-S12` — Reconcile export/download semantics onto active
    presentation versions**: §49-§51, and — depending on `FPA-P14-S09`'s
    Collection model and `FPA-P14-S11`'s Photo-version work both being
    complete — Collection export's final active-version packaging and
    download-time revalidation (§27-§28). **`FPA-P14-S12` is not complete
    until the scheduled cancelled-export object-reconciliation mechanism
    (§28) — the widened `app_due_family_exports()` branch and its
    dispatch/cleanup handling — exists and is tested**, not merely the
    fence/immediate-attempt/download-revalidation pieces; this is the
    actual crash-safety guarantee, not an optional hardening pass. `FPA-P14-S09`
    must not be treated as delivering complete Collection export behaviour
    before `FPA-P14-S12` completes this reconciliation in full (§30).
  - The existing Phase 14 product-integration stages (product shell;
    Photo/upload/Album/Event journeys; People/recognition/discovery;
    collaboration/Family Space management; responsive/visual; role-based
    acceptance) follow unchanged in substance, renumbered
    `FPA-P14-S13` through `FPA-P14-S18` — none of Phase 15's Platform
    Administration scope is pulled forward.
- **Migrations required**: one migration per new table
  (`album_tag`, `album_people`, `event_people`, `collections`,
  `collection_photos`, `reactions`, `photo_versions`,
  `love_notification_groups`, `love_notification_group_actors`), one
  migration adding `albums.starts_on`/`ends_on`/`location`/`cover_photo_id`/
  `cover_focal_x`/`cover_focal_y`/`current_cover_intent_id` (§9 — a plain
  value column, not a foreign key), one adding
  `event_admissions.rsvp_status`/`rsvp_responded_at` (with the `pending`
  default applied to existing rows — a genuine data initialization step,
  §56, not merely an additive column), one adding
  `photos.active_photo_version_id` together with `photo_versions`'
  `UNIQUE(id, photo_id, family_space_id)` constraint and the composite FK
  referencing it (§39-§40 — both must land in the same migration, since
  the FK cannot be created before its target unique constraint exists),
  and one adding `family_exports.collection_id`/`selection_checksum`/
  `cancelled_at`/`generation_started_at`/`generation_finished_at`/
  `storage_reconciled_at` (§28-§29 — `cancelled_at` the durable,
  non-retryable fence marker; `generation_started_at`/`generation_finished_at`
  the generation-quiescence signal, reusing `GenerateFamilyExport`'s
  existing `$timeout`/`WithoutOverlapping` bound as the crash-case
  fallback; `storage_reconciled_at` the durable signal that scheduled
  reconciliation has confirmed this export's storage object is gone
  *after* generation became conclusively quiescent, never merely after a
  single delete attempt returned success-or-not-found) together with
  `collections`' existing tenant-composite uniqueness and the composite
  FK referencing it, plus the `FamilyExportScope::Collection` enum value
  and the widened `app_due_family_exports()` SQL function (§28 — one
  additional `WHERE` branch, no new function). Every other new column is
  nullable with no default applied to existing rows, and is genuinely
  backfill-free (§56). No
  `media_uploads` column is required — the current-cover-intent identity
  lives entirely on `albums` (§9).
- **Required regression tests** (grouped by area; the first draft's 25
  are retained and merged with, not replaced by, the following — scenarios
  are combined where a single test can honestly cover more than one
  concern, rather than mechanically inflating the count): (1) Album
  `CHECK (ends_on >= starts_on)` rejects an inverted range; (2) an Album
  cover set to a Photo not currently in that Album is rejected; (3)
  removing an Album's current cover Photo clears `cover_photo_id`/focal
  position atomically in the same transaction; (4) Album tags/People
  never affect `AlbumPolicy::view()`/`contribute()` outcomes; (5) an
  asynchronous cover upload that completes successfully finalizes the
  cover only after Album membership succeeds and its current-cover-intent
  check still matches; (6) cover finalization correctly enforces the
  existing private-Photo visibility-widening confirmation and
  update-authority checks, plus the new, separate `AlbumPolicy::update()`
  re-check, re-loaded fresh rather than compared against a stale copy;
  (7) a cover upload that fails processing, or is cancelled, clears only
  its own matching `current_cover_intent_id` (never a newer, unrelated
  one) and never alters an existing `cover_photo_id`, leaving the Album
  valid with no broken reference; (8) an exact-duplicate resolution of `Use existing
  Photo` finalizes the cover to that existing Photo with no duplicate
  created; (9) an exact-duplicate resolution of `Create separate Photo`
  finalizes the cover to the newly-created Photo; (10) soft-deleting a
  Photo currently serving as an Album's cover clears that cover
  atomically, and restoring the Photo does not automatically restore it;
  (11) RSVP mutation by anyone other than the `EventAdmission`'s own User
  is rejected; (12) RSVP never creates a `family_activities` row and
  never changes `admitted_at`/`revoked_at`; (13) a Person with no linked
  User, connected via `event_people`, has no RSVP-mutation path available
  to them; (14) an active `EventAdmission` moved to `going` and then
  revoked is excluded from every active RSVP grouping while its
  historical `rsvp_status` is retained on the row; (15) genuine
  readmission after revocation resets `rsvp_status`/`rsvp_responded_at`
  to pending/null, while an idempotent re-admit call on a never-revoked
  admission leaves an in-progress RSVP untouched; (16) an expired
  admission is excluded from active RSVP groupings; (17) a Person merge
  correctly repoints a simple `album_people` reference and a simple
  `event_people` reference; (18) a Person merge involving a survivor
  collision on either table repoints without violating uniqueness and
  without creating a duplicate row; (19) guarded reversal of a Person
  merge restores `album_people`/`event_people` exactly as the merge
  operation's own provenance snapshot recorded, without resurrecting a
  row that would conflict with valid post-merge state; (20) a rejected
  Person merge (including the existing active-chained-merge rejection)
  leaves every `album_people`/`event_people` row unchanged; (21) a
  Collection never exposes a Photo the owner cannot currently
  `PhotoPolicy::view()`, checked independently at add-time, export-build
  time, and export-download time; (22) deleting a Collection leaves every
  Photo, Album, and Event untouched, and cleans up every associated
  export's storage object before the Collection row is removed, never
  relying on a bare database cascade to do so; (23) removing a
  `CollectionPhoto` never affects `AlbumPhoto` or any other relationship;
  (24) a Collection export packages each Photo's active presentation
  version by default, never a preserved original merely because
  Full/Personal export does; (25) a Collection archive that was `Ready`
  becomes undownloadable, requiring regeneration, once the owner's
  authorized selection no longer matches the packaged
  `selection_checksum`, while Full/Personal exports remain correctly
  un-revalidated; (26) a `reactions` row with more than one, or zero, of
  `album_id`/`event_id`/`story_id` non-null is rejected; (27) a second
  Love by the same User on the same target is rejected (idempotent add,
  not a duplicate row); (28) Photo Love continues to require
  `PhotoPolicy::interact()` plus, for Contributor, `AlbumPolicy::contribute()`
  on the reaction's Album context — never merely `view()`; (29) Album/
  Event/Story Love each require their own target's existing view
  authority; (30) a Love reactor's linked Person is shown only when the
  current viewer independently holds `view` authority over that Person,
  verified for a Contributor/Guest viewer specifically; (31) repeated
  Loves on one target, from different Users, within the aggregation
  window produce exactly one notification, safely retried and idempotent;
  (32) no Love, of any target type, ever produces a `family_activities`
  row; (33) re-editing an already-edited Photo produces a new
  `photo_versions` row derived from canonical plus the full current
  recipe, never from the prior derived asset; (34) setting
  `active_photo_version_id` to null reverts to the canonical asset
  losslessly; (35) an `edit_recipe`/`restore` document with an
  out-of-vocabulary value or an unbounded numeric parameter is rejected
  before persistence; (36) the database itself — not
  merely application code — rejects an `UPDATE`/`INSERT` attempting to set
  `active_photo_version_id` to a `photo_versions` row belonging to a
  different Photo, or to a different Family Space, via the composite FK,
  from every entry point that could set it, including a direct SQL
  attempt that bypasses application code entirely; (37) the database
  rejects deleting a `photo_versions` row that is still any Photo's active
  version (`RESTRICT`), and an explicit clear-then-delete sequence
  succeeds; deleting a non-active version is unaffected; Photo
  soft-deletion/restoration leaves version history and the active pointer
  intact; (38) authorized PhotoVersion delivery
  correctly resolves through Photo `view` authority and rejects a
  cross-Family-Space request; (39) a Restore preview creates no
  `photo_versions` row and produces no accessible asset outside the
  authorized preview-delivery path unless explicitly applied; (40) a
  Restore attempt that finds no meaningful improvement reports that
  outcome without creating a worse/no-op version; (41) applying Restore
  stores structured, bounded provenance sufficient to identify the
  algorithm version and parameters used, never an opaque blob; (42)
  ordinary Photo/Album/Event/Collection download/export resolves to the
  active presentation version by default; (43) `Download original`
  continues to resolve to the immutable original regardless of any active
  edited version; (44) a Family/Personal archival export's
  `original_included` computation is unchanged by this ADR, with the new
  version-provenance field additive only; (45) an Event-linked
  notification (Attendance or Event Love) is deliverable only while the
  recipient currently holds Event view authority, re-checked immediately
  before delivery; (46) the same Event-linked notification disappears
  from `NotificationController::index()`'s visible results the moment
  Event access is revoked, independent of the delivery-time check; (47) a
  PostgreSQL constraint test exists for every new integrity rule
  introduced in this ADR (the `stories`-style exactly-one-target `CHECK`s
  — including `love_notification_groups`' own — the RSVP status
  vocabulary `CHECK`, the Love reaction vocabulary `CHECK`, every partial
  unique index, `photo_versions`' `UNIQUE(id, photo_id, family_space_id)`,
  and the composite same-Photo/same-tenant FK on
  `active_photo_version_id`); (48) an RLS/tenant-isolation test exists for
  every new tenant-scoped table introduced in this ADR, including
  `love_notification_group_actors`; (49) starting a new cover choice
  (another upload, or an existing Photo) while an earlier upload is still
  pending correctly makes the newer choice authoritative, and the earlier
  upload finishing afterward does not overwrite it; (50) the superseded
  upload still becomes an ordinary Photo, with ordinary Album membership,
  wherever the normal contribution pipeline would otherwise permit it;
  (51) choosing an existing Photo as cover while an upload is still
  pending immediately supersedes that upload, and its later completion
  does not disturb the explicitly-chosen cover; (52) if the requesting
  actor loses `AlbumPolicy::update()` authority between initiating an
  uploaded cover and its finalization, the cover is not assigned even
  though the intent is still current; (53) contribution authority alone,
  without current Album-update authority, cannot finalize a cover;
  (53a) a Contributor holding only contribution authority can upload a
  Photo to the Album normally, but cannot set or replace
  `current_cover_intent_id` with it — the upload succeeds, the intent
  does not change; (53b) a Guest or other contribution-only participation
  level cannot supersede an Owner/Administrator's already-current cover
  intent by uploading or selecting a Photo of their own; (53c) an Owner or
  Administrator can set, replace, or clear the cover intent, including
  selecting an existing Photo as the candidate; (53d) choosing an existing
  Photo as cover, at both Album-creation time and afterward, is rejected
  for an actor lacking the applicable authority (`AlbumPolicy::create()`
  during creation, `AlbumPolicy::update()` afterward); (54) deleting a
  Collection while an associated export is still `Pending` correctly
  fences it (`cancelled_at` set, `state` moved to `Failed`) with no
  storage object present to delete; (55) deleting a Collection while an
  associated export is `Processing`, before its worker has written the
  archive object, results in no orphan object regardless of whether the
  worker's own recheck ever runs; (56) a worker that writes its archive
  object and then crashes — never reaching its own post-write recheck —
  before a concurrent Collection deletion fences the export leaves no
  permanent orphan, because deletion-side reconciliation independently
  and unconditionally attempts the same deterministic object deletion;
  (57) a deletion-fenced (`cancelled_at`-set) export is rejected by
  `beginGeneration()` even though its `state` is `Failed`, while an
  ordinary, unrelated `Failed` export (no `cancelled_at`) remains
  retryable exactly as today; (58) Collection deletion's cleanup — fence,
  object-deletion attempt, and `collection_id` clearing — is idempotent
  when repeated, including when the storage object is already absent;
  (59) `object_key`, `cancelled_at`, and `failure_reason` remain present
  and sufficient on an export row after `collection_id` has been cleared,
  so a delayed reconciliation/expiry job can still retry object deletion
  correctly; (60) the database rejects a `family_exports` row whose
  `collection_id` references a Collection in a different Family Space,
  via the composite FK; a same-tenant reference is accepted; (61) the
  composite FK's `RESTRICT` action prevents deleting a Collection while
  any `family_exports` row still references it with a non-null
  `collection_id`, confirming the service-level clearing step is not
  merely advisory; (62) queued (`Pending`), `Processing`-before-write,
  `Processing`-after-write, and `Ready` exports all converge on the same
  deterministic cleanup outcome when their Collection is deleted;
  (63) a `love_notification_groups` row with more than one, or
  zero, of `photo_id`/`album_id`/`event_id`/`story_id` non-null is
  rejected; (64) every `love_notification_groups`/
  `love_notification_group_actors` row is removed when its target, or its
  Family Space, is torn down; (65) two Loves from the same actor on the
  same open group produce exactly one `love_notification_group_actors`
  row, never two; (66) Loves from two different actors on the same open
  group are counted as two, producing the "and N others" message shape
  once finalized; (67) a self-Love never creates an actor row counted
  toward a notification delivered to the same person; (68) an actor who
  removes their Love before the group finalizes is excluded from the
  final count, and if that removal brings the count to zero, no
  notification is sent at all; (69) retrying Love-group candidate
  creation and finalization is idempotent and produces no duplicate
  notification; **(70) the exact race this final correction closes**:
  export generation begins (`generation_started_at` set); Collection
  deletion sets `cancelled_at`; deletion's immediate object-deletion
  attempt finds the deterministic `object_key` currently absent — this
  MUST NOT set `storage_reconciled_at` or otherwise terminally exclude the
  export from scheduled reconciliation; the (still-running, from before
  cancellation) worker subsequently writes `object_key` and crashes before
  its own post-write fence recheck (simulated by never invoking that
  recheck, and never setting `generation_finished_at`); scheduled
  reconciliation later selects this export via `cancelled_at IS NOT NULL
  AND storage_reconciled_at IS NULL`, correctly treats it as *not yet
  quiescent* while within the job's timeout/lock bound, and only once
  that bound has elapsed (`generation_started_at` old enough, still no
  `generation_finished_at`) does it delete the now-present object and
  record `storage_reconciled_at`; a further reconciliation pass is
  idempotent (no additional storage call, no change); (71) a cancelled
  export for which no worker was ever dispatched (`generation_started_at`
  still `null`) is immediately quiescent, and reconciliation may record
  `storage_reconciled_at` on its very first pass; (72) a worker that
  observes cancellation after writing, deletes its own object, and sets
  `generation_finished_at` makes the export immediately quiescent, so the
  next scheduled reconciliation pass (not a timeout wait) confirms absence
  and records `storage_reconciled_at`; (73) a worker that completes
  normally (`markReady()`) before ever observing cancellation is
  unaffected by any of this — Collection deletion in that case follows the
  ordinary `Ready`-export cleanup path (§29), not the cancelled/fenced
  one; (74) repeated absent-object observations made by reconciliation
  *while* `generation_started_at` is recent and `generation_finished_at`
  is still null never set `storage_reconciled_at`, no matter how many
  such passes occur, until quiescence is actually reached; (75) once
  quiescence is reached (either signal), a reconciliation pass that finds
  the object already absent (no worker ever wrote it, or an earlier
  best-effort attempt already removed it) correctly records
  `storage_reconciled_at` immediately, without requiring a redundant
  delete to "succeed" again; (76) a simulated storage-deletion failure
  during a quiescent reconciliation pass leaves `storage_reconciled_at`
  null and the export discoverable by the same query on the next run, and
  a subsequent attempt, once the simulated failure clears, succeeds and
  records it; (77) repeating reconciliation after `storage_reconciled_at`
  is already set performs no further storage call and remains idempotent;
  (78) scheduled reconciliation for a cancelled export succeeds
  identically whether or not `collection_id` has already been cleared and
  whether or not the Collection row itself still exists; (79) a cancelled
  export is rejected by `beginGeneration()` at every point in this
  lifecycle — before, during, and after quiescence and reconciliation —
  while an ordinary, unrelated `Failed`-but-not-cancelled export remains
  retryable throughout; (80) the existing expired-`Ready`-export branch of
  `app_due_family_exports()`/`DispatchDueFamilyExports` is unchanged and
  continues to select and clean up expired `Ready` exports exactly as
  before, independent of the new cancelled-export branch; **(81) the
  retry-then-cancel race**: attempt 1 begins (`generation_started_at =
  T1`), fails, and records `generation_finished_at` for attempt 1;
  `beginGeneration()` starts attempt 2 (`generation_started_at = T2`),
  and this MUST atomically clear `generation_finished_at` back to `null`
  in that same transition; while attempt 2 is still actively writing,
  Collection deletion sets `cancelled_at`; scheduled reconciliation must
  NOT treat attempt 1's now-cleared completion as proof of quiescence —
  the export remains reconciliation-due; the attempt-2 worker crashes
  before its own post-write cancellation check; reconciliation correctly
  waits until attempt 2 is quiescent by the job's own timeout bound (since
  no cooperative completion was ever recorded for attempt 2), only then
  deletes the object and records `storage_reconciled_at`; (82) an
  ordinary (non-cancelled) `Failed` export's retry via `beginGeneration()`
  clears the prior attempt's `generation_finished_at` to `null` as part of
  starting; (83) a retry that itself completes successfully sets a fresh
  `generation_finished_at` for that new attempt; (84) a `cancelled_at`-
  fenced export remains permanently ineligible for `beginGeneration()`
  regardless of how many prior attempts it had or what either
  `generation_started_at`/`generation_finished_at` currently hold; (85)
  tests 70-80 continue to pass unmodified — the atomic-clear correction
  changes only retry behaviour, never the single-attempt paths those
  tests already exercise.

## Review triggers

- **If a genuine requirement for more than one Love reaction value ever
  emerges**: revisit §31-§32 as a deliberate, separate decision — do not
  silently widen the `reaction` `CHECK` vocabulary.
- **If a genuine requirement for `maybe` RSVP, or organiser-entered RSVP
  on behalf of another User, ever emerges**: revisit §15-§16 explicitly,
  including what it implies for the notification and grouping logic.
- **If Photo editing ever needs drawing, text, stickers, layers, cutout,
  or generative capability**: scope it as a separate, deliberate ADR — V1's
  closed geometry/adjustment/five-filter vocabulary is not meant to grow
  informally.
- **If Restore's conservative-operation set ever needs to expand toward
  generative reconstruction**: that is a materially different product
  and safety posture, requiring its own explicit decision, never a quiet
  extension of §47.
- **If Collections ever need sharing, comments, reactions, tags, or
  People associations**: that would make a Collection meaningfully
  Album-shaped, and should be reconsidered as such explicitly, rather
  than incrementally growing Collections into a second Album model.
- **If the Album-cover or active-Photo-version service-level invariants
  are ever found to have a gap in production**: treat it as a signal to
  revisit whether a database-level safeguard (even a heavier one, such as
  a trigger) is warranted, rather than only patching the service code —
  the structural reason a plain composite FK cannot help is well
  understood (§12, §40), but that does not mean no database-level
  safeguard is possible, only that the obvious one is not.

## Deferred concerns

- The exact bounded numeric ranges for `adjustments`/`filter.intensity`/
  `straighten_degrees`/Restore's own parameters (§42-§43) — an
  implementation-guide detail, not an architectural one.
- The exact rendering/processing implementation for generating
  `derived_object_key` from `edit_recipe` (server-side image processing
  library/service choice) — deliberately left to implementation.
- The exact bounded expiry window for an unaccepted Restore preview
  (§48) — an implementation-guide detail.
- Object-storage lifecycle/retention policy for superseded `photo_versions`'
  `derived_object_key` assets (whether they are ever purged, and when) —
  out of scope here; no such policy is introduced or assumed.
- The exact UI copy/flow for the Album-cover-removal warning (§13) — a
  product-copy detail, not a domain decision.

## Resolved decisions

1. **ADR number** — `ADR-0019`, the number `tasks.json` already reserved
   for Phase 14's UI/UX integration decision; this is that ADR's first
   draft.
2. **Album date range** — optional `starts_on`/`ends_on`, reusing
   `events`' exact representation and range `CHECK`.
3. **Album location** — a plain optional string, reusing `events.location`
   exactly; no geography subsystem introduced.
4. **Album tags** — reuse the existing Family-Space-scoped `Tag`
   vocabulary via a new `album_tag` pivot mirroring `photo_tag`; never
   authorization-bearing.
5. **Album People** — a new, small, non-authorization-bearing
   `album_people` association; never implies per-Photo membership,
   authorization, or Event semantics; integrated into Person merge.
6. **Album cover** — optional; a typed `cover_photo_id` (`RESTRICT`, not
   `SET NULL`) plus fractional focal position, never a duplicate asset;
   must reference a current, valid member Photo, enforced at the service
   layer across every lifecycle path, including Photo soft deletion.
7. **Album cover creation is a mix of synchronous and asynchronous
   paths, with one authoritative current-cover-intent identity, gated by
   Album-update authority at both ends** — an existing Photo or "none"
   resolve synchronously with Album creation; an uploaded cover is a
   pending intent tracked by `albums.current_cover_intent_id` (not a flag
   on `MediaUpload`, which cannot prevent a stale, superseded upload from
   overwriting a newer choice). **Setting, replacing, or intentionally
   clearing the intent — including selecting an existing Photo as
   candidate — requires `AlbumPolicy::update()` at the moment it is set**,
   separate from and in addition to the ordinary upload's own contribution
   authority, which alone governs whether the underlying Photo upload
   itself may proceed; finalization then re-verifies both that the intent
   is still current *and* that the requesting actor's Album-update
   authority still holds — contribution authority is never sufficient at
   either point — including through exact-duplicate resolution and
   failure/cancellation handling scoped to the exact matching intent only.
8. **Album cover lifecycle** — addable/changeable/removable/repositionable
   post-creation by existing manage-authority; removing the cover Photo
   (explicitly or via soft deletion) warns and atomically clears the
   cover; no automatic fallback cover is ever chosen; restoration never
   automatically reinstates a cleared cover.
9. **Event RSVP boundary** — independent of, and never altering,
   `EventAdmission`'s access-granting fields; scoped to exactly the
   Guest/Contributor population `EventAdmission` already represents.
10. **Event RSVP schema** — `rsvp_status`/`rsvp_responded_at` added
    directly to `EventAdmission`, not a separate object; `maybe` excluded
    from V1; the existing-row default is an honest data-initialization
    step, not "no backfill."
11. **Event RSVP authorization** — self-service only; no
    manages-members or organiser-on-behalf override.
12. **Event RSVP effective population and groupings** — Going/Awaiting
    reply/Not attending are derived read-time views of one column, scoped
    to currently-effective (non-revoked, non-expired) admissions only.
13. **Event RSVP revocation/readmission** — revocation preserves the
    historical RSVP answer without live-exposing it; a genuine
    readmission resets RSVP to pending; an idempotent re-admit call on an
    active admission never resets an in-progress RSVP.
14. **Historical/unlinked Person and Events** — a new `event_people`
    descriptive association, entirely independent of RSVP; such a Person
    cannot personally RSVP; integrated into Person merge.
15. **Album People/Event People Person-merge integration, each atomic to
    its own introducing stage** — both integrate into
    `PersonMergeManager`'s existing capture/reconcile/guarded-reversal
    transaction using the same collision-aware repoint pattern
    `saved_search_people` and ADR-0021's mention tables already establish;
    `album_people`'s full integration lands in `FPA-P14-S07`,
    `event_people`'s lands independently in `FPA-P14-S08` — neither
    depends on the other, and `FPA-P14-S07` never depends on a table that
    does not yet exist; the active-chained-merge prohibition is
    unaffected.
16. **Event RSVP notifications** — a new, restrained `Attendance`
    category notifying the Event's organiser; no feed activity; delivery
    and inbox visibility share one Event-authorization boundary.
17. **Collections** — a new, minimal, strictly private, non-archival,
    non-authorization-widening personal Photo-curation domain; never an
    Album, Event, or shared object.
18. **Collection schema** — `collections`/`collection_photos`, owner-only
    authorization, Photo membership always re-checked against current
    visibility.
19. **Collection bulk population** — deduplicated by Photo id from an
    Album's or Event's currently-authorized Photos; no new direct
    Photo-Event relationship introduced.
20. **Collection export is Collection-aware, not a bare reuse of
    `FamilyArchiveBuilder`, with a database-enforced tenant-consistent
    reference** — a new `FamilyExportScope::Collection` value, with
    `collection_id` a genuine composite FK (`(collection_id,
    family_space_id) → collections(id, family_space_id)`, `RESTRICT` —
    never `SET NULL`, for the same structural reason as
    `active_photo_version_id`), reuses ADR-0015's job/ownership/expiry
    lifecycle, but selection is re-authorized at build time, packaging
    defaults to active presentation versions (never originals merely
    because archival export includes them), and a `Ready` archive is
    revalidated at download time against a `selection_checksum` computed
    from the Photo ids actually packaged after the builder's own
    reconciliation — never from the earlier, pre-build selection.
21. **Collection export generation and Collection deletion are
    interlocked, with durable, quiescence-gated scheduled reconciliation
    — not either side's one-shot attempt, and not a mere delete-once
    marker — as the actual crash-safety guarantee** — because
    `FamilyArchiveBuilder::buildAndStore()` writes the archive object
    before `markReady()` runs, a `Processing` export can already have a
    real object, and a worker may write it *after* Collection deletion's
    own immediate attempt already ran (finding nothing) and then crash
    before its own recheck. An absent-object observation proves nothing
    about a worker that has not yet written, so terminal cleanup
    (`storage_reconciled_at`) is recorded only once generation is
    conclusively quiescent — `generation_finished_at` set for the
    *current* attempt (the worker cooperated), or `generation_started_at`
    never set at all, or the *existing, unchanged* `GenerateFamilyExport`
    job's own configured `$timeout`/`WithoutOverlapping` bound has
    definitively elapsed since the current attempt started — *and* a
    delete attempt made at or after that point confirms absence.
    **`beginGeneration()` atomically clears `generation_finished_at` back
    to `null` every time it starts a new attempt (including a retry of an
    ordinary `Failed` export), in the same transition as setting
    `generation_started_at` — so a non-null `generation_finished_at` can
    never be a stale leftover from an earlier, already-superseded attempt.**
    Both
    Collection deletion's own immediate attempt and the worker's
    post-write recheck remain best-effort, promptness-only optimizations,
    never proof of terminal cleanup by themselves. **The durable guarantee
    is a scheduled reconciliation process, extending this project's
    existing `app_due_family_exports()`/`DispatchDueFamilyExports`
    export-cleanup infrastructure with a second query branch**
    (`cancelled_at IS NOT NULL AND storage_reconciled_at IS NULL`),
    re-evaluating quiescence and retrying idempotent object deletion on
    every scheduled run until terminal reconciliation is genuinely
    warranted, using nothing but the `FamilyExport` row's own
    permanently-retained metadata — independent of whether the Collection,
    or even `collection_id` itself, still exists. A dedicated, durable
    `cancelled_at` marker (never inferred from `state`/`failure_reason`
    text) fences an export non-retryable, checked explicitly by
    `beginGeneration()`, so a deletion-fenced export can never restart
    generation while an ordinary, unrelated `Failed` export remains
    retryable. `collection_id` is cleared via an explicit, single-column
    service-level `UPDATE` (valid under standard `MATCH SIMPLE`
    composite-FK semantics, never an FK action) as part of the same
    deletion sequence, without waiting for storage reconciliation to
    complete. Collection deletion never strands storage in any state
    (`Pending`/`Processing`/`Ready`), and never relies on a bare database
    cascade for object cleanup.
22. **Love targets and vocabulary** — Photo, Album, Event, Story; exactly
    one value, `love`, in V1; Person explicitly excluded.
23. **Love schema** — Photo reuses the already-shipped `photo_reactions`
    table unchanged; Album/Event/Story share one new, typed-exactly-one-
    target `reactions` table.
24. **Love authorization is accurate per target** — Photo Love keeps its
    existing `interact()`-plus-Contributor-`contribute()` authority
    unchanged; Album/Event/Story Love each use their own target's
    existing view authority; Love never widens access anywhere.
25. **Love reactor disclosure** — a linked Person is shown only when the
    current viewer independently holds `view` authority over that
    specific Person — directly informed by this project's own recent
    mention-rendering authorization-gap finding.
26. **Love notification aggregation is target-keyed and typed, with
    normalized distinct-actor tracking — not a reuse of `ContributionGroup`,
    and not a generic `target_type`/`target_id` pair** — a new
    `love_notification_groups` table (typed, nullable
    `photo_id`/`album_id`/`event_id`/`story_id`, exactly-one `CHECK`,
    tenant-consistent cascading FKs) groups several different actors'
    Loves on the same target within a window into one notification,
    reusing the Phase 12 candidate/evaluation architecture's shape, not
    its actor-and-batch-centric schema; a companion
    `love_notification_group_actors` table (`UNIQUE(group_id,
    actor_user_id)`) tracks distinct contributing actors directly, never
    inferred from `NotificationCandidate` recipient rows — retries are
    idempotent, self-Love never contributes, and a Love removed before
    finalization is not counted.
27. **Love feed exclusion** — no Love, individual or batched, ever
    produces a `family_activities` row.
28. **Story reconciliation** — ADR-0021 is restated for production use,
    not reopened; Love is additive to, not a change of, its schema.
29. **Nondestructive Photo editor** — original immutable; a new
    `photo_versions`/`active_photo_version_id` model holds derived
    presentations; every version derives fresh from canonical plus a
    complete, closed-vocabulary `edit_recipe`, never from a prior
    derivative; the active-version invariant (same Photo, same Family
    Space) is **database-enforced** via a composite FK against
    `photo_versions`' own `UNIQUE(id, photo_id, family_space_id)`
    constraint, using `RESTRICT` rather than an impossible composite
    `SET NULL`, with teardown explicitly clearing the pointer before the
    cascade that follows — corrected from the first draft's overclaim
    that a single-column FK alone made this structurally impossible.
30. **Authorized PhotoVersion delivery** — a new delivery method resolves
    through Photo `view` authority, distinct from and additive to
    `MediaDeliveryManager`'s existing canonical/variant/original methods.
31. **Restore provenance is structured, not a boolean** — a bounded,
    closed-vocabulary `restore` document records algorithm version,
    processing mode, and chosen parameters, sufficient to understand or
    reproduce the result, never an opaque blob or editor-specific state.
32. **Restore lifecycle** — an explicit, fully-specified preview →
    authorize → keep-or-apply → persist contract, with an explicit
    "no meaningful improvement" outcome and idempotent retries.
33. **Export/download semantics** — ordinary exports/downloads resolve to
    the active presentation version by default; `Download original` and
    archival Family/Personal export authority are unchanged, gaining only
    additive version-provenance metadata.
34. **Event notification authorization covers delivery and inbox
    visibility together** — `NotificationManager::authorized()` and
    `NotificationController::visible()` both gain an identical `event_id`
    branch; Attendance and Event Love share this one boundary.
35. **Migration/backfill accuracy** — genuinely additive, nullable,
    no-default columns are backfill-free; `event_admissions.rsvp_status`'s
    existing-row default is an honest, described data-initialization
    step.
36. **Stage sequencing** — implemented as six new Phase 14 stages
    (`FPA-P14-S07`-`S12`), between accepting this ADR (`S06`) and the
    existing, renumbered product-integration stages (`S13`-`S18`), with
    explicit per-stage ownership so no stage claims a contract it does
    not complete (`FPA-P14-S09`'s Collection export hand-off to
    `FPA-P14-S12` above all); Platform Administration remains entirely in
    Phase 15.
