# ADR-0014: Memories, Family Homepage and Notifications

- Status: Accepted
- Date: 2026-09-09
- Decision owners: David
- Related stages: FPA-P12-S01 (accepting this ADR completes that stage),
  implemented by FPA-P12-S02, FPA-P12-S03, FPA-P12-S04, FPA-P12-S05,
  FPA-P12-S06

## Context

Phases 6-11 built a rich, typed family archive — Photos, Albums, Events,
Stories, confirmed Person identity, and search/Discovery over all of it.
Phase 12 gives that archive a front door: a homepage that makes the
archive feel alive, and a notification mechanism that helps family
members notice and return to genuine activity. Neither is a dashboard of
counts, and neither is an engagement-optimised feed — `PROJECT_ROADMAP.md`
Phase 12's own objective is "a warm, useful homepage that feels like a
private family home rather than a social feed," and this project's
photograph-domain decisions already pre-committed part of this ADR's
scope: ADR-0008 explicitly rejects "weighting of memories (Phase 12) or
search (Phase 11) by reaction count" and states plainly that "this
product is the deliberate alternative to an algorithmic,
engagement-optimised feed." That constraint is binding here, not merely
aspirational.

**Four concepts, verified as genuinely distinct in this codebase**:

- A **domain mutation** is an authoritative fact already recorded by an
  existing table (a `PhotoPerson` row, an `Album`, an `AuditEvent`).
- A **family activity** is a family-facing account of a meaningful,
  authored action worth showing on the homepage.
- A **memory** is family-history content worth resurfacing because of
  what it is (a date, a Person, an anniversary) — not because someone
  recently acted on it.
- A **notification candidate** is something worth proactively telling one
  specific recipient, arising from the same domain mutation as a family
  activity but serving a different audience with different lifecycle
  rules.

These do not share one persistence model. `family_activities` (new)
serves the first two; live derivation over existing tables serves the
third; `notification_candidates`/`notifications`/`notification_deliveries`
(new) serve the fourth. Discovery (ADR-0013 §16) is reused as-is for
relationship traversal and is not reinvented here.

**`AuditEvent` cannot serve as the family-facing activity or notification
source, for reasons beyond taste.** Direct inspection of
`apps/api/database/migrations/2026_08_04_030000_add_tenancy_audit_and_deletion_support.php`
confirms `audit_events` has `FORCE ROW LEVEL SECURITY` enabled with
**only an `INSERT` policy** — no `SELECT` policy exists anywhere in the
schema. Under Postgres RLS, a forced table with no matching policy for a
command denies that command outright for the application's ordinary
runtime role. `AuditEvent` is therefore not merely semantically
unsuitable (its actor is always a `User`, never a `Person`; its wording is
machine-oriented; it carries IP/user-agent that must never reach a family
display) — **it is structurally unreadable by the application today.**
`AuditRecorder::record()` (`apps/api/app/Services/AuditRecorder.php`)
remains untouched and continues to run exactly as it does today,
independently of everything this ADR introduces.

**This reconciliation re-verified several claims against the live write
path and corrected places the original draft assumed a mechanism that
does not exist**:

- **There is no single transaction that adds several Photos to an Album
  at once, and no single completion point for a bulk upload either.**
  Direct inspection of `apps/api/app/Services/AlbumContributionFinalizer.php`,
  `apps/api/app/Services/AlbumManager.php`, and
  `apps/api/app/Services/EventContributionNotifier.php` confirms each
  Photo finalizes and dispatches independently —
  `EventContributionNotifier::dispatch()` is called once per Photo from
  `AlbumContributionFinalizer::completeNewContribution()`, immediately
  queuing `SendEventContributionNotifications` for that one Photo. There
  is no batch-completion transaction, owner, or event anywhere in the
  live pipeline. **A genuinely useful, already-existing mechanism does
  connect the individual finalizations**: `media_uploads.upload_batch_id`
  (`apps/api/database/migrations/2026_08_10_000000_create_media_uploads.php`,
  a nullable `char(26)`, indexed with `family_space_id`) is already a
  client-suppliable ULID — `InitiateMediaUploadRequest` already validates
  it (`['nullable', 'ulid']`) and `MediaUploadManager` already persists it
  per upload. §5 and §28 use this existing value as the basis for both
  presentation grouping and notification convergence, without inventing a
  batch-completion boundary the live pipeline does not have.
- **`PhotoStory` has no `album_id`.** Confirmed by direct inspection of
  `apps/api/app/Models/PhotoStory.php` (`Fillable` lists only
  `family_space_id, photo_id, author_id, body, edited_at`) — a Story
  cannot be truthfully described as a participant in one Album-specific
  conversation, because it is not scoped to any Album at all. `PhotoComment`
  *does* carry `album_id` alongside `photo_id`
  (`apps/api/app/Models/PhotoComment.php`), which is the only table this
  ADR's comment-recipient rule may read from (§23).
- **`family_activities`/`notifications` cannot use one `target_type` +
  `target_id` pair as if it were a single database foreign key.**
  PostgreSQL cannot attach one FK column to several unrelated target
  tables. §8 and §29 use a small, closed set of nullable typed foreign-key
  columns instead.
- **`ShouldBeUnique` is a temporary queue-level lock, not durable
  business-logic idempotency.** Its de-duplication window
  (`uniqueFor`) expires; after that, a genuinely duplicated or
  redelivered job runs again as if new. If, in the meantime, a recipient
  had re-enabled a previously disabled notification preference, a naive
  re-run would incorrectly materialise a candidate that should have
  stayed permanently skipped — contradicting this ADR's own settled rule
  that re-enabling a preference affects only future candidates. §26
  introduces the smallest durable record needed to prevent this.
- **A client-supplied `upload_batch_id` alone is not a safe, unique
  logical contribution identity.** Direct inspection confirms
  `media_uploads.upload_batch_id`
  (`apps/api/database/migrations/2026_08_10_000000_create_media_uploads.php`)
  and `media_uploads.target_album_id`
  (`apps/api/database/migrations/2026_08_24_020000_create_albums.php`)
  are independent, separately nullable columns with no compound
  constraint tying them together — nothing in the schema prevents the
  same client-generated ULID from being reused across different Albums
  (including Albums linked to different Events), or, since it is
  entirely client-supplied, across different actors. §25 and §28 derive
  the notification-candidate identity from the full logical contribution
  tuple — actor, batch id, and target Album together — never the bare
  batch id.
- **None of `albums`, `events`, `photos`, `photo_stories`, or
  `photo_comments` currently has an additive `UNIQUE(id,
  family_space_id)` constraint** — confirmed by direct inspection of
  their creating migrations. `people` and `media_uploads` already do
  (`people_id_family_space_unique`, `media_uploads_id_family_space_unique`,
  added in Phase 10's face-analysis migrations, per the same pattern
  ADR-0011/0012 already established). The typed subject/target columns
  this ADR introduces on `family_activities` and the notification tables
  require the same additive constraint on every parent they reference
  before the composite tenant-consistent foreign keys can be created. §9
  makes this explicit.

**Existing infrastructure this ADR builds on rather than replaces**:

- `PersonAccountLink` (`family_space_id, person_id, user_id, created_by`)
  is the only bridge from a `User` actor to a navigable `Person` identity.
  Not every `User` has one; not every `Person` has a linked `User`.
- `PhotoPolicy::update()` (`apps/api/app/Policies/PhotoPolicy.php:43-47`)
  already governs Photo metadata edits (non-Guest, and either a
  manages-members role or the Photo's own creator) — the natural,
  reusable authority boundary for a new metadata-shaped Photo flag.
- A **complete, working, narrow-scope notification pipeline already
  exists** for one case: `SendEventContributionNotifications`
  (`apps/api/app/Jobs/SendEventContributionNotifications.php`) resolves
  recipients fresh at job-execution time (admitted Guests within
  `admission_lifetime_days`, Owner/Administrator, the Event creator if
  still active), excludes the acting user, and uses
  `EventNotificationDelivery` (`family_space_id, event_id, photo_id,
  user_id, sent_at`) as an idempotent `firstOrCreate`-on-natural-key
  delivery marker before sending `EventPhotoContributed`, a
  `Notification` class using Laravel's `mail` channel exclusively. No
  `database` notification channel and no general preference model exist
  anywhere in the codebase today — this ADR generalises the proven
  mechanics of the existing pipeline into a category-driven one, it does
  not invent new mechanics.
- `apps/api/app/Demo/DemoFamilyBuilder.php` (the Mercer Family Demo)
  seeds three real `User` accounts each linked via `PersonAccountLink` to
  a `Person`, staggered `created_at` timestamps, two `private`-visibility
  Photos, one `unknown`-precision Photo, and a full range of
  `exact`/`month`/`year`/`decade`/`approximate` dated Photos — sufficient
  to exercise most of this ADR's behaviour without redesign. It does not
  seed a Contributor, a Guest with `EventAdmission`, or a Person
  identified separately in time from its Photo's upload; these should be
  added when Phase 12 is implemented, not as a precondition to accepting
  this ADR.

`ADR-0006` §12's standing obligation applies directly: any new
Person-referencing column this ADR introduces must integrate with
`PersonMergeManager`'s existing capture/reconcile/guarded-reversal
transaction in the same stage it is introduced. `FamilySpaceDeletionManager
::teardown()` never physically deletes the `family_spaces` row and
performs an explicit per-table delete walk instead — any new family/user
content table this ADR introduces must be added to that walk explicitly,
following the precedent ADR-0013 §15 already set for `saved_searches`.

**Observability**: a separate audit confirmed this project already has
substantial, real OpenTelemetry infrastructure — HTTP server spans,
propagated trace context across internal queue boundaries, working
Laravel-to-Python distributed tracing for face analysis, and a real local
collector/backend. This ADR does not introduce OpenTelemetry as new
architecture and does not depend on any observability completion work;
it only requires that the new activity/notification pipelines participate
in the conventions that already exist (§34).

## Decision

### 1. Scope

Phase 12 establishes homepage and notification **behaviour and data
contracts**: what is shown, to whom, why, and where it is stored. It does
not establish final visual design, typography, animation, or navigation
chrome — that is Phase 14's scope, exercised here only far enough to
verify the behaviour works end to end.

### 2. Four concepts, one architecture each

| Concept | Persistence | Audience |
|---|---|---|
| Domain mutation | Existing authoritative tables (unchanged) | N/A |
| Family activity | New `family_activities` (write-time, transactional per mutation) | Every authorized viewer of the homepage |
| Memory | Fully derived, live queries — never persisted | Every authorized viewer of the homepage |
| Notification candidate | New `notification_candidates` / `notifications` / `notification_deliveries` | One specific recipient |

A single domain mutation may produce a family-activity row, a
notification candidate, both, or neither — these are independent
decisions made at the same call site, never inferred from one another,
and never sharing a table. Discovery is unchanged: it is Phase 11's
existing explicit relationship traversal (ADR-0013 §16), reused directly
by homepage "more from this Person" surfaces.

### 3. Homepage model: bounded, mixed sections

The homepage is a small, fixed set of purpose-labelled sections — Recent
family activity; On this day / Around this time; Recent Stories; People
with new memories; Event/anniversary memories where applicable — never
one endless chronological feed and never a single blended ranking score
across sections. Activity-recency and historical-date have no shared
unit; forcing them into one score would fabricate a relevance function
this project has no honest basis for. A "Recently added" section that
would merely restate the same facts as "Recent family activity" with
weaker framing is not introduced.

### 4. `family_activities`: atomic facts, not a fictional batch transaction

Each real Photo→Album/Event addition, Story addition, Album/Event
creation, or identity confirmation writes **its own** `family_activities`
row, transactionally, in the same domain-manager call that already
writes that mutation's `AuditRecorder::record()` entry — never batched
into one row that pretends several separate database writes were one
transaction. `family_activities` never asserts a fact the domain tables
don't already assert, so it remains a rebuildable, family-facing pointer
with display metadata, not a second source of family truth. Presentation
grouping ("David added 5 photos...") is a separate, deliberate
computation over these atomic rows (§5) — never achieved by writing a
false batch row.

### 5. Presentation grouping: explicit logical operation identity, not a fabricated transaction

**Atomic domain mutation and presentation grouping are different
concerns.** Each `family_activities` row records one real mutation; a
nullable `contribution_batch_id` column records the logical operation it
belongs to, when one genuinely exists:

- For Photos reaching an Album via upload finalisation
  (`AlbumContributionFinalizer::completeNewContribution()`), the value is
  copied directly from that Photo's `MediaUpload.upload_batch_id` — an
  already-existing, already-client-suppliable identifier requiring no new
  API surface.
- For a Photo added to an Album via `AlbumManager::addPhoto()` (an
  existing Photo being manually attached, with no `MediaUpload`/batch
  concept in that path at all), `contribution_batch_id` is `null` — that
  addition is honestly its own, ungrouped fact.

Homepage rendering groups `family_activities` rows sharing the same
non-null `(actor_user_id, action_type, subject_album_id,
contribution_batch_id)` tuple into one presented card, **recomputed fresh
on every render** — **rows with a `NULL` batch id are never grouped with
each other**, only rows sharing the identical non-null value are. Because
this grouping is read-time and query-based rather than a one-shot push,
it needs no completion signal: a batch's card simply includes whatever
rows currently share its id, however many have landed so far, and
naturally shows more as later Photos in the same batch finalize. A later,
separate bulk upload to the same Album carries a new client-generated
`upload_batch_id` and therefore forms a new group automatically — no
rolling time window, no heuristic, and no redesign of the upload
subsystem beyond reading a column that already exists. Notifications
cannot use this same read-time trick, because a notification is a
one-time push rather than something recomputed on every view — §28
defines the deterministic convergence model that need exists for.

### 6. Activity taxonomy

Included: Photo(s) added to an Album or Event (individually or as one
logically grouped batch, per §5); Story added; human-confirmed Person
identity added to a Photo (`PersonProposalStatus::Approved` only — never
`pending`); Album created; Event created.

Excluded, deliberately, matching the discipline ADR-0008/0010 already
applied to comments and reactions: pending machine recognition
suggestions of any kind; biometric confidence or processing detail;
duplicate-detection housekeeping; membership/invitation/admin/security
actions; raw upload-processing events with no family-legible Album/
Event/Person context yet; every Person/relationship edit; every comment;
every reaction. Not every mutation is feed-worthy, and inflating the
taxonomy is the fastest way back to an engagement feed this project has
explicitly rejected.

### 7. Actor identity

`actor_user_id` is always populated (every taxonomy action in §6 has a
real acting User). `actor_person_id` is resolved through
`PersonAccountLink` at write time and is nullable — when no link exists,
display falls back to the User's own name, never an invented Person.
Guest contributions use the identical resolution path; Guests are not
given a lesser identity model, only a narrower authorization scope (§18).

### 8. `family_activities`: typed subject columns, not a polymorphic pair

The Phase 12 subject vocabulary is small and closed. `family_activities`
uses explicit nullable typed foreign-key columns for it, never an
untyped `target_type`/`target_id` pair:

```text
family_activities
    id                     ulid, primary key
    family_space_id        tenant scope, RLS
    actor_user_id
    actor_person_id        nullable, typed FK, tenant-consistent composite
    action_type            enum: photos_added_to_album, story_added,
                            person_identity_confirmed, album_created,
                            event_created
    subject_album_id       nullable, typed FK, tenant-consistent composite
    subject_event_id       nullable, typed FK, tenant-consistent composite
    subject_story_id       nullable, typed FK, tenant-consistent composite
    subject_person_id      nullable, typed FK, tenant-consistent composite
    contribution_batch_id  nullable — a plain copied value (§5), not a
                            foreign key; batches are not a table
    photo_ids              bounded JSON array of Photo ids (nullable;
                            illustrative thumbnails only — never
                            consulted for authorization or merge; capped,
                            e.g. 12)
    created_at
```

A `CHECK` constraint enforces that exactly the subject column matching
`action_type` is populated and every other subject column is `NULL` —
`photos_added_to_album`/`album_created` → `subject_album_id` only;
`event_created` → `subject_event_id` only; `story_added` →
`subject_story_id` only; `person_identity_confirmed` →
`subject_person_id` only. Where an Album is Event-linked, the Event is
resolved for display via the Album's own `event_id` at render time —
never duplicated onto `family_activities` itself. `photo_ids` remains the
one deliberate non-relational exception in this table, used only for
illustration; every count and disclosure decision re-derives visibility
from the live Photo rows it references (§17), never from the array's
mere presence. **Every typed FK column above requires its target table to
carry an additive `UNIQUE(id, family_space_id)` constraint before it can
be created — §9 makes this explicit and orders the migrations.**

### 9. Supporting tenant-composite `UNIQUE` constraints

**Corrected in this reconciliation.** A composite tenant-consistent
foreign key — `FOREIGN KEY (subject_album_id, family_space_id)
REFERENCES albums (id, family_space_id)` — requires Postgres to have a
unique (or primary key) constraint on exactly `(id, family_space_id)` on
the referenced table. Direct inspection of the live schema confirms
`people` and `media_uploads` already carry this additive constraint
(`people_id_family_space_unique`, `media_uploads_id_family_space_unique`)
from Phase 10, but `albums`, `events`, `photos`, `photo_stories`, and
`photo_comments` do not. Every one of these five tables is referenced by
a typed column this ADR introduces, so each requires the identical
additive constraint before the dependent Phase 12 tables can be created:

```text
albums          → albums_id_family_space_unique          (needed by family_activities.subject_album_id, notifications.album_id)
events          → events_id_family_space_unique           (needed by family_activities.subject_event_id)
photos          → photos_id_family_space_unique           (needed by notifications.photo_id)
photo_stories   → photo_stories_id_family_space_unique    (needed by family_activities.subject_story_id, notifications.story_id)
photo_comments  → photo_comments_id_family_space_unique   (needed by notifications.comment_id)
```

`id` remains each table's sole primary key and domain identity; no
tenancy or lifecycle semantics change on any of these five tables — this
is purely additive relational-integrity support, identical in kind to
what Phase 10 already did for `people` and `media_uploads`. Migration
ordering within each stage is fixed: **(1)** add the supporting parent
`UNIQUE(id, family_space_id)` constraints; **(2)** create that stage's new
Phase 12 tables; **(3)** add the composite tenant-aware foreign keys.
`FPA-P12-S02` adds the constraints for `albums`, `events`, `photos`, and
`photo_stories` (every parent `family_activities` references) before
creating `family_activities`; `FPA-P12-S06` adds the one remaining
constraint, on `photo_comments`, before creating the notification tables
(§34).

### 10. Date-based memories: honest wording per precision

Fully derived, live, over `historical_date`/`historical_date_window_end`
(the generated columns ADR-0013 §6/§10 already established) — never
persisted. Eligibility follows the *interval* each precision actually
represents, reusing ADR-0013 §6's semantics exactly rather than
collapsing them for convenience:

```text
exact        → eligible for true "On this day" wording, when the
               calendar day matches
month        → the day within the month is genuinely unknown; eligible
               only for broader wording such as "This month," "From
               around this time," or "In September 1984" — never a
               specific-day claim the data does not support
year         → broader, year-based resurfacing only
decade       → broader, period-based resurfacing only
approximate  → wording must explicitly reflect the existing uncertainty
               (e.g. "around 2000") — the stored value being a literal
               date does not make the memory exact; no precision is
               invented that this domain does not actually have
unknown      → never date-resurfaced
```

Every resurfaced item states its own reason ("On this day in 1984,"
"Sometime in September 1984") — never an unexplained appearance, and
never a false claim of precision.

### 11. Recently-added vs. historically-recent

These remain two different facts, read from two different columns, never
collapsed: `created_at` (or the activity timestamp) answers "when did
someone act in Fambam"; `historical_date` answers "when did the family
memory happen." A homepage card may correctly say both "David added this
yesterday" and "Christmas 1984" about the same Photo — neither substitutes
for the other, and `created_at` is never used as a proxy for
`historical_date` anywhere in memory resurfacing.

### 12. Person-centred memories and Discovery reuse

"People with new memories" is a fully derived listing/count — never a
maintained counter — over approved `PhotoPerson`, Stories on Photos they
appear in, and Event/Album associations created within a bounded recent
window, computed against the viewer's current authorized set, and
respecting `do_not_resurface` (§16) on every referenced Photo. Any "more
from this Person" surface reuses ADR-0013 §16's existing Discovery
traversal endpoints directly; this ADR introduces no second Discovery
model.

### 13. Stories on the homepage

A Story card always carries author/actor identity, the Story's own
identity, its owning Photo (with thumbnail), and the Photo's People/
Album/Event context where the viewer is authorized to see it — reusing
the `PhotoStorySearchSummary` shape ADR-0013 §3 already defined, never
flattened to anonymous text.

### 14. Comments and reactions are excluded from homepage activity

Neither appears in any homepage section, initially. Comments are
Album-scoped (ADR-0010 §6) and the same Photo can carry independent
conversations in different Albums, making a homepage-level comment card
ambiguous about which conversation it belongs to; reactions are
explicitly excluded from influencing memories or ranking by ADR-0008.
Comments remain a legitimate notification concern (§22) — a different
audience with different rules, not a homepage concern.

### 15. Human-confirmed identity as family activity

"Sarah identified William Mercer in 3 Photos" is family activity,
sourced only from human decisions reaching `PersonProposalStatus::Approved`
— never a pending proposal, a `FaceIdentityAssignment` of any status, or
any confidence/biometric value. This is a direct extension of the same
human-confirmed-only boundary ADR-0012/ADR-0013 already established for
identity as family fact.

### 16. `do_not_resurface`: Photo-level resurfacing exclusion

An additive `photos.do_not_resurface` boolean (default `false`).
Authority to set it is exactly `PhotoPolicy::update()`'s existing
boundary — non-Guest, and either a manages-members role or the Photo's
own creator — reused unmodified, since this is a metadata-shaped edit,
not a new authority tier. Effect is narrow and singular: the flagged
Photo is excluded from automatic memory resurfacing (§10, §12) only. It
does **not** hide or delete the Photo, does not change `PhotoVisibility`
or any authorization decision, does not remove it from Search or
Discovery, and does not alter any historical fact. No Story-level
equivalent is introduced for v1 — a Story is always shown attached to its
owning Photo's card, so the Photo-level flag is sufficient until a
concrete case demonstrates otherwise. This column is introduced in the
same stage as the first memory query that must respect it (§34) — not
after it.

### 17. Authorization-before-disclosure on the homepage

Every homepage section — activity, memories, Discovery-derived listings —
is filtered through the viewer's **current** authorized universe at read
time, reusing the exact entry points ADR-0013 §9 already established
(`PhotoQuery::visibleTo()`, `AlbumQuery::visibleTo()`, the Event-visibility
query, `PersonPolicy::viewAny()`) — never the authorization state at the
time the activity occurred, and never persisted alongside the activity
row itself. Where a `family_activities` row's `photo_ids` batch is
partially visible to the current viewer, the displayed count reflects
only the viewer-visible subset (a five-Photo batch with two now-private
or deleted Photos shows **"3 photos,"** never "5" with two silently
missing, and never omitted entirely) — hidden content remains
indistinguishable from non-existent content, exactly as established
throughout ADR-0008/0010/0011/0012/0013.

### 18. Role-based homepage behaviour

Owner/Administrator/Member see the ordinary Family-Space-wide homepage.
Contributor sees activity/memories scoped to content they can already
access or contribute to — never a broader Family-Space-wide stream.
Guest receives an Event-centric landing experience, driven by
`EventAdmission`/Album-participation authority, **not** the general
homepage — this reuses ADR-0009's existing Guest model; no new
authorization mechanism or separate application is introduced for any
role.

### 19. Personalisation stays light

The homepage represents the shared, authorized family archive primarily —
not an individually algorithmic feed. No recommendation engine, no
"recently viewed" ranking signal, no engagement-derived weighting is
introduced. Saved searches or usage patterns may inform future product
work if real evidence supports it; none is built speculatively here.

### 20. Empty and low-activity states

The homepage must remain useful for a new, small, mature, or temporarily
quiet Family Space. When recent activity/memories are sparse, the
homepage falls back to genuine archive entry points already available
(explore People, Albums, Events, recent Stories) rather than a bare "No
activity" state. Final copy and illustration are Phase 14's concern; the
behavioural requirement — never a dead end — is fixed here.

### 21. Notification architecture: generalising the proven pattern

The existing `SendEventContributionNotifications` pipeline is
generalised, not replaced. For each notification-worthy domain mutation
(§22), the originating domain manager dispatches a queued job carrying
the typed subject reference and the same `TenantOperationContext`-array
shape already used throughout this codebase. That job resolves a
**notification candidate** — a durable record of one recipient/category/
originating-action combination (§26) — before doing anything
channel-specific: it re-checks current authorization, independently
checks each recipient's in-app and email preference for that category
(§24, §29), and, per channel, either produces that channel's
recipient-facing outcome or records that it was skipped. Self-notification
is suppressed generically (actor equals recipient) exactly as the
existing pipeline already does.

### 22. Notification taxonomy

Included: comments/conversation on a Photo in an Album the recipient is
connected to; Photo(s) added to an Album/Event the recipient participates
in; Story added; confirmation of the **recipient's own** linked Person
identity. Excluded: reactions; pending machine recognition suggestions;
biometric confidence/detail; duplicate-detection housekeeping; general
admin/security activity; routine technical mutations.

### 23. Notification recipient resolution

- **Comments/conversation**: eligible recipients are bounded to actors
  whose relationship to that specific `(photo_id, album_id)` conversation
  is real and already established — the Album's creator; the Photo's own
  creator/contributor; and anyone who has previously authored a
  **`PhotoComment`** in that exact `(photo_id, album_id)` conversation.
  **`PhotoStory` authors are never included as comment-conversation
  participants** — a Story has no `album_id` and cannot be truthfully
  described as belonging to one Album-specific conversation; Story
  authorship is handled entirely under its own category (below). Also
  never included: every Person confirmed in the Photo; relatives of
  anyone confirmed in the Photo; participants from a *different* Album's
  conversation on the same Photo. Being depicted in a Photo does not
  imply subscription to that Photo's Album-scoped conversation, and
  extending recipients along either the depicted-person or the
  relationship axis would be a "follow this Person / Photo / Album"
  capability this ADR does not introduce.
- **Album/Event contributions**: reuses the existing
  `SendEventContributionNotifications` recipient logic directly for
  Event-linked Albums (admitted Guests within admission lifetime,
  Owner/Administrator, the Event creator if still active); for an
  Album with no linked Event, the Album's creator is the recipient.
- **Story added**: the Photo's own creator, plus the linked `User` (via
  `PersonAccountLink`) of any Person confirmed in that specific Photo.
  This is a single, direct hop from a Person's own confirmed presence to
  their own linked account — the same boundary the identity-confirmation
  category uses — not a relationship-derived extension. It does not
  violate the depicted-does-not-imply-subscription principle above,
  because it is not routed through anyone else's relationship and is not
  about conversation subscription; it is about a new fact recorded about
  content the recipient is *themselves* directly, confirmedly part of.
- **Identity confirmation**: the linked `User` of the confirmed Person
  only, and only when that confirmed Person is the recipient's **own**
  linked identity. A user is never notified because a relative — child,
  parent, sibling, or otherwise — was identified; that would require a
  separate relationship-interest/following capability, explicitly out of
  Phase 12 scope.
- No recipient is ever invented where no `PersonAccountLink` exists.

### 24. Notification preferences and defaults

A small, category-based `notification_preferences` table
(`family_space_id, user_id, category, channel, enabled`) — four
categories (comment, contribution, story, identity) × two channels
(in-app, email); never one generic preferences blob, never a
per-action-type switch, never a preference row referencing a job/class
name. Defaults: **in-app enabled for every category** (pull-based, zero
interruption cost); **email enabled by default only for comment and
identity** (the two highest personal-relevance categories); **email
disabled by default for contribution and story** (already visible on the
homepage, lower urgency). Users may change any of these.

### 25. Notification identity: the logical originating action

Notification identity is a fixed tuple, sourced from a real, stable
domain id per category, never the target alone and never a timestamp:

```text
(family_space_id, recipient_user_id, category, source_action_id)
```

```text
comment       → the PhotoComment id
story         → the PhotoStory id
contribution  → the contribution group id (§ below) when a batch id is
                present, otherwise the single AlbumPhoto id it concerns
identity      → the PhotoPerson id (the specific confirmation row)
```

`source_action_id` is a **plain scalar identifier value** — not itself a
foreign key, and not the same thing as the typed target columns used for
navigation and Person-merge integration (§29). A later, distinct action
against the same target — a second comment, a second Story, a new
contribution batch — carries a different `source_action_id` and therefore
always produces a new notification candidate.

**Corrected in this reconciliation: the contribution category's
`source_action_id` is never the bare `upload_batch_id`.** Because a
client-supplied batch id alone does not uniquely identify one logical
contribution (§ Context — the same id can legitimately or carelessly
recur across different Albums or different actors), the contribution
category's `source_action_id` identifies the **full logical contribution
group**: the same actor, the same `upload_batch_id`, and the same target
Album, together. This is materialized as a small `contribution_groups`
table:

```text
contribution_groups
    id                  ulid, primary key — this id is the
                        contribution category's source_action_id
    family_space_id     tenant scope, RLS
    actor_user_id
    upload_batch_id     NOT NULL — a row here exists only for a genuine
                        client-supplied batch; an ungrouped, manually
                        added Photo never creates one
    album_id            typed FK, tenant-consistent composite
    created_at
```

`UNIQUE(family_space_id, actor_user_id, upload_batch_id, album_id)` is
this table's natural key: **same actor + same batch + same Album**
resolves (`firstOrCreate`) to the same group and therefore the same
notification candidate; **same batch + a different Album**, or **same
batch + same Album + a different actor**, each resolve to a distinct
group and therefore a distinct candidate — exactly the corrected
invariant this reconciliation requires. Where the Album is Event-linked,
the Event is resolved from that Album for display and recipient purposes
(§23) exactly as elsewhere in this ADR — the Album, never the Event,
remains the grouping boundary, since two different Event-linked Albums
sharing an Event must never converge merely because they share one.
`upload_batch_id` being `NOT NULL` on this table specifically means the
manual, single-Photo `AlbumManager::addPhoto()` path never creates a
`contribution_groups` row at all — it keeps using the single `AlbumPhoto`
id as `source_action_id` directly, sidestepping any ambiguity about
matching `NULL` batch ids against each other. `contribution_groups` is
tenant-scoped and torn down like every other new table (§32); it carries
no Person reference and needs no `PersonMergeManager` integration.

### 26. Durable notification-candidate record: `notification_candidates`

**Corrected in this reconciliation.** `ShouldBeUnique` prevents a queue
from running two near-simultaneous copies of the same job, but its lock
expires; it is not durable business-logic idempotency. Without a durable
record, a genuinely duplicated or much-later redelivered job — arriving
after a recipient has since re-enabled a previously disabled preference —
could incorrectly materialise a notification for a candidate that was
already, deliberately, permanently skipped, contradicting §29's rule that
re-enabling a preference affects only future candidates.

A small, additional table closes this gap — its only purpose is to
record that one specific `(recipient, category, source_action_id)`
candidate has already been evaluated, never to become the in-app
notification itself or a general event log:

```text
notification_candidates
    id                     ulid, primary key
    family_space_id        tenant scope, RLS
    recipient_user_id
    category
    source_action_id       plain scalar value (§25) — not a foreign key
    evaluated_at            nullable — set only once evaluation reaches
                            a terminal outcome (§28); NULL while a
                            contribution candidate is still converging
    in_app_outcome          enum: pending, created, skipped_preference,
                            skipped_authorization
    email_outcome           enum: pending, created, skipped_preference,
                            skipped_authorization
    created_at
```

`UNIQUE(family_space_id, recipient_user_id, category, source_action_id)`
is this table's natural key and the system's actual durable idempotency
anchor. The job flow is:

1. `firstOrCreate` a `notification_candidates` row on this natural key.
2. **If the row already existed and its outcomes are already terminal**
   (not `pending`): no-op. The candidate was already fully evaluated;
   current preferences are never re-checked for it, by design — this is
   precisely what makes re-enabling a preference affect only genuinely
   new candidates.
3. **If the row is newly created** (comment, story, and identity
   categories only — see §28 for contribution's two-phase flow): evaluate
   current authorization and each channel's current preference once,
   create a `notifications` row if in-app is enabled, create a
   `notification_deliveries` row if email is enabled, record each
   channel's outcome (`created` or `skipped_preference`/
   `skipped_authorization`), and set `evaluated_at`.

This is deliberately minimal — one small table, no event log, no
general-purpose event-sourcing framework — and its only behavioural job
is "has this exact candidate already been decided."

### 27. Per-channel independence given a durable candidate

Recipient resolution and current-authorization checking happen once per
candidate (§26), independent of any channel. **If the in-app preference is
enabled** for that recipient/category: create the recipient's in-app
`notifications` row. **If the in-app preference is disabled**: no in-app
row is created for that candidate — not hidden, not deferred, recorded
only as `skipped_preference` on the durable candidate row. Re-enabling
the preference later affects only *future* notification candidates; it
never retroactively materialises a candidate whose durable outcome is
already `skipped_preference`. **If the email preference is enabled**:
create a `notification_deliveries` row and send via the `mail` channel,
independently of whether the in-app row was created. **Email may exist
without a corresponding in-app row, and vice versa** — the two channels
are deliberately independent, per-recipient, per-category outcomes of the
same candidate, both recorded on the one durable row that anchors the
candidate's identity. **Preferences are re-checked only once, at the
candidate's first (non-`pending`) evaluation** — not on every subsequent
duplicate or retry, which instead see the already-terminal outcome and
no-op (§26 step 2). This is a deliberate refinement of the general
"revalidate before delivery" discipline (§30): authorization for an
*already-decided* channel outcome is still revalidated again immediately
before that channel actually delivers and once more at in-app read time
(§30) — what is not re-run is the *preference* decision itself, once a
candidate has reached a terminal outcome, which is exactly the behaviour
this reconciliation exists to guarantee.

### 28. Contribution-batch convergence: staggered Photo finalization

**Corrected in this reconciliation.** Because Photos finalize
independently (§ Context) with no batch-completion boundary, the
`contribution` category cannot resolve a candidate synchronously the way
comment/story/identity do. Instead, it uses a two-phase flow keyed on the
same `source_action_id` (§25) every contributing Photo's job shares:

1. **Each Photo's finalization dispatches its own notification-processing
   job**, carrying the actor, the target Album, and the
   `upload_batch_id` (or, absent one, going directly to the single
   `AlbumPhoto` id — no `contribution_groups` row involved at all, per
   §25). When a batch id is present, the job resolves
   (`firstOrCreate`s) the `contribution_groups` row for
   `(family_space_id, actor_user_id, upload_batch_id, album_id)` — the
   full logical contribution identity, never the batch id alone — and
   uses that group's own `id` as `source_action_id`. It then
   `firstOrCreate`s the `notification_candidates` row for
   `(recipient, contribution, source_action_id)`. If this is the first
   Photo to reach this candidate, both outcome columns start as
   `pending`.
2. **The job then schedules (or re-schedules) one delivery job for this
   exact candidate, after a short, fixed, explicit debounce delay** (on
   the order of a minute — an implementation detail, not a product
   commitment) — using `ShouldBeUnique` on the delivery job, keyed to the
   candidate's natural key, for exactly the purpose `ShouldBeUnique` is
   actually good for: collapsing several near-simultaneous re-dispatches
   (one per Photo in the same actor+batch+Album group) into a single
   scheduled delivery, **never** as the source of durable correctness.
   This is not a broad rolling time window — the delay is short, fixed,
   and scoped to one specific, already-identified logical contribution
   group, never merging unrelated actors, Albums, or batches the way a
   generic activity time-window would.
3. **When the delivery job finally executes**, it re-queries, live,
   every Photo currently finalized and currently authorized whose
   `AlbumPhoto` belongs to **this exact group's Album** *and* whose
   originating `MediaUpload` matches **this exact group's actor and
   `upload_batch_id`** — the full tuple, never the batch id alone. A
   Photo finalized under the same batch id into a different Album, or by
   a different actor, is never included, however the query is written.
   Where the group's Album is Event-linked, the Event is resolved from
   that Album only for display/recipient purposes (§23) — it never
   widens or replaces the query's Album scope. The delivery job builds
   the in-app/email content from that live, correctly-scoped set,
   evaluates each channel's current preference exactly once (§27),
   creates whichever `notifications`/`notification_deliveries` rows the
   outcomes call for, and sets both outcome columns on
   `notification_candidates` to their terminal value plus
   `evaluated_at`. From this point the candidate is terminal, exactly as
   in §26.
4. **A Photo that finalizes after its group's candidate has already gone
   terminal is never silently dropped and never used to retroactively
   edit an already-delivered notification.** Its own finalization job
   resolves the same `contribution_groups` row (the full tuple is
   identical), finds its `notification_candidates` row already terminal,
   and — rather than reopening it — falls back directly to its own
   standalone `source_action_id`: its own `AlbumPhoto` id, which is
   already inherently unique to that actor's contribution action within
   that specific Album, producing its own, honestly separate,
   single-Photo notification candidate rather than reopening or
   duplicating the group's own notification.

This satisfies every invariant a convergence model here needs: every
independently finalized Photo may safely dispatch work; duplicate or
staggered jobs are harmless (steps 1-2 are naturally idempotent, and step
3 only ever runs once per candidate per §26); one logical contribution
group produces at most one final recipient notification per category/
`source_action_id`; the delivered content always reflects the finalized,
currently-authorized contents of that exact actor+batch+Album tuple at
the moment of delivery, not at the moment the first Photo arrived; a
different Album, a different actor, or a genuinely later, separate upload
batch — any of which resolves to a different `contribution_groups` row
even when an `upload_batch_id` happens to coincide — always creates a new
notification; and no Photo is silently lost, because a late arrival
always produces *some* honest notification, either converging into its
group's still-pending candidate or standing on its own.

### 29. `notifications` / `notification_deliveries`: typed columns, two tables

```text
notifications
    id                     ulid, primary key
    family_space_id        tenant scope, RLS
    recipient_user_id
    category               enum: comment, contribution, story, identity
    source_action_id       plain scalar value (§25) — not a foreign key
    photo_id               nullable, typed FK, tenant-consistent composite
    album_id               nullable, typed FK, tenant-consistent composite
    story_id               nullable, typed FK, tenant-consistent composite
    person_id              nullable, typed FK, tenant-consistent composite
    comment_id             nullable, typed FK, tenant-consistent composite
    read_at                nullable
    created_at

notification_deliveries
    id                     ulid, primary key
    family_space_id        tenant scope, RLS
    recipient_user_id
    category
    source_action_id
    notification_id        nullable, FK to notifications — populated only
                            when the in-app row also exists for this
                            candidate; a delivery is never blocked on one
                            existing
    channel                enum: mail (extensible; no push exists today)
    status                 enum: pending, sent, failed
    attempted_at / sent_at / failure_reason
```

A `CHECK` constraint enforces exactly the typed columns relevant to
`category` are populated and the rest are `NULL` — `comment` →
`photo_id`, `album_id`, `comment_id`; `contribution` → `album_id` (the
linked Event, where one exists, is resolved via the Album's own
`event_id` at render time, exactly as §8 already does for
`family_activities` — never duplicated here); `story` → `story_id`;
`identity` → `person_id`, `photo_id`. `notifications` is the
product-facing, recipient-scoped record with its own read-state
lifecycle; `notification_deliveries` is the channel-specific technical
execution history — kept separate so retry/failure bookkeeping never
overloads or corrupts the read-state record, and each is created only
when its own channel's preference is enabled (§27), never unconditionally,
and only once per candidate, per the durable outcome recorded on
`notification_candidates` (§26).

### 30. Notification authorization-before-disclosure

Authorization is checked at three points: a pre-filter at candidate
creation; a full re-check immediately before any channel-specific
delivery (matching the existing job's already-correct behaviour of
resolving recipients at execution time, not trigger time); and again at
in-app read/open time, since an unread `notifications` row can persist
for a long time during which access can change. A notification whose
underlying subject has become inaccessible to its recipient renders and
behaves as if it does not exist — the same indistinguishable-from-
nonexistent discipline as the homepage (§17). Unlike preferences (§27),
authorization is never treated as "decided once" — it is genuinely
re-evaluated at each of these three points, because access can change for
reasons entirely independent of the notification pipeline itself (an
`AlbumGrant` revoked, an `EventAdmission` expired).

### 31. Role-based notification behaviour

Contributor receives notification categories scoped to content they can
already access or contribute to, never a Family-Space-wide category.
Guest receives Event-scoped notifications only while their
`EventAdmission` is currently valid, generalising exactly the
already-correct logic `SendEventContributionNotifications` uses today;
expired or revoked admission stops future disclosure through the same
recipient-resolution re-check.

### 32. Person-merge, deletion, retention and teardown integration

Any Person-referencing typed column this ADR introduces —
`family_activities.actor_person_id`, `family_activities.subject_person_id`,
`notifications.person_id` — is integrated into `PersonMergeManager`'s
existing capture/reconcile transaction, in the same stage the table is
introduced, per ADR-0006 §12. Because neither `family_activities` nor
`notifications` is authoritative family truth (unlike, say,
`saved_search_people`), a simpler standard applies: plain FK repointing
on merge, with a narrow collision rule for `notifications`' natural-key
uniqueness (where merge would make two existing notifications collide on
the same recipient/category/`source_action_id`, keep the newer and drop
the older) — full snapshot/guarded-reversal machinery is not required,
since nothing of family-historical value is lost if a reversed merge does
not perfectly resurrect pre-merge notification rows. `source_action_id`
itself never needs merge reconciliation — it references a Comment, Story,
contribution batch, or confirmation event, never a Person directly.

`family_activities`, `contribution_groups`, `notification_candidates`,
`notifications`, `notification_deliveries`, and `notification_preferences`
are added explicitly to `FamilySpaceDeletionManager::teardown()`'s
per-table delete list — `ON DELETE CASCADE` from `family_spaces` never
fires, since that row is only ever status-flipped, never physically
deleted, exactly the pattern ADR-0013 §15 already established for
`saved_searches`. None of these six tables is retained past teardown,
unlike `audit_events`, which is deliberately exempt for forensic-retention
reasons that do not apply to family/user-facing content.

### 33. Observability compliance, not new architecture

This ADR does not introduce OpenTelemetry, does not treat any
observability gap as a Phase 12 design dependency, and does not add a
stage for it. It requires only that every new activity/notification
write path: participates in this project's existing trace/correlation
conventions; propagates `traceparent`/`correlation_id` across the new
queued boundaries the notification pipeline introduces (including the
debounced delivery job in §28), using the same pattern already proven in
existing jobs; emits bounded operational telemetry for notification
creation, delivery, and failure (counts and durations only); and never
places private notification text, Photo content, or Person names into
ordinary traces or metrics.

### 34. Phase boundaries and stage ownership

- **`FPA-P12-S02`** adds the supporting `UNIQUE(id, family_space_id)`
  constraints on `albums`, `events`, `photos`, and `photo_stories` (§9),
  then implements §4-§8 (`family_activities`'s typed schema, atomic-write
  discipline, presentation grouping via `contribution_batch_id`,
  taxonomy, actor identity) and the "Recent family activity" homepage
  section, fully authorized from its first commit.
- **`FPA-P12-S03`** implements §10-§11 (date-based memories, the
  recently-added/historically-recent distinction) **and introduces
  `photos.do_not_resurface`** (§16) — the additive column, its default/
  backfill, `PhotoPolicy::update()`-based authority, and every date-memory
  query respecting it from this stage's first commit. The column must
  exist before any memory query depends on it; it is not deferred to a
  later stage.
- **`FPA-P12-S04`** implements §12-§14 (person-centred memories, Stories)
  and reuses Discovery directly, respecting the `do_not_resurface` field
  `FPA-P12-S03` already introduced — it does not reintroduce the column
  or its authority.
- **`FPA-P12-S05`** implements the functional user-facing control for
  `do_not_resurface` (the toggle UI and its own exclusion-management
  tests) and §20 (empty/low-activity states) — it is **not** the stage
  that creates the underlying field.
- **`FPA-P12-S06`** adds the one remaining supporting constraint, on
  `photo_comments` (§9), then implements §21-§33 in full — the entire
  notification architecture, preferences, recipient resolution, the
  durable `notification_candidates` record, the `notifications`/
  `notification_deliveries` split, contribution-batch convergence,
  authorization revalidation, role-based behaviour, Person-merge/teardown
  integration, and a functional in-app notification surface —
  atomically, in one stage, never split across a later stage.

`tasks.json` already reflects `FPA-P12-S06` (depending on
`FPA-P12-S05`) and `FPA-P13-S01`'s corrected dependency on
`FPA-P12-S06`, so Phase 13 cannot begin before notification work
completes. `docs/IMPLEMENTATION_GUIDE.md`'s Phase 12 section is updated
in this same reconciliation round to add the supporting-constraint
migration ordering to S02/S06 and to introduce `notification_candidates`
and the contribution-batch convergence model in S06.

## Alternatives considered

- **Using `AuditEvent` directly as the family activity or notification
  source** — rejected: beyond the semantic mismatch (User-only actor,
  ungrouped, machine wording, security metadata), `audit_events` has no
  `SELECT` RLS policy at all, making it structurally unreadable by the
  application's runtime role today.
- **A background listener/consumer building `family_activities` from
  domain events** — rejected: no domain-event/listener architecture
  exists anywhere in this codebase; every existing side effect, including
  `AuditRecorder::record()`, is a direct synchronous call inside the
  mutating transaction. Introducing an event bus for this would be new,
  unjustified architecture.
- **Persisting memory "recommendations" for convenience** — rejected:
  memories are fully derivable live from existing generated columns and
  relationships; persisting them would create a second, driftable
  representation of facts the domain tables already assert.
- **One immutable `family_activities` row written from one fictional
  batch transaction** — rejected: no such transaction exists in the live
  write path; atomic per-mutation rows plus an explicit, already-existing
  `upload_batch_id`-derived grouping key achieve the same presentation
  outcome honestly.
- **A broad rolling time window for activity or notification grouping**
  — rejected, as originally decided and reaffirmed here: it can silently
  merge two unrelated sessions by the same actor; an explicit logical
  operation identity (§5, §28) is deterministic and requires no window
  tuning. The short, fixed debounce delay in §28 is a materially
  different mechanism — anchored to one already-identified logical
  operation, never merging unrelated actors/Albums/batches — and is not a
  reintroduction of the rejected heuristic.
- **Including `PhotoStory` authors as Album-conversation comment
  participants** — rejected: `PhotoStory` has no `album_id` and cannot be
  truthfully placed in one Album-specific conversation; Story authorship
  is handled entirely under its own notification category.
- **A mandatory `notifications` row for every candidate regardless of
  preference** — rejected: a disabled preference genuinely prevents that
  channel's row from being created, with no hidden/deferred state and no
  retroactive effect from later re-enabling it.
- **Relying on `ShouldBeUnique` alone as durable notification
  idempotency** — the original draft's error, corrected in §26:
  `ShouldBeUnique`'s lock is temporary and expires; a durable
  `notification_candidates` row is the actual anchor, so a later
  duplicate or redelivered job can never incorrectly resurrect a
  previously, deliberately skipped candidate.
- **No durable record at all when every channel is disabled** — the
  original draft's error, corrected in §26/§27: the candidate is still
  recorded, with both outcomes marked `skipped_preference`, so a later
  duplicate job sees it as already-decided rather than re-evaluating
  current (possibly since-changed) preferences.
- **A general-purpose notification event log / event-sourcing system** —
  considered and rejected: `notification_candidates` is deliberately
  narrow — one row per candidate, two outcome columns, no history of
  intermediate states, no generic event schema.
- **Claiming one notification job is dispatched "per contribution
  batch" from a boundary that does not exist** — the original draft's
  error, corrected in §28: no batch-completion transaction or event
  exists in the live pipeline; each Photo dispatches its own idempotent
  processing job, and a short, fixed, explicitly-scoped debounce
  converges them onto one delivery per batch.
- **A scheduled reconciliation sweep for contribution batches instead of
  a debounced delivery job** — considered (the reviewed batching option) and not
  adopted: a debounce keyed to the specific candidate already dispatches
  and converges without a separate periodic sweep needing to discover
  "which batches are probably done," and requires no new scheduled-command
  infrastructure beyond what this codebase's existing job scheduler
  already provides.
- **Using the bare, client-supplied `upload_batch_id` as the contribution
  category's `source_action_id`** — the original draft's error, corrected
  in §25: direct inspection confirmed `upload_batch_id` and
  `target_album_id` are independent columns with no compound constraint,
  so the same client-generated id can legitimately or carelessly recur
  across different Albums or different actors. `source_action_id` now
  identifies the full logical contribution group (actor + batch id +
  Album), materialized as a small `contribution_groups` table, never the
  bare batch id.
- **A deterministic hash derived from `family_space_id` + `actor_user_id`
  + `upload_batch_id` + `album_id` instead of an explicit
  `contribution_groups` table** — considered and not adopted: a plain
  relational table with a natural-key `UNIQUE` constraint gives the same
  determinism without picking a hash function, canonical byte/string
  ordering, or collision analysis, and stays consistent with this
  project's preference for a real, inspectable row over a derived opaque
  key.
- **Uniqueness keyed only on `(recipient, category, target)`** —
  rejected: it would permanently collapse distinct real events (a second
  comment, a second Story, a new contribution) onto the first. Identity
  now includes the logical originating action (`source_action_id`),
  sourced from a real, stable domain id per category.
- **An untyped `target_type`/`target_id` pair described as a real
  foreign key** — rejected: PostgreSQL cannot enforce referential
  integrity across a polymorphic pair spanning unrelated tables; a small,
  closed set of nullable typed FK columns plus a `CHECK` constraint gives
  real relational integrity, ordinary teardown, and direct
  `PersonMergeManager` discoverability instead.
- **Composite tenant-consistent foreign keys without the supporting
  parent `UNIQUE(id, family_space_id)` constraints** — the original
  draft's omission, corrected in §9: Postgres cannot create such an FK
  without it; every parent this ADR's typed columns reference now has an
  explicit, ordered migration step adding it first.
- **A normalized `family_activity_subjects`/`notification_subjects` child
  table instead of typed nullable columns** — considered and not
  adopted: the Phase 12 subject vocabulary is small (four to five cases)
  and each activity/notification row has exactly one subject, so a child
  table would add a join for every read without adding integrity a
  `CHECK` constraint doesn't already provide; revisit only if the
  vocabulary grows materially (§ Review triggers).
- **A Story-level `do_not_resurface` flag alongside the Photo-level one**
  — rejected for v1: a Story is always shown attached to its owning
  Photo's card, so the Photo-level flag already covers the only
  resurfacing path a Story currently has; add one only if a concrete case
  demonstrates a Story needing independent suppression from a Photo that
  itself still resurfaces.
- **Introducing `do_not_resurface` in the user-controls stage (S05)** —
  rejected: S03/S04's memory queries must respect the column from their
  first commit, which is impossible if it does not yet exist; the column
  ships in S03, with S05 owning only the user-facing control surface.
- **Treating observability completion as a Phase 12 prerequisite** —
  rejected: the existing OpenTelemetry infrastructure is substantial and
  real; the remaining gaps are narrow, unrelated completion items handled
  as a separate, bounded correction, not a Phase 12 design dependency.
- **A dedicated Phase 12 stage for observability** — rejected for the
  same reason; §33 states compliance requirements only.

## Consequences

### Positive

- Reusing an already-proven notification pipeline (recipient resolution,
  idempotent delivery, self-suppression) rather than designing one from
  nothing removes most of the implementation risk from the notification
  half of this ADR.
- The durable `notification_candidates` record is small (one table, two
  outcome columns) but closes a real correctness gap — preference changes
  can never retroactively resurrect or duplicate a decision already made,
  regardless of how queue-level deduplication windows behave.
- The contribution-batch convergence model (§28) requires no new
  scheduled-job infrastructure and no fictional completion event — it is
  built entirely from mechanisms this codebase already has (delayed
  dispatch, `ShouldBeUnique`, a natural-key durable row).
- Typed nullable FK columns with a `CHECK` constraint, now backed by
  explicit supporting `UNIQUE(id, family_space_id)` constraints on every
  referenced parent, give `family_activities`/`notifications` real,
  enforceable relational integrity — no generic morph/graph
  infrastructure anywhere in Phase 12.
- Per-channel independence in notification delivery means a disabled
  preference behaves exactly as a user would expect — nothing is
  silently queued, hidden, or resurrected later.

### Negative

- Three new persisted tables for notifications (candidates, deliveries,
  preferences) plus `notifications` itself is more schema than a
  single-table approach, in exchange for durable idempotency and cleaner
  retry/read-state separation.
- A dedicated `FPA-P12-S06` extends Phase 12's total implementation
  surface materially beyond the original five-stage roadmap sketch.
- The contribution-batch debounce delay means a notification for a bulk
  upload is not instantaneous — a deliberate, bounded trade-off in
  exchange for one coherent notification instead of several fragmented
  ones.
- `family_activities`' `photo_ids` JSON array is the one place in this
  ADR that stores a bounded list rather than a fully normalized join —
  acceptable because it is illustrative-only and never authoritative or
  authorization-relevant, but worth naming as a deliberate exception to
  this project's general preference for normalized relational storage.

### Risks

- If any homepage or notification surface is ever implemented as "query
  broadly, then filter/redact," that is a direct existence-disclosure
  leak, the same failure mode named repeatedly since ADR-0010 §3 — worth
  a direct test per section and per notification category.
- If a `family_activities` row's `photo_ids` is ever used for
  authorization or disclosure decisions instead of illustration, it would
  bypass live, current authorization entirely — worth a direct test that
  a Photo removed from the underlying visibility still cannot be rendered
  from a stale `photo_ids` reference.
- If `ShouldBeUnique` is ever relied upon as the sole idempotency
  mechanism for notifications (rather than `notification_candidates`),
  a job redelivered after the unique-lock window expires could duplicate
  or incorrectly resurrect a decision — worth a direct test redelivering
  a job well after its `uniqueFor` window has elapsed.
- If a contribution candidate's delivery job is ever implemented to use a
  cached Photo count from the first-arriving Photo's job rather than
  re-querying live at execution time, a staggered batch would under-report
  its true contents — worth a direct test where Photos finalize at
  different points within the debounce window and the final notification
  still reflects all of them.
- If a Photo finalizing after its batch's candidate has gone terminal is
  ever silently dropped instead of falling back to its own standalone
  notification, real content would go unreported — worth a direct test
  for exactly this staggered-arrival case.
- If the convergence query for a contribution group is ever implemented
  to filter by `upload_batch_id` alone rather than the full
  `(actor, upload_batch_id, album_id)` tuple, Photos from a different
  Album — or a different actor's Photos reusing the same client-generated
  batch id — could leak into a recipient's notification content — worth
  a direct test with two Albums (including two Albums each linked to a
  different Event) sharing one batch id, and a direct test with two
  actors sharing one batch id, asserting each produces its own,
  independent notification with no cross-contamination.
- If comment-notification recipient resolution is ever extended to
  include Photo-depicted People, their relatives, or `PhotoStory` authors
  "for completeness," it silently reintroduces the follow-model and the
  Story/Album conflation this ADR explicitly rejects — worth a direct
  regression test for each excluded case.
- If notification identity ever reverts to `(recipient, category,
  target)` without `source_action_id`, a second genuine comment/Story/
  contribution would be silently swallowed — worth a direct test that two
  sequential distinct actions each produce their own notification.
- If any of the five newly-required `UNIQUE(id, family_space_id)`
  constraints is skipped, the corresponding composite foreign key cannot
  be created at all — a build-time failure, not a silent runtime one, but
  worth a direct migration test confirming each constraint exists before
  its dependent table's migration runs.
- If `family_activities`/`notification_candidates`/`notifications`/
  `notification_deliveries`/`notification_preferences` are not added to
  `FamilySpaceDeletionManager::teardown()`'s delete list, they survive a
  deleted Family Space indefinitely — worth a direct teardown test.
- If `do_not_resurface` is ever checked inside a Search, Discovery, or
  authorization query path rather than only inside memory-resurfacing
  queries, it would silently create an undocumented visibility control —
  worth a direct test that a flagged Photo remains fully searchable and
  discoverable.

## Implementation notes

- Stage-by-stage ownership, including migration ordering for the
  supporting `UNIQUE(id, family_space_id)` constraints, is fixed in §9
  and §34; `tasks.json` already carries `FPA-P12-S06` and
  `FPA-P13-S01`'s corrected dependency, and
  `docs/IMPLEMENTATION_GUIDE.md`'s Phase 12 section is updated in this
  same round to match.
- **Required regression tests**: (1) a homepage section/count for content
  the viewer cannot see must be indistinguishable from no match, for
  every section; (2) a `family_activities` batch with some Photos now
  inaccessible must display only the viewer-visible subset in its count;
  (3) `exact` qualifies for true "On this day," `month` qualifies only
  for broader "this month"/named-month wording, `year`/`decade`/
  `approximate` qualify only for broader period wording, `unknown` never
  resurfaces; (4) `created_at`/activity timestamps are never substituted
  for `historical_date` in any memory card; (5) a `pending` `PhotoPerson`
  or any `FaceIdentityAssignment` status never produces family activity
  or a notification; (6) `do_not_resurface` excludes a Photo from memory
  resurfacing only — Search, Discovery, visibility, and historical facts
  are unaffected; (7) two separate single-Photo Album additions by the
  same actor, each with a `NULL` `contribution_batch_id`, never render as
  one grouped card; (8) five Photos uploaded with the same
  `upload_batch_id` and finalizing at staggered times within the debounce
  window converge into exactly one grouped activity card and exactly one
  notification reflecting all five; (9) a comment notification never
  reaches a Person confirmed in the Photo, a relative of anyone confirmed
  in it, or a `PhotoStory` author on that Photo, unless they are also the
  Album creator, the Photo's creator, or a prior `PhotoComment` author in
  that exact `(photo_id, album_id)` conversation; (10) an identity
  notification never reaches a relative of the confirmed Person; (11) a
  comment notification from Album A never resolves to Album B's
  conversation on the same Photo; (12) reactions never produce a
  notification or influence any ranking/resurfacing decision; (13)
  self-notification is suppressed for every category; (14) disabling a
  category's in-app preference records `skipped_preference` on the
  candidate and prevents that channel's row from being created, and
  re-enabling it later never retroactively creates a row for a candidate
  already recorded as skipped; (15) disabling in-app while email remains
  enabled for the same candidate still produces the email delivery; (16)
  two sequential, distinct comments on the same Photo each produce their
  own notification, never collapsing into one; (17) a retried/duplicated
  dispatch of the *same* originating action, even after its
  `ShouldBeUnique` window has expired, never creates a second
  `notifications` or `notification_deliveries` row, because the durable
  `notification_candidates` row is already terminal; (18) a Photo that
  finalizes into a contribution batch after that batch's candidate has
  already gone terminal produces its own standalone notification rather
  than being dropped or retroactively edited into the earlier one; (19) a
  notification whose subject has become inaccessible since creation
  renders as absent at read time; (20) revoking a Guest's `EventAdmission`
  between candidate creation and delivery prevents delivery; (21)
  deleting a Family Space leaves zero rows in all six new tables; (22) a
  Person merge leaves no `family_activities` or `notifications` row
  referencing the absorbed Person, and collapses any resulting
  natural-key collision to the newer row; (23) each of the five newly
  required `UNIQUE(id, family_space_id)` constraints (`albums`, `events`,
  `photos`, `photo_stories`, `photo_comments`) exists and a cross-Family-
  Space composite foreign key reference is structurally rejected by
  Postgres, not merely by application logic; (24) the same actor
  contributing the same `upload_batch_id` to the same Album produces
  exactly one grouped notification candidate per recipient; (25) the same
  actor contributing the same `upload_batch_id` to two different Albums
  produces two independent candidates, each reflecting only its own
  Album's Photos; (26) the same `upload_batch_id` used by two different
  actors contributing to the same Album produces two independent
  candidates, one per actor; (27) two Albums linked to two different
  Events never converge into one notification merely because they share
  an `upload_batch_id`; (28) a contribution group's convergence query
  never includes a Photo whose Album, actor, or batch id differs from the
  group's own tuple, even when one of the three matches; (29) a retry or
  redelivery of work for the same full logical contribution group
  remains idempotent and never creates a duplicate `contribution_groups`
  row or a duplicate notification; (30) a Photo finalizing after its
  contribution group's candidate has gone terminal produces its own
  separate, honest notification rather than being dropped or merged into
  the earlier one; (31) no notification text, Photo content, or Person
  name appears in any trace, span attribute, or metric label.

## Review triggers

- **When Phase 13 (Export, Portability, Backup and Recovery) is scoped**:
  confirm whether `family_activities`/`notifications` participate in
  personal/family export, and whether their derived, non-authoritative
  nature means they can be safely excluded or trivially regenerated.
- **When Phase 14 (Product UI/UX) is scoped**: design final homepage/
  notification visual treatment, empty-state copy and illustration, and
  any richer in-app notification presentation.
- **If a genuine relationship-interest or "follow" product need emerges**:
  scope it as a separate, deliberate capability — this ADR's recipient
  boundaries are a considered exclusion, not an oversight to quietly
  extend later.
- **If the Phase 12 subject vocabulary grows materially** (more than a
  handful of activity/notification subject types): revisit the typed
  nullable-column model in favour of a normalized child table; nothing in
  this ADR depends on the column approach staying fixed at this size.
- **If real usage shows `family_activities`' `photo_ids` JSON array
  becoming a performance or correctness liability at scale**: revisit
  normalizing it into a join table; nothing in this ADR depends on it
  staying a JSON column specifically.
- **If the contribution-batch debounce delay proves too short or too
  long in practice**: it is implementation detail (§ Deferred concerns),
  adjustable without changing the convergence model itself.
- **When the separate observability completion work (§ Context) lands**:
  confirm the new notification queue boundaries this ADR introduces,
  including the debounced delivery job, are covered by it.

## Deferred concerns

- Exact physical schema/column types/index definitions and the precise
  `CHECK` constraint expressions for all six new tables — the shape is
  fixed, the syntax is not.
- The exact contribution-batch debounce delay — a short, fixed value in
  the range of tens of seconds to a few minutes is expected to be
  sufficient; the precise number is implementation detail, not a product
  commitment.
- Exact in-app notification UI treatment beyond functional correctness —
  Phase 14.
- Exact retention window for read/old notifications and resolved
  `notification_candidates` rows — a lightweight, adjustable default is
  sufficient; not a correctness question this ADR needs to fix a number
  for.
- Whether `notification_deliveries.channel` ever needs a value beyond
  `mail` (a future push channel) — no requirement exists today; the enum
  is extensible without a redesign if one emerges.
- Whether `family_activities`/`notifications` ever inform a future
  relationship-interest/"follow" capability — explicitly not decided
  here (see Review triggers).

## Resolved decisions

1. **Four concepts, four architectures** — domain mutation (existing),
   family activity (`family_activities`, new), memory (fully derived,
   never persisted), notification candidate
   (`notification_candidates`/`notifications`/`notification_deliveries`,
   new) — never sharing one table or inferring one from another.
2. **Homepage model** — bounded, purpose-labelled mixed sections, never
   one endless feed or one blended ranking score.
3. **`family_activities` writes** — one row per real mutation, always;
   presentation grouping is a separate, read-time computation over an
   explicit logical operation identity, never a fictional batch
   transaction.
4. **Why not `AuditEvent`** — no `SELECT` RLS policy exists on
   `audit_events`, making it structurally unreadable by the application,
   in addition to every semantic mismatch already named.
5. **Presentation grouping** — keyed on `contribution_batch_id`, sourced
   from the already-existing, already-client-suppliable
   `media_uploads.upload_batch_id`, recomputed fresh at every homepage
   render; `NULL`-batch rows never group with each other; no rolling
   time window.
6. **Activity taxonomy** — Photos added, Story added, human-confirmed
   identity, Album created, Event created; comments, reactions, machine
   suggestions, and admin/security actions excluded.
7. **Actor identity** — `User` resolved to `Person` via
   `PersonAccountLink` where it exists; never invented otherwise.
8. **`family_activities` subject model** — small, closed, nullable typed
   FK columns plus a `CHECK` constraint; never an untyped polymorphic
   `target_type`/`target_id` pair.
9. **Supporting tenant-composite constraints** — `albums`, `events`,
   `photos`, `photo_stories`, and `photo_comments` each require an
   additive `UNIQUE(id, family_space_id)` constraint, added before the
   Phase 12 tables that reference them, matching the pattern already
   used for `people`/`media_uploads` in Phase 10.
10. **Date-based memories** — `exact` alone qualifies for true "On this
    day"; `month` qualifies only for broader named-period wording;
    `year`/`decade`/`approximate` broader still; `unknown` never
    resurfaces; no invented precision.
11. **Recently-added vs. historically-recent** — always two distinct
    columns/facts, never collapsed or substituted for each other.
12. **Person-centred memories and Discovery** — fully derived, respecting
    `do_not_resurface`; Discovery reused directly from ADR-0013 §16,
    never reinvented.
13. **Stories** — reuse the `PhotoStorySearchSummary` shape; never
    flattened text.
14. **Comments/reactions** — excluded from homepage activity entirely;
    remain a notification-only concern.
15. **Identity as activity** — human-confirmed only, never machine
    suggestion or confidence.
16. **`do_not_resurface`** — Photo-level only for v1, `PhotoPolicy::
    update()`'s existing authority, memory-resurfacing effect only —
    never authorization, Search, Discovery, or historical fact;
    introduced in the earliest stage that queries it, never after.
17. **Homepage authorization** — current, viewer-visible-subset counts
    and content everywhere, reusing ADR-0013 §9's entry points.
18. **Role-based homepage** — Owner/Administrator/Member full homepage;
    Contributor scoped; Guest gets an Event-centric landing, not the
    homepage.
19. **Personalisation** — light; the shared authorized archive first, no
    recommendation engine.
20. **Empty states** — genuine archive entry points, never a dead end.
21. **Notification architecture** — the existing
    `SendEventContributionNotifications` pattern generalised across
    categories via a durable candidate record, not replaced.
22. **Notification taxonomy** — comments/conversation, Album/Event
    contributions, Stories, own-identity confirmation; reactions,
    machine suggestions, and admin/security activity excluded.
23. **Notification recipients** — comments: Album creator/Photo creator/
    prior `PhotoComment` authors in that exact `(photo_id, album_id)`
    conversation only, never depicted-People, their relatives, or
    `PhotoStory` authors; contributions: the existing Event logic
    generalised, or Album creator; Stories: Photo creator plus the
    recipient's own confirmed presence; identity: the recipient's own
    linked Person only, never a relative's.
24. **Notification preferences** — four categories × two channels,
    in-app on by default everywhere, email on by default only for
    comment and identity.
25. **Notification identity** — `(family_space_id, recipient_user_id,
    category, source_action_id)`, sourced from a real, stable domain id
    per category, never the target alone and never a timestamp; for the
    contribution category, `source_action_id` identifies the full
    logical contribution group (actor + `upload_batch_id` + Album),
    materialized in a small `contribution_groups` table, never the bare
    client-supplied `upload_batch_id` alone.
26. **Durable candidate record** — `notification_candidates` is the
    system's actual idempotency anchor, minimal (one table, two outcome
    columns), never the in-app notification itself and never a general
    event log; `ShouldBeUnique` remains a queue-level optimisation only.
27. **Per-channel independence** — each enabled channel produces its own
    outcome, decided once per candidate and recorded durably; a disabled
    channel produces no row for that candidate, ever, with no retroactive
    effect from re-enabling.
28. **Contribution-batch convergence** — staggered Photo finalizations
    sharing the same actor+batch+Album group converge on one candidate
    via a short, fixed, explicitly-scoped debounce and a live re-query,
    scoped to that full tuple, at delivery time; a different Album or a
    different actor sharing the same batch id never converges into the
    same group; a Photo arriving after its group's candidate is terminal
    produces its own standalone notification rather than being lost or
    retroactively merged.
29. **`notifications`/`notification_deliveries`** — two tables with
    typed FK columns and a `CHECK` constraint, never an untyped
    polymorphic pair; product record and delivery-attempt history kept
    separate.
30. **Notification authorization** — revalidated at candidate creation,
    before delivery, and at read time, independent of and never
    conflated with the once-per-candidate preference decision;
    inaccessible content renders as absent.
31. **Role-based notifications** — Contributor scoped; Guest
    Event-scoped while admission remains valid.
32. **Person-merge/deletion/teardown** — typed FK repointing (simplified
    relative to authoritative tables, since neither table is
    authoritative family truth); explicit inclusion in Family Space
    teardown for all six new tables, unlike `audit_events`.
33. **Observability** — compliance with existing conventions only; no
    new architecture, no new stage, no design dependency on the separate
    completion work already underway.
34. **Stage ownership** — `FPA-P12-S02` adds the `albums`/`events`/
    `photos`/`photo_stories` supporting constraints before creating
    `family_activities`; `S03`-`S05` implement the rest of the homepage
    side, with `do_not_resurface` introduced in `S03`; `FPA-P12-S06`
    adds the `photo_comments` supporting constraint before implementing
    the entire notification architecture atomically; `FPA-P13-S01`'s
    dependency is corrected to `FPA-P12-S06`.
