# ADR-0021: First-Class Interactive Stories

- Status: Accepted
- Date: 2026-09-12
- Decision owners: David
- Related stages: FPA-P14-S01 (accepting this ADR completes that stage),
  implemented by FPA-P14-S02, FPA-P14-S03, FPA-P14-S04, FPA-P14-S05

## Context

Phase 14 product prototyping exposed a genuine architectural mismatch: the
live `PhotoStory` model ties a family memory to exactly one Photo, but
family memories are not always about a Photo. "Dad once drove all the way
back from Morecambe because Mum thought she had left the iron on" is a
memory about William, not about any single photograph. "This was the box
of photographs Margaret kept underneath the stairs" is a memory about an
Album. "Michelle discovered prosecco rather enthusiastically at William's
80th Birthday" is a memory about an Event. The settled product principle
this ADR serves is that family conversation should feel alive enough that
people come back because something genuinely happened — not through
streaks, view notifications, reaction spam, algorithmic nudging, or any
other engagement mechanic. This is a bounded architecture correction
discovered during Phase 14 prototyping, not a reopening of any completed
phase.

**This reconciliation also settles, as in-scope now rather than deferred,
two things the original draft had left open**: constrained rich text and
typed @Person mentions are a current-version requirement for Person
biography, Album description, and Event description, not merely
infrastructure this ADR happens to make reusable later (§11); and
existing `PhotoComment` gains the identical typed-mention capability
Story comments receive, without changing its Album-scoped conversation
semantics (§23). It further reconciles the originally-proposed new
`story_comment` notification category against the completed Phase 12
taxonomy, and settles on reusing the existing `comment` category for both
conversation kinds (§31), rather than introducing a second
user-configurable preference the product has no genuine reason to keep
separate.

**This reconciliation also closes three further gaps found during
implementation-readiness review**: the original mention model could not
deterministically resolve an embedded mention back to its current Person
after a merge (fixed by a stable per-mention identifier, §12); the
original stage plan removed legacy `PhotoStory` before every live consumer
had moved off it, and made mention documents writable before their
extraction tables existed (fixed by a four-stage schema → consumer-migration
→ removal → extension sequence, Implementation notes); and Story-comment
restoration was not deterministic with respect to comments independently
deleted before or during the Story's own deletion (fixed by an explicit
deletion-provenance marker, §34).

**The live `PhotoStory` schema, confirmed directly**
(`apps/api/database/migrations/2026_08_24_030000_create_photo_conversations.php`):
`id, family_space_id, photo_id, author_id (nullable, nullOnDelete), body
(text), edited_at, timestamps, soft-deletes`, with a companion
`photo_story_revisions` table (`id, family_space_id, photo_story_id,
editor_id, revision, body`, no soft-deletes, `UNIQUE(photo_story_id,
revision)`). `PhotoCommentPolicy` and `PhotoStoryPolicy` both define only
`update`/`delete` (author, or a manages-members role for delete) — neither
has a `view` method, confirming both a Story's and a Comment's visibility
have always been entirely derived from their owning Photo, never
independent. Story creation itself is gated today by
`PhotoPolicy::authorStory()`, which reuses `PhotoPolicy::interact()`
(view-authorized and, for Contributor, requires Album-contribution
access) with Guests excluded entirely.

**Search, family activity, and notifications already depend on
`photo_stories` at the schema level, and each dependency must be
repointed, not merely conceptually updated.** Direct inspection confirms
three separate composite foreign keys currently target
`photo_stories(id, family_space_id)`:
`family_activities.subject_story_id`
(`apps/api/database/migrations/2026_09_09_000000_create_family_activities.php:38-39`,
`cascadeOnDelete()`), `notifications.story_id`, and
`notification_deliveries.story_id`
(both in `apps/api/database/migrations/2026_09_09_020000_create_family_notifications.php`,
each `cascadeOnDelete()` via a `notifications_story_family_foreign`/
`notification_deliveries_story_family_foreign` constraint). `photo_stories`
also carries a live, generated `search_vector` column
(`apps/api/database/migrations/2026_09_08_000000_add_search_metadata.php:38`)
already indexed and part of the searchable-tables list. Every one of
these must be migrated onto the new first-class Story table in the same
implementation stage that creates it — this ADR is not additive to
`photo_stories`, it replaces it.

**`NotificationManager`'s existing `comment` category is already
structured around a `typedSubject()` dispatch and a per-`(family_space_id,
user_id, category, channel)` preference lookup**
(`apps/api/app/Services/NotificationManager.php:134-221`) — confirmed
directly that `category` is a single enum value, `typedSubject()` builds
its typed column set from whatever keys are present in the `$subject`
array passed to it, and `NotificationPreference` is looked up once per
`(category, channel)` pair, never per subject shape. This means the same
`comment` category can honestly represent two different typed-column
shapes (a Photo comment's `(photo_id, album_id, comment_id)` and a Story
comment's `(story_id, story_comment_id)`) without any new enum case,
migration, or preference row — confirmed as the mechanism §31 relies on.

**The supporting tenant-composite constraints this ADR's typed subject
columns need mostly already exist — verified, not assumed.** Phase 12's
own reconciliation already added `UNIQUE(id, family_space_id)` to
`albums`, `events`, and `photos`
(`apps/api/database/migrations/2026_09_09_000000_create_family_activities.php:12-15`),
and `people` has carried the equivalent constraint since Phase 10. Only
the new `stories` table itself needs a fresh `stories_id_family_space_unique`
constraint, created alongside it, to support the three FK repoints named
above plus this ADR's own new mention/comment tables.

**No rich-text or mention library exists anywhere in this stack.**
Confirmed by inspecting `apps/api/composer.json` and `apps/web/package.json`
directly — no ProseMirror, Tiptap, Slate, Quill, Markdown, or HTML-sanitiser
dependency of any kind. This ADR therefore defines a small, first-party,
versioned document schema rather than adopting an external editor's
native format.

**`saved_search_people`'s Person-merge pattern is the direct precedent
this ADR's mention model follows, applied uniformly across every mention
consumer this ADR introduces.** ADR-0013 §15/§18 already established, and
this project has since applied repeatedly, that a Person reference
embedded inside a JSON blob is unsafe for merge reconciliation — "Person
references are persisted only in `saved_search_people`, never inside the
`filters` JSON... so Person-merge reconciliation is a plain relational
`UPDATE`/`DELETE`, never a JSON-manipulation query." Typed @Person
mentions inside a Story body, a Story comment, a Photo comment, a Person
biography, an Album description, or an Event description all face the
identical problem and all receive the identical solution (§12-§13).

**`PersonMergeManager::validatePair()` already, and deliberately,
prohibits an active chained merge — confirmed directly**
(`apps/api/app/Services/PersonMergeManager.php:252-272`): given an active
`A → B` merge, attempting `B → C` is rejected, because `validatePair()`
checks for an existing `PersonMerge` row with `survivor_person_id` equal
to the proposed absorbed Person in `Active`/`ManualCorrectionRequired`
status and fails with "Reverse this Person's existing merges before
absorbing them into another Person." Only once `A → B` is reversed (or
otherwise resolved) can `B` be absorbed into another Person. This ADR
integrates with that existing boundary exactly as it stands; it does not
extend `PersonMergeManager` to support or reason about active chained
merges (§13).

**Standing constraints this ADR inherits and does not revisit**: Family
Space tenancy and RLS (ADR-0005); User ≠ Person and `PersonAccountLink`
(ADR-0006); Person-merge's `captureState()`/`restoreState()` transaction
and its standing obligation that "every future Person-referencing domain
... must explicitly integrate with merge behaviour when it is introduced"
(ADR-0006 §12); Photo/Album/Event authorization and Contributor/Guest
boundaries (ADR-0008/0009/0013); the durable `notification_candidates`
idempotency model and per-channel preference independence (ADR-0014);
Family Space teardown's explicit per-table delete discipline. None of
these is reopened; this ADR only adds what a first-class Story and its
shared infrastructure genuinely require of each.

## Decision

### 1. Scope

This ADR replaces the Photo-scoped `PhotoStory` model with a first-class
`Story` object whose primary subject may be exactly one Person, Album,
Event, or Photo; introduces Story comments as a distinct conversational
model from Photo comments; introduces a small, versioned, constrained
rich-text document schema and a typed @Person mention model; and adopts
that shared schema and mention model, in this same version, for Person
biography, Album description, Event description, and existing Photo
comments — without converting any of them into Stories, and without
changing `PhotoComment`'s `(photo_id, album_id)` conversation semantics
or table identity. It reconciles Search, family activity, and
notifications onto the new model, resolving the notification-category
question by reusing the existing `comment` category for both conversation
kinds rather than introducing a new one. It does not introduce reactions
on Stories, does not introduce public/external Story sharing, and does
not change Event admission/invitation semantics.

### 2. Story becomes a first-class family-memory object

A Story is no longer a property of a Photo. It is its own object with
exactly one primary subject, drawn from a closed set: Person, Album,
Event, or Photo. The primary subject gives the Story its canonical home
and its authorization boundary (§24). A Story never has more than one
primary subject, and never none.

### 3. Canonical Story schema and the exactly-one-subject invariant

```text
stories
    id                  ulid, primary key
    family_space_id     tenant scope, RLS
    author_id           nullable, FK to users, nullOnDelete
                        (matching PhotoStory.author_id exactly)
    person_id           nullable, typed FK, tenant-consistent composite
    album_id            nullable, typed FK, tenant-consistent composite
    event_id            nullable, typed FK, tenant-consistent composite
    photo_id            nullable, typed FK, tenant-consistent composite
    body                jsonb — the canonical structured document (§6)
    body_plain_text     text — application-maintained plain-text
                        projection of body, written in the same
                        transaction as body (§6)
    search_vector       tsvector, GENERATED ALWAYS AS
                        (to_tsvector('english', body_plain_text)) STORED
    edited_at           nullable
    created_at / updated_at
    deleted_at            soft delete (§34)
    deletion_operation_id nullable ULID — identifies the specific Story
                          deletion that is currently active, if any (§34);
                          never a timestamp
```

A `CHECK` constraint enforces that **exactly one** of `person_id`,
`album_id`, `event_id`, `photo_id` is non-null — the same discipline
ADR-0014 §8 already established for `family_activities`' typed subject
columns, applied here for the identical reason: PostgreSQL cannot enforce
referential integrity across an untyped `subject_type`/`subject_id` pair,
and this project does not use one anywhere.

### 4. Supporting tenant-composite constraints

`albums`, `events`, and `photos` already carry `UNIQUE(id, family_space_id)`
(added in Phase 12's `FPA-P12-S02`), and `people` has carried the
equivalent since Phase 10 — confirmed directly (§ Context). **No new
constraint is required on any of these four tables.** The new `stories`
table requires its own additive `stories_id_family_space_unique`
constraint, created in the same migration that creates the table, before
any dependent table's composite FK is created against it — the same
ordering discipline (parent constraint → dependent table → composite FK)
established in every prior ADR that introduced tenant-composite foreign
keys.

### 5. Story vs. biography, description, caption, and comment

Kept explicitly distinct, none of these becomes a Story, and adopting
shared rich-text/mention infrastructure (§11) never blurs this boundary:

- **Person biography** — a canonical-ish descriptive summary of the
  Person (`people.biography`). Not a Story.
- **Album description** — describes the Album itself
  (`albums.description`). Not a Story.
- **Event description** — describes the Event itself
  (`events.description`). Not a Story.
- **Photo caption/description** — describes the Photo
  (`photos.caption`/`photos.description`, unchanged, not adopting rich
  text in this ADR — no product requirement asked for it). Not a Story.
- **Story** — a subjective, authored family memory or narrative about
  one Person, Album, Event, or Photo, retaining author and revision
  provenance (§19).
- **Comment** — conversation around content; a Photo comment remains
  Album-contextual conversation about a Photo (ADR-0010, table identity
  and `(photo_id, album_id)` scoping unchanged), and a Story comment
  (§21) is conversation about the authored memory itself. Story and
  Comment remain two models; Photo comment and Story comment remain two
  distinct tables sharing an infrastructure, never collapsed into one.

### 6. Story body: canonical storage format

The canonical representation is a small, first-party, versioned
structured document, stored as `jsonb` — never raw HTML, never Markdown
source text. The document is an ordered array of block nodes; each block
node is one of `paragraph`, `heading_2`, `heading_3`, or `horizontal_rule`;
a `paragraph`/`heading_2`/`heading_3` node contains an ordered array of
inline nodes, each either a `text` run (with optional `bold`/`italic`
marks) or a `mention` node (§12). The top-level document object carries
its own `schema_version` key — following the same self-describing
convention ADR-0013 §15 already uses for saved-search filter JSON. A
companion `_plain_text` projection (`body_plain_text` on `stories`, and
the equivalent named column on every other adopting table, §11/§23) is
an application-maintained plain-text projection, written in the same
transaction as the structured document whenever it changes, used solely
to drive each table's generated `search_vector` column and as a plain
fallback. This is a deliberate, named exception to this project's general
preference for fully DB-generated columns — analogous to
`family_activities.photo_ids` being named as ADR-0014's own deliberate
JSON exception — accepted because Postgres's `GENERATED ALWAYS AS` cannot
execute the document-walking logic needed to derive plain text from
`jsonb` directly.

### 7. Rich-text schema versioning

`schema_version` (embedded in the document, §6) is a small integer,
starting at `1`, shared across every adopting table (§11/§23) — a future
schema revision is additive and interpreted by version, dispatched on
explicitly by validation and rendering, never assumed to be the latest
shape. No migration of historical documents to a new schema version is
required merely because a new version is introduced.

### 8. Sanitisation and validation boundary

Every structured document, in every adopting table, is validated against
its own closed node/mark vocabulary (§6, §11) at write time, on the
server, before persistence — never trusting client-supplied structure.
Validation rejects: any node type outside the closed set for that field
(no arbitrary HTML, no tables, no font/colour/size attributes, no
unrestricted pasted styling); and any document exceeding a bounded
size/depth limit. There is no HTML-sanitisation boundary to define,
because HTML is never accepted or stored — the closed node schema is the
sanitisation boundary.

**Mention validation is asymmetric between new and continuing occurrences
— correcting a gap in a prior reconciliation, which stated every mention's
`person_id` must resolve to a current Person, a rule that is wrong for a
continuing mention after a Person merge (§12-§13):**

- **A new mention occurrence** (no `mention_id`, or an unrecognized one —
  §12) is validated against its *submitted* `person_id`: that Person must
  currently exist and be valid for this Family Space, and must pass
  mention-autocomplete authorization (§17). The server assigns the
  `mention_id` and the extraction row is created from this validated,
  current `person_id`.
- **A continuing mention occurrence** (a submitted `mention_id` matching
  an existing extraction row for this owning document, §12) is validated
  by that extraction row's own existence and ownership alone — **its
  embedded historical `person_id` is never required to resolve to the
  current canonical Person**, and is never re-validated against current
  authorization on every unrelated save. Canonical Person identity comes
  from the extraction row, not from the document; the embedded
  `person_id`/`label` remain fallback/historical payload only. This is
  what makes the valid post-merge state — a document whose embedded
  `mention_id = M1` still carries historical `person_id = A` while `M1`'s
  extraction row now reads `B` — pass validation: `M1` belongs to this
  document and resolves canonically through its extraction row; `A` is
  never required to still be the current canonical Person.
- **Duplicate recognized `mention_id`s are rejected.** Within one
  submitted document, each `mention_id` that matches an existing
  extraction row for this owning document may appear at most once. A
  document submitting the same recognized `mention_id` on two separate
  mention nodes is structurally invalid — one extraction row cannot back
  two embedded occurrences — and the save is rejected before any
  extraction-table write occurs; the existing extraction row for that
  `mention_id` is left completely untouched by a rejected save. This
  applies only to *recognized* `mention_id`s: two mention nodes that are
  both new (no `mention_id`, or both carrying the same unrecognized,
  unowned id) are not a duplicate-recognized-id violation — each is
  independently treated as a new occurrence (§12) and assigned its own,
  distinct, server-generated `mention_id`, so the persisted canonical
  document that results is guaranteed to contain no duplicate
  `mention_id`s regardless of what the client submitted.

### 9. Rendering boundary

Rendering walks a validated document directly and produces presentation
output strictly from its closed node vocabulary — never re-interpreting
stored content as HTML or Markdown. A `mention` node resolves via its
`mention_id` to the corresponding extraction-table row (§12) to find the
Person it *currently* names — never via its own embedded `person_id` —
and renders as a navigable reference to that current, live Person wherever
the viewer is currently authorized to see that Person in this context; if
the viewer lacks that authorization, or the extraction row cannot be found
(only possible once the owning document has been purged, §14), it renders
as inert text using the mention's embedded `label` snapshot, never as a
broken link and never as a way to newly disclose a Person's existence.

### 10. Shared rich-text/mention infrastructure: one schema, several consumers

The document schema (§6-§9) and the mention model (§12-§17) are one
piece of reusable infrastructure, consumed by six content surfaces in this
same version: Story body (full vocabulary — paragraphs, headings, horizontal
rule, bold/italic, mentions); Person biography, Album description, and
Event description (§11 — the same full vocabulary, since these are prose
fields with no product reason to restrict them further than Story body);
and Story comments and Photo comments (§21, §23 — a deliberately smaller
vocabulary: paragraph and mention only, no headings, no marks, no
horizontal rule, matching "comments remain lightweight conversation, not
rich Story documents"). Each consumer gets its own companion
mention-extraction table (§12), its own `_plain_text` projection and
generated `search_vector` where search applies, and its own migration —
the schema and validation/rendering machinery are shared; the data is
not commingled between tables.

### 11. Person biography, Album description, and Event description: adoption is in scope now

**Corrected in this reconciliation: this is a current-version requirement,
not a deferred possibility.** `people.biography`, `albums.description`,
and `events.description` each convert from plain text to the shared
structured document format (§6, full vocabulary), each gaining:

- a companion `_plain_text` projection and generated `search_vector`,
  reusing whatever search integration already exists for that field
  today, never regressing it;
- a companion mention-extraction table — `person_biography_mentions`,
  `album_description_mentions`, `event_description_mentions` — each
  shaped exactly like `story_person_mentions` (§12): `id, family_space_id,
  <owner>_id` typed FK, `mention_id`, `person_id` typed FK,
  `historical_label_snapshot`, `UNIQUE(<owner>_id, mention_id)` — never
  `UNIQUE(<owner>_id, person_id)`, so two distinct mention occurrences
  resolving to the same Person remain valid, unambiguous, non-colliding
  rows, exactly as §12 establishes for every adopting surface;
- a one-time migration wrapping existing plain-text content into a
  single-paragraph document at `schema_version: 1`, mechanically, with no
  content loss — the same pattern §20 already establishes for
  `PhotoStory`.

None of these three fields becomes a Story (§5) — a biography remains a
canonical-ish descriptive summary of a Person, not an authored memory,
and the same holds for the two description fields. Edit authority for
each remains exactly what it already is today (the existing
Person/Album/Event update policies, unchanged) — adopting rich text does
not change who may edit a biography or description, only what format the
edit is stored in.

### 12. Typed @Person mentions: persistence model

**The smallest explicit model that survives Person merge, and — correcting
a gap in the original draft (§ Context) — the smallest model that lets a
renderer deterministically resolve every embedded mention to its current
canonical Person after that merge.** A mention keyed only by `person_id`
cannot do this: the extraction row's `person_id` gets repointed to the
survivor, but the embedded document still carries the absorbed Person's
id, with no way back from "this embedded node" to "that extraction row."
The fix is a stable, per-mention identifier that ties the two together,
independent of which Person the mention currently names. A mention is
represented in two places:

- **Embedded mention nodes inside the structured document** carry three
  fields:

  ```json
  {
    "type": "mention",
    "mention_id": "<ulid, assigned once by the server, stable for the
                   lifetime of this mention occurrence>",
    "person_id": "<Person id at the moment this node was last saved>",
    "label": "William Mercer"
  }
  ```

  `mention_id` is assigned by the server the first time a mention
  occurrence is created (see the save/update transaction below) and is
  **stable for the lifetime of that occurrence** — every ordinary edit
  that leaves the occurrence in place (including one that resubmits a
  stale, pre-merge `person_id`, §8/§13) preserves it unchanged. It is
  **never retargeted in place**: if a user intentionally changes which
  Person a mention refers to, that occurrence is removed (its `mention_id`
  and extraction row retired) and a new occurrence is created with its own
  fresh `mention_id` and extraction row pointing at the newly-chosen
  Person (§12, "Intentional retargeting", below) — there is no operation
  that mutates an existing `mention_id`'s target. `person_id` and `label`
  remain historical/structural material only — a
  point-in-time snapshot, useful as a fallback and for round-tripping the
  document a user authored — and are **never** independently trusted for
  authorization, merge reconciliation, search, notifications, or live
  rendering. Neither is ever rewritten merely to reflect a later Person
  merge; only a genuine content edit changes them.
- **A companion extraction table per adopting field** —
  `story_person_mentions`, `story_comment_person_mentions`,
  `photo_comment_person_mentions` (§23),
  `person_biography_mentions`/`album_description_mentions`/
  `event_description_mentions` (§11) — is the canonical, queryable,
  merge-safe, *currently correct* record of who a mention now points to,
  keyed on the mention occurrence rather than on the Person it currently
  names:

```text
{table}_person_mentions
    id                        ulid, primary key
    family_space_id           tenant scope, RLS
    {owner}_id                typed FK, tenant-consistent composite,
                               cascadeOnDelete
    mention_id                char(26) — the embedded node's own
                               mention_id, copied verbatim
    person_id                 typed FK, tenant-consistent composite,
                               cascadeOnDelete — the mention's *current*
                               canonical target
    historical_label_snapshot text — a copy of the document's label at
                               the moment this row was last written
    created_at / updated_at
```

`UNIQUE({owner}_id, mention_id)` — one row per mention *occurrence*, not
per Person, so two separate mentions of the same Person inside one
document are two distinct, unambiguous rows.

**Save/update transaction**, run whenever a document is created or edited,
before persistence: (1) validate the incoming document (§8); (2) partition
its mention nodes into three groups by comparing their `mention_id`
against this owning document's *current* extraction rows —
**continuing** (a submitted `mention_id` matches an existing extraction
row for this same owning document), **new** (no `mention_id` submitted,
or a submitted `mention_id` that does not match any existing extraction
row for this owning document — treated identically, since an unrecognized
`mention_id` cannot be trusted as a real continuation and is never
replayed from elsewhere), and **removed** (an existing extraction row
whose `mention_id` no longer appears anywhere in the submitted document);
(3) for a **continuing** mention, do nothing to its extraction row — its
`person_id`/`historical_label_snapshot` are left exactly as they stand,
regardless of what `person_id`/`label` the submitted node carries; (4) for
a **new** mention, assign a fresh `mention_id` (overwriting any
unrecognized one the client sent), validate the submitted Person against
current authorization (§17), and insert a new extraction row from it; (5)
for a **removed** mention, delete its extraction row; (6) within the same
transaction as writing `body`/`body_plain_text`, persist the document with
every mention node's `mention_id` now fixed (assigned in step 4 where
applicable). **A continuing mention's extraction row is never written by
this transaction — only inserted (new) or deleted (removed).** This is
what makes an ordinary, unrelated edit merge-safe by construction: an
editor that resubmits a mention node carrying a stale, pre-merge
`person_id` (because the client never re-fetches historical payloads it
isn't displaying as live data) cannot repoint that mention's extraction
row back to the pre-merge Person, and cannot be rejected for "no longer
matching" the canonical Person either, because the submitted `person_id`
is never consulted for a continuing mention at all.

**Intentional retargeting — changing which Person an authored mention
refers to — is never expressed as an in-place field change on a
continuing `mention_id`.** `mention_id` identifies one authored mention
*occurrence* with a stable identity; changing who it refers to is, by
definition, a different occurrence. The editing surface expresses a
retarget as: remove the existing mention node (its `mention_id` then
falls out of the submitted document and is treated as **removed**, step
5 above — its extraction row is deleted), and insert a new mention node
with no `mention_id` for the newly-chosen Person (treated as **new**, step
4 above — a fresh `mention_id` and extraction row are created). No
separate "retarget" API or database operation is introduced; the ordinary
create/remove halves of the save/update transaction already compose into
exactly this, and — because a continuing mention's `person_id` is never
read from the submission (above) — there is no ambiguous middle path where
an ordinary save could silently retarget an existing occurrence.

This is the same delete-then-reinsert-to-match discipline this project
already uses for merge-safe relational extraction (§ Context), now keyed
on `mention_id` rather than `person_id` and now precise about which of the
three groups above is added, removed, or left untouched. It structurally
guarantees a document is never persisted with a mention lacking a matching
extraction row, nor an extraction row without a matching embedded mention,
and — the correction in this reconciliation — that no ordinary edit can
ever undo a Person merge's repoint. Extraction-table creation and this
transaction are introduced together, in the same implementation stage as
the document schema itself (§
Implementation notes), so there is never a sub-state where one exists
without the other.

**Rendering, search, notifications, and merge reconciliation all resolve
"who is mentioned here" from the extraction table** — `mention_id` → its
row → that row's *current* `person_id` — **never by re-parsing the
document or trusting its embedded `person_id`.**

### 13. Mentions and Person merge

Every mention extraction table's `person_id` column is repointed from the
absorbed Person to the survivor as part of `PersonMergeManager`'s
existing capture/reconcile transaction, in the same stage each table is
introduced — the same standing obligation ADR-0006 §12 already imposed on
every prior Person-referencing table. **Because §12's extraction tables
are keyed on `UNIQUE({owner}_id, mention_id)` rather than `UNIQUE({owner}_id,
person_id)`, this repoint can never collide**: two mention rows that end
up pointing at the same survivor `person_id` after a merge remain two
perfectly valid rows, distinguished by their different `mention_id`s —
exactly the "multiple mentions of the same Person in one document" case.
This is a deliberate simplification relative to `saved_search_people`'s
own pattern: that table's uniqueness genuinely includes `person_id`, so it
needs the collision-and-guarded-reversal handling ADR-0013 established;
these mention tables do not, because identity here is the mention
occurrence, not the `(owner, Person)` pair. Reversing a merge simply
reverts every repointed row's `person_id` back to the absorbed Person's
id, read from the merge snapshot — a plain `UPDATE`, no redundant-row
deletion or restoration is ever required for mentions.

The document's embedded JSON is **never rewritten** by a Person merge —
its `person_id`/`label` remain exactly as authored, honestly historical.
The renderer's `mention_id` → extraction-row → survivor resolution (§12)
is what makes the mention navigate to the correct, live Person after
merge, while the embedded `label` remains available as the historical
fallback text if the extraction row is ever unavailable.

**A later, unrelated edit to the same document cannot undo this
repoint.** Because the save/update transaction (§12) never reads a
continuing mention's embedded `person_id` as authoritative, resubmitting a
document that still carries the pre-merge `person_id` on an already-merged
mention — the ordinary case, since nothing prompts a client to refresh
historical payloads it isn't displaying as live data — leaves that
mention's extraction row exactly as the merge left it. This holds for
every one of the six adopting content surfaces (§10) identically.

**A Story whose *primary subject* is the absorbed Person**
(`stories.person_id`) is repointed the same way
`family_activities.subject_person_id` already is — a plain FK update, no
collision possible, since a Story has exactly one primary subject by
construction (§3). This is unrelated to, and simpler than, mention
repointing.

**This ADR integrates with the existing supported Person-merge model; it
does not expand it.** Typed Story/Story-comment/Photo-comment/biography/
Album-description/Event-description mentions remain correct across every
Person merge and reversal operation the existing `PersonMergeManager`
permits — no more, no less. Concretely: `A → B` may occur, and every
mention extraction row pointing at `A` repoints to `B` through that merge
operation; reversing it restores them from that operation's own
capture/reconcile snapshot; **while `A → B` remains active,
`PersonMergeManager::validatePair()` continues to reject `B → C`** exactly
as it does today (§ Context) — this ADR introduces no mechanism that
permits, anticipates, or reasons about an active chained merge, and no
ordered chained-merge reversal machinery is added. Only once the `A → B`
merge is reversed or otherwise resolved may `B` (or `A`, restored) be
absorbed into another Person under the existing rules, and mentions
repoint correctly for that subsequent, independent merge in exactly the
same way.

### 14. Mentions and deletion/revision semantics

Soft-deleting a Story or a comment does not delete its mention-extraction
rows — they remain queryable evidence of what a since-removed document
once referenced, consistent with the owning row itself being recoverable
via its own soft-delete state, and are removed only when the owning row
is eventually purged. A new Story revision (§19) that changes which
mentions are present triggers the same `mention_id`-keyed
delete-then-reinsert-to-match extraction described in §12, keeping the
extraction table synchronized with the *current* body, not a historical
revision's body; a mention's `mention_id` is preserved across revisions
for as long as the same mention occurrence continues to exist in the
edited document.

### 15. Mentions and search

Mention-extraction tables are not themselves searched directly — a
mentioned Person's name already appears in the owning document's
`_plain_text` projection (via the mention's embedded label) and is
therefore already reachable through that field's own `search_vector`
where one exists. Extraction tables exist for authorization-adjacent and
notification purposes (§17, §31), not as a second search index.

### 16. Mentions and notifications

An explicit @mention inside a **Story comment or a Photo comment** is a
notification recipient basis (§31) — resolved via the comment's own
mention-extraction table → `PersonAccountLink` → `User`, the same
resolution shape the existing `identity` notification category already
uses. A mention inside a Story body, a Person biography, an Album
description, or an Event description is deliberately **not** an
independent notification trigger in this ADR — only the Person-Story
default (§30) and comment mentions (§31) are; adding a body/description-
mention notification would risk exactly the "meaningful vs. noisy"
boundary the product principle in this ADR's Context exists to protect,
and no product requirement asked for it.

### 17. Mention autocomplete authorization

Mention autocomplete never becomes a hidden People-directory bypass, in
any authoring context this ADR introduces. Reusing the exact precedent
ADR-0013 §9/§17 and ADR-0015 §6 already established for Contributor/Guest
People-context bounding: for a Member/Administrator/Owner, autocomplete
is gated by the same public boundary Search already uses
(`Gate::denies('viewAny', Person::class)`); for a Contributor or Guest,
autocomplete is bounded to only Persons with an approved `PhotoPerson`
association on a Photo the requester is currently authorized to see —
never the full Person directory, and never derived from the relationship
graph. This single rule applies uniformly to Story-body mentions,
Story-comment mentions, Photo-comment mentions, and biography/description
mentions alike — one authorization boundary, not one per surface.

### 18. Derived display heading

No required Story title field is introduced — Story remains body-led. One
centralised derivation function, consumed identically by the homepage
feed, Search, Person pages, Album pages, Event pages, and notifications,
produces the display heading:

1. If the body's first block node is a `heading_2` or `heading_3`, its
   text content is the display heading.
2. Otherwise, a short, deterministic preview heading is derived from the
   opening plain text (`body_plain_text`), bounded to a fixed character
   length.
3. A Story short enough to fit within that bound in full is shown in
   full, without an unnecessary trailing ellipsis.
4. Formatting is stripped and every `mention` renders as its current,
   authorized Person name (or the embedded label fallback, §9) when
   deriving preview text — never a raw identifier.
5. This function lives in one place; no consuming surface implements its
   own truncation or heading logic.

### 19. Story revisions

The existing `PhotoStoryRevision` principle — durable, authored revision
history — is preserved and generalised:

```text
story_revisions
    id            ulid, primary key
    family_space_id
    story_id      typed FK, tenant-consistent composite, cascadeOnDelete
    editor_id     nullable, FK to users, nullOnDelete
    revision      unsigned integer
    body          jsonb — the complete canonical document for that
                  revision, not a diff
    created_at
```

`UNIQUE(story_id, revision)`, matching `photo_story_revisions`' existing
constraint shape exactly. Each revision stores the **complete** structured
document as it stood at that revision — no historical revision evidence
is discarded or summarised.

### 20. Migration of existing PhotoStory data

Each existing `photo_stories` row becomes exactly one `stories` row:
`photo_id` copied unchanged, `person_id`/`album_id`/`event_id` left
`NULL`, same `family_space_id`, same `author_id`, same `created_at`/
`updated_at`/`edited_at`/`deleted_at`. Because the legacy `body` is plain
text, each migrated row's `body` becomes a single `paragraph` block
containing one `text` run holding the legacy plain text verbatim,
`schema_version: 1`, with `body_plain_text` set to the same legacy text —
a lossless, mechanical wrap, not a reinterpretation. Every
`photo_story_revisions` row migrates identically into `story_revisions`,
preserving `revision` numbering and `editor_id` exactly. `family_activities.subject_story_id`,
`notifications.story_id`, and `notification_deliveries.story_id`'s
composite foreign keys are dropped and recreated against
`stories(id, family_space_id)` as part of the same migration, and every
existing row in those three tables is updated to reference the newly
migrated `stories.id` in place of its old `photo_stories.id`. **No
permanent compatibility layer is introduced** — `photo_stories`,
`photo_story_revisions`, `PhotoStory`, `PhotoStoryRevision`, and
`PhotoStoryPolicy` are removed entirely once every live consumer has
moved onto `stories`, in the implementation stage immediately following
that migration (§ Implementation notes) — never left as a lasting dual
model, and never removed before that migration and cutover are complete.

### 21. Story comments

Stories require conversation in V1. Story comments are a distinct model
from Photo comments, scoped to the Story itself, never to a
`(photo_id, album_id)` pair:

```text
story_comments
    id              ulid, primary key
    family_space_id tenant scope, RLS
    story_id        typed FK, tenant-consistent composite, cascadeOnDelete
    author_id       nullable, FK to users, nullOnDelete
    body            jsonb — the "plain conversational" document subset
                    (§10): paragraph, text, and mention only
    created_at / updated_at
    deleted_at                     soft delete, matching PhotoComment's
                                   convention
    deleted_with_story_operation_id nullable ULID — set only when this
                                   comment's current soft-delete was
                                   cascaded from its Story's own deletion
                                   (§34), copied from that Story deletion's
                                   own `deletion_operation_id`; null for an
                                   independent delete
```

`story_comment_person_mentions` mirrors §12's shape exactly.

### 22. Story-comment newline and paragraph semantics

See §23 — the same shared rendering invariant applies to Story comments
and Photo comments identically, defined once there.

### 23. Existing Photo comments: typed mention extension

**Corrected in this reconciliation: this is a current-version requirement.**
`PhotoComment` gains the identical typed-mention capability Story
comments receive, without changing its table identity, its `(photo_id,
album_id)` conversation scoping, or its existing authorization
(`PhotoCommentPolicy`, unchanged). `photo_comments.body` converts from
plain `text` to the same "plain conversational" document subset used by
Story comments (paragraph, text, and mention only — no headings, no
marks, no horizontal rule) — `PhotoComment` does not become
`StoryComment`, and does not adopt Story's full rich-text vocabulary; it
remains lightweight conversation, now with durable typed mentions instead
of free-typed `@name` text. A companion `photo_comment_person_mentions`
table mirrors §12's shape exactly, integrated into `PersonMergeManager`
in the same stage. Existing `photo_comments` rows migrate mechanically:
legacy plain text wraps into a single paragraph/text-run document at
`schema_version: 1`; since no typed mention concept existed before this
ADR, there is no historical mention data to extract — every migrated
row's mention-extraction set starts empty. Mention autocomplete for
authoring a Photo comment follows §17's single shared authorization rule,
unchanged.

**The shared rendering invariant, applying identically to Story comments
and Photo comments**: a single line break within otherwise-continuous
text is preserved as a line break; one blank line produces a paragraph
break; a run of more than one blank line collapses to the same single
paragraph break, bounded — a comment can never produce an arbitrarily
large empty vertical area through repeated blank lines. This is a
rendering/normalization invariant, never a stored HTML transformation and
never raw `<br>` markup, implemented once and reused, not re-implemented
per rendering surface or per comment kind.

### 24. Story authorization follows the primary subject

A Story is discoverable/viewable only when its primary subject is
currently authorized for the viewer, reusing existing entry points
exactly, never a parallel Story-specific authorization model:

- **Person Story** — viewable when the viewer currently has legitimate
  visibility of the subject Person, bounded the same way Search/Personal
  Export already bound Contributor/Guest People-context.
- **Album Story** — viewable when `AlbumPolicy::view()` authorizes the
  viewer for the subject Album.
- **Event Story** — viewable when `FamilyEventPolicy::view()` (or the
  Event-visibility query ADR-0013 §9 introduced) authorizes the viewer
  for the subject Event.
- **Photo Story** — viewable when `PhotoPolicy::view()` authorizes the
  viewer for the subject Photo, exactly as `PhotoStoryPolicy`'s absence
  of an independent `view()` method already establishes today.

A Story never widens access to its subject. The same principle extends to
Story comments (§26), Photo-comment mentions (§23), and every mention
(§17): none ever exposes a hidden Person, Album, Event, or Photo.

### 25. Story creation, edit, and delete authority by subject type

Create, edit, and delete authority are decided separately from view
authority, reusing each subject's existing authorization primitives — no
new role matrix:

- **Person Story** — create authority mirrors the existing People
  directory-access boundary (Owner/Administrator/Member, non-Guest).
- **Album Story** — create authority mirrors `AlbumPolicy::contribute()`
  exactly.
- **Event Story** — create authority mirrors `FamilyEventPolicy::view()`
  combined with non-Guest status for ordinary members, and for Guest,
  bounded by `EventAccess::hasValidAdmission()` exactly as Event
  visibility already is — never implying wider Family Space contribution
  rights, and never implying Event-admission authority itself (§32).
- **Photo Story** — create authority is unchanged:
  `PhotoPolicy::authorStory()`'s existing rule.
- **Edit** — the Story's own author only, for every subject type,
  mirroring `PhotoStoryPolicy::update()`'s existing rule exactly.
- **Delete** — the Story's own author, or a manages-members role, for
  every subject type, mirroring `PhotoStoryPolicy::delete()`'s existing
  rule exactly.

### 26. Story-comment authorization

View authority is inherited entirely from the owning Story's own view
authority (§24). Create authority requires the commenter to currently
hold Story *view* authority. Edit is author-only; delete is author or a
manages-members role — mirroring `PhotoCommentPolicy`'s existing shape
precisely.

Because every comment operation above requires current Story view
authority, and a soft-deleted Story is never viewable, **no comment on a
Story can be independently created, edited, deleted, or restored while
that Story is itself soft-deleted** — closing, by construction rather
than by special-case logic, the scenario of an independent comment
mutation racing the Story's own deleted state (§34).

### 27. Person-merge integration, consolidated

Every Person-referencing column this ADR introduces is integrated into
`PersonMergeManager`'s existing capture/reconcile transaction, in the same
stage each table is introduced: `stories.person_id` (a plain repoint, no
collision possible — a Story has exactly one primary subject by
construction, §3); `story_person_mentions.person_id`,
`story_comment_person_mentions.person_id`,
`photo_comment_person_mentions.person_id`,
`person_biography_mentions.person_id`, `album_description_mentions.person_id`,
`event_description_mentions.person_id` (each **also a plain repoint** —
`UPDATE person_id` under the merge operation currently being applied,
never a collision-aware repoint and never a deduplication step). Because
every mention table's uniqueness is `UNIQUE({owner}_id, mention_id)`, not
`({owner}_id, person_id)` (§12), two mention rows resolving to the same
Person after a merge are not a collision — they remain two legitimate,
independently identified mention occurrences, distinguished by their own
`mention_id`s, exactly as before the merge. Reversal restores each
repointed row's `person_id` from that merge operation's own provenance
snapshot (`PersonMergeManager::restoreState()`) — no redundant-row
deletion, deduplication, or guarded-reversal collision handling is ever
needed for any mention table. No orphaned historical content results from
a merge — every mention row's `person_id` simply reflects who it
currently, canonically identifies.

### 28. Search integration

Search indexes first-class `stories` in place of `photo_stories`, reusing
ADR-0013's existing `PhotoStorySearchSummary`-shaped result contract,
generalised to a subject-agnostic Story summary: derived display heading
(§18), a short body excerpt, the author where authorized/useful, the
subject's type and identity, and a navigation target to both the Story
itself (§33) and its subject. Search authorization happens before
disclosure, reusing §24's per-subject-type rules exactly. Story comments
and Photo comments remain excluded from global search, exactly as
`PhotoComment` already is (ADR-0013 §8) — this ADR does not reopen that
exclusion, and the new typed mentions inside comments do not change it.

### 29. Family activity integration

`family_activities.action_type = 'story_added'` continues to reference
the Story via `subject_story_id` alone — the activity row does not
separately record which kind of subject the Story is about, since that
is already resolvable by following `stories.person_id`/`album_id`/
`event_id`/`photo_id` from the referenced Story. What changes is the
**rendering** of that activity, branching on the Story's own
primary-subject type: "Sarah added a story about William" (Person),
"Helen added a story to William's 80th Birthday" (Event), "Robert added a
story to Mercer Family Holidays" (Album), or the existing Photo-Story
wording unchanged. Story comments and Photo comments are **not** added to
the homepage family-activity feed — the accepted Phase 12 design already
excludes comments from that feed, and this ADR does not reopen it.

### 30. Notifications: Story creation, reconciled across subject types

The existing `story` notification category's recipient rule is
Photo-specific today and is reconciled per subject type, keeping every
rule bounded and explainable, never inferring recipients from the
relationship graph:

- **Photo Story** — unchanged: the Photo's creator, plus the linked User
  of any Person with an approved `PhotoPerson` association on that Photo.
- **Person Story** — the subject Person's own linked `User`, if any, and
  only if that User currently holds authorization to see the Story. No
  relative is inferred, and no notification is sent if the subject Person
  has no linked account.
- **Album Story** — the Album's creator.
- **Event Story** — the Event's creator only — never every admitted
  Guest or every Album participant.

Self-notification suppression applies uniformly.

### 31. Notifications: comment mentions, reconciled onto the existing `comment` category

**Corrected in this reconciliation: no new notification category is
introduced.** The original draft proposed a distinct `story_comment`
category; inspecting the completed Phase 12 taxonomy directly
(`NotificationManager`, §Context) found no genuine product reason to give
Story-comment notifications a separate, independently user-configurable
preference from Photo-comment notifications — both are "someone
commented on content I'm connected to," and a second, near-identical
preference toggle would be exactly the noise Phase 12's own design
already worked to avoid. **Story comments and Photo comments share the
existing `comment` category, its existing `notification_preferences`
row, and its existing in-app/email defaults — no migration, no new
category enum value, no new default, and no settings/UI change are
required.**

The category's typed-column shape (already `photo_id`, `album_id`,
`story_id`, `person_id`, `comment_id` on `notifications`/
`notification_deliveries`, per ADR-0014 §29) gains one additional typed
column, `story_comment_id`, and the existing `comment`-category `CHECK`
constraint gains a second valid branch alongside the existing Photo-comment
one:

```text
category = 'comment' AND (
    (photo_id IS NOT NULL AND album_id IS NOT NULL AND comment_id IS NOT NULL
        AND story_id IS NULL AND story_comment_id IS NULL AND person_id IS NULL)
    OR
    (story_id IS NOT NULL AND story_comment_id IS NOT NULL
        AND photo_id IS NULL AND album_id IS NULL AND comment_id IS NULL AND person_id IS NULL)
)
```

`source_action_id` remains the originating comment's own id in both cases
(the `PhotoComment` id or the `StoryComment` id) — a real, stable domain
id per category, unchanged in principle from ADR-0014 §25. **Recipient
resolution for the `comment` category is extended symmetrically for both
shapes**, unifying around one recipient philosophy — content owner(s),
prior conversation participants, and explicit mentions:

- **Photo comment** (unchanged base, extended): the Album's creator; the
  Photo's own creator; every prior `PhotoComment` author in that exact
  `(photo_id, album_id)` conversation; **and, newly, every User
  explicitly @mentioned in the new comment**, via
  `photo_comment_person_mentions` → `PersonAccountLink`.
- **Story comment**: the Story's own author; every prior `story_comments`
  author in that specific Story's conversation; and every User explicitly
  @mentioned in the new comment, via `story_comment_person_mentions` →
  `PersonAccountLink`.

Self-notification suppression applies to both. Every existing Phase 12
guarantee applies unchanged: durable candidate idempotency, independent
in-app/email channel outcomes and preferences, authorization revalidated
at candidate creation, before delivery, and at read time.

### 32. Invitations and Event semantics remain untouched

Adding a Story about an Event, or commenting on one, never grants Event
access, never constitutes an admission, and never invites anyone. Event
access remains governed entirely by `EventAdmission`/invitation
mechanics, unrelated to and unaffected by this ADR.

### 33. Story URL and navigation

A Story has a stable canonical detail identity and navigation target
(`/families/{familySlug}/stories/{storyId}`, or the equivalent this
project's existing routing convention naturally extends to), sufficient
for Search results, notifications, family activity, and direct in-product
links. This ADR does not introduce public or externally-shareable Story
links.

### 34. Story deletion semantics

Soft deletion, preserving the existing `PhotoStory` behaviour exactly —
`deleted_at` on `stories`, recoverable, never a hard delete outside Family
Space teardown. Deleting a Story does **not** delete or affect its subject
Person/Album/Event/Photo in any way, and does **not** affect unrelated
Photo comments. Its own mention-extraction rows remain as historical
evidence regardless of the Story's deleted state, removed only when the
owning row is eventually purged (§14).

**Comment deletion provenance is identified by a ULID operation marker,
never by a timestamp.** A timestamp cannot safely serve as deletion-
operation identity here: this project's `DateTime` values bind at
whole-second precision, so two delete/restore operations occurring within
the same wall-clock second would be indistinguishable by timestamp alone.
`stories.deletion_operation_id` and
`story_comments.deleted_with_story_operation_id` (§3, §21) are nullable
ULIDs, generated using this codebase's normal ULID convention — ordinary
lifecycle timestamps (`deleted_at`, `created_at`, etc.) remain present as
metadata, but never as the identity of *which* deletion operation produced
the current state.

- **Story delete transaction**: (1) lock the `stories` row; (2) verify the
  Story is currently active (`deleted_at IS NULL`) — a delete on an
  already-deleted Story is a no-op, not a second operation; (3) generate
  one new ULID and write it to `stories.deletion_operation_id`; (4)
  soft-delete the Story (`deleted_at = now()`); (5) identify only
  `story_comments` rows currently active (`deleted_at IS NULL`) for that
  Story; (6) soft-delete those rows; (7) stamp each of those same rows'
  `deleted_with_story_operation_id` with the Story's own
  `deletion_operation_id` from step 3. A comment already independently
  deleted before this transaction is left completely untouched — neither
  its `deleted_at` nor its `deleted_with_story_operation_id` (which stays
  null) is written.
- **Independent comment delete** (by the comment's own author, or a
  manages-members role, while the Story is active): sets `deleted_at`
  only; `deleted_with_story_operation_id` stays null. Only reachable
  while the Story itself is active — see §26's structural rule, restated
  below.
- **Story restore transaction**: (1) lock the `stories` row; (2) read its
  current `deletion_operation_id`; (3) restore the Story
  (`deleted_at = null`); (4) restore only `story_comments` rows whose
  `deleted_with_story_operation_id` equals that exact
  `deletion_operation_id` (`deleted_at = null`); (5) clear
  `deleted_with_story_operation_id` on each of those restored rows; (6)
  clear `stories.deletion_operation_id`. A comment independently deleted
  before the Story was deleted has `deleted_with_story_operation_id =
  null` and never matches, so it is untouched and remains deleted; a
  comment stamped by a *different*, earlier deletion operation's now-stale
  marker likewise never matches the Story's current
  `deletion_operation_id` and cannot be resurrected.
- **Independent comment delete or restore while the Story is itself
  soft-deleted**: structurally impossible (§26) — comment mutation
  requires current Story view authority, which a soft-deleted Story never
  grants.
- **Repeated cycles are deterministic**: each successful Story deletion
  generates exactly one fresh `deletion_operation_id` and owns exactly one
  state transition — delete cycle 1 produces operation ULID `X`; restore
  clears `X` from the Story and every comment it stamped; delete cycle 2
  produces a new, different operation ULID `Y`; no stale marker from `X`
  can ever match `Y`, even if both cycles occur within the same
  wall-clock second, because identity is the ULID, never a timestamp.
- **Locking and idempotency**: both the delete and restore transactions
  lock the `stories` row first and verify the Story's current state
  (active for delete, deleted for restore) before generating or
  consuming an operation id — a retry of an already-completed delete
  finds the Story already inactive and is a no-op rather than generating
  a second `deletion_operation_id` for the same transition, and
  correspondingly for restore.
- **Purge**: once a Story or an individually-deleted comment is
  physically removed — only via Family Space teardown (§36) — its
  operation-id columns become moot along with the row itself; no separate
  purge rule is needed.
- Revisions and mention-extraction rows are unaffected by any of the
  above — they remain exactly as §14 already establishes, consistent
  throughout every delete/restore cycle, regardless of which deletion
  path produced the current state.
- No general-purpose deletion-event or audit-log infrastructure is
  introduced — `deletion_operation_id`/`deleted_with_story_operation_id`
  exist solely to make this one cascade relationship deterministic.

### 35. Reactions are out of scope

Stories have no reaction model today, and this ADR does not add one
merely because comments are being introduced. Reaction notifications
remain excluded, exactly as already established. A future reaction
decision, if ever genuinely valuable, is a separate, deliberately-scoped
ADR.

### 36. Teardown, tenancy, and database constraints

Every table this ADR introduces is tenant-scoped (`family_space_id`,
`FORCE ROW LEVEL SECURITY`, a tenant-isolation policy matching every other
table), uses ULIDs consistently with the rest of this codebase, and every
subject/mention/comment foreign key is a tenant-consistent composite FK —
a cross-Family-Space reference is structurally impossible at the
PostgreSQL level. Family Space teardown never relies on a cascade from the
`family_spaces` row itself, since that row is only ever status-flipped,
never physically deleted.

**Confirmed directly against `FamilySpaceDeletionManager::completeTeardown()`:
`stories`, `story_revisions`, `story_comments`, and every mention-extraction
table need no new explicit entry in its per-table delete list**, because
`photo_stories`/`photo_comments` already establish, and this ADR follows,
the pattern that makes an explicit entry unnecessary: each carries
`cascadeOnDelete()` on its owning foreign key (`stories`' four typed
subject columns to `people`/`albums`/`family_events`/`photos`; every
comment/revision/mention table's owning column to `stories`,
`story_comments`, or the field it annotates), and every one of those
ultimate parent tables is already explicitly hard-deleted by
`completeTeardown()` today — `photo_stories`/`photo_comments` themselves
appear nowhere in that method and rely on exactly this cascade.
`photo_stories`/`photo_story_revisions` are dropped entirely once
`FamilySpaceDeletionManager` and every other live consumer no longer
reference them (§ Implementation notes), never before.

## Alternatives considered

- **Keeping Story as a Photo-only property and layering Person/Album/
  Event "mentions" on top instead** — rejected: this was the exact
  mismatch Phase 14 prototyping surfaced.
- **An untyped `subject_type` + `subject_id` polymorphic pair** —
  rejected, consistent with every prior ADR in this project.
- **Raw HTML or Markdown as the rich-text storage format** — rejected:
  HTML invites unbounded styling this product explicitly does not want;
  Markdown has no native place for a typed, ID-backed mention.
- **A generic polymorphic mention table** — rejected; a small, explicit
  extraction table per adopting field mirrors an already-proven pattern.
- **Trusting embedded `mention` node `person_id` values for merge,
  search, or notifications, without an extraction table** — rejected:
  the same JSON-manipulation-for-merge problem ADR-0013 §15 already
  rejected for saved-search filters.
- **Deferring Person biography/Album description/Event description
  adoption to a later, unscheduled decision** — the original draft's
  error, corrected in §11: this is a settled current-version product
  requirement, not an open possibility, and the implementation sequence
  now owns it explicitly.
- **Leaving `PhotoComment` mention-inert while Story comments gain typed
  mentions** — the original draft's gap, corrected in §23: both
  conversation kinds now share the identical typed-mention capability,
  without changing `PhotoComment`'s table identity or `(photo_id,
  album_id)` semantics.
- **A distinct `story_comment` notification category with its own
  preference** — the original draft's choice, superseded in §31: no
  genuine product reason separates "someone commented on my Photo" from
  "someone commented on this Story" as a user-configurable preference;
  reusing the existing `comment` category avoids a redundant, noisy
  second toggle and requires no preference migration.
- **Extending Photo-comment mentions without also extending its
  notification recipient rule to include them** — rejected as an
  inconsistent half-implementation: if a Photo comment can now durably
  mention someone, that mention should carry the same notification
  weight a Story-comment mention does; both recipient rules are unified
  symmetrically in §31.
- **A required Story title field** — rejected: Story remains body-led; a
  derived, centralised heading serves every consuming surface.
- **Collapsing Story comments into the existing `PhotoComment` model** —
  rejected: a Story comment has no Album context; forcing it into
  `PhotoComment`'s shape would blur two conversational concerns this ADR
  keeps distinct.
- **Inferring Story/comment notification recipients from the Person
  relationship graph** — rejected throughout, matching this project's
  standing rejection of relationship-inferred recipients.
- **Adding reactions to Stories alongside comments** — rejected: no
  reaction model exists for Stories today and none is requested.
- **A permanent compatibility shim keeping both `PhotoStory` and `Story`
  live indefinitely** — rejected: legacy removal follows consumer
  migration by exactly one stage, never left open-ended.
- **Public/externally-shareable Story URLs in this ADR** — rejected: no
  product requirement calls for it now.
- **Keying mention extraction rows by `({owner}_id, person_id)` alone** —
  the original draft's choice, rejected in this reconciliation: it cannot
  deterministically resolve an embedded mention back to its current
  Person after a merge, and cannot distinguish two mentions of the same
  Person inside one document; corrected via the `mention_id`-keyed model
  (§12).
- **Removing legacy `PhotoStory` in the same stage that creates `Story`**
  — the original draft's staging error, rejected once every live consumer
  (Search, Discovery, homepage, notifications, exports, the conversation
  controller) was confirmed still reading it; corrected via the
  schema-creation → consumer-migration → removal sequence (§ Implementation
  notes).
- **Making rich-text/mention documents writable before their extraction
  tables exist** — the original draft's staging error, rejected because it
  would allow a persisted document with no canonical mention record;
  corrected by introducing extraction tables and the save/update
  transaction in the same stage as the document schema itself.
- **Restoring every cascade-soft-deleted Story comment unconditionally on
  Story restore** — rejected: would incorrectly resurrect a comment that
  was independently deleted before the Story was; corrected via a
  deletion-operation marker (§34).
- **Using a timestamp (`deleted_with_story_at`) as deletion-operation
  identity** — the previous reconciliation's choice, rejected in this
  pass: this project's `DateTime` binding is whole-second precision, so
  two delete/restore cycles within the same second could collide;
  corrected via a nullable ULID `deletion_operation_id`/
  `deleted_with_story_operation_id` pair (§3, §21, §34), with ordinary
  timestamps retained only as lifecycle metadata, never as operation
  identity.
- **Treating a continuing mention's submitted embedded `person_id` as
  authoritative for its extraction row** — rejected: it would let an
  ordinary, unrelated document edit silently repoint an already-merged
  mention back to the pre-merge Person (or reject the edit outright for
  no longer matching), either way undoing or conflicting with a completed
  Person merge; corrected by never reading a continuing `mention_id`'s
  submitted `person_id`/`label` as authoritative in the save/update
  transaction (§12).
- **Allowing an in-place retarget of an existing mention's Person via an
  ordinary save payload** — rejected: it would make an existing
  `mention_id`'s canonical target ambiguous depending on incidental
  submission content; corrected by requiring retargeting to be expressed
  as retiring the existing mention occurrence and creating a new one with
  its own `mention_id` (§12).

## Consequences

### Positive

- Family memories can finally be told about the person, gathering, or
  collection they are actually about, rather than being forced onto a
  single photograph.
- One shared rich-text/mention infrastructure, adopted by six content
  surfaces in one version rather than staged open-endedly, means Person
  biography, Album/Event description, and both comment kinds all gain
  durable typed mentions at once, from one design.
- Keying mention extraction on a stable `mention_id` rather than on
  `person_id` is not just merge-safe but simpler than
  `saved_search_people`'s own precedent: no collision-and-guarded-reversal
  handling is needed for any mention table, because the uniqueness key
  never contains the Person being repointed.
- Reusing the existing `comment` notification category rather than
  introducing a second near-identical one keeps the user-facing
  preference surface exactly as small as Phase 12 already deliberately
  made it.
- Reusing `saved_search_people`'s already-proven merge-safe mention
  pattern, and every existing authorization/query entry point per
  subject/field, means this ADR introduces very little genuinely new
  architecture.

### Negative

- Nine new tables plus a widened `CHECK` constraint and a new typed
  column on two existing tables is substantially more schema and
  migration surface than the original Story-only scope.
- Three existing composite foreign keys must be dropped and recreated as
  part of this migration.
- `PhotoComment`'s storage format changes (plain text → structured
  document) in this same version, which is more migration risk taken on
  at once than deferring it would have been — accepted because leaving
  it mention-inert while Story comments were not would have been an
  inconsistent, half-finished product surface.
- Splitting Story delivery into four sequential stages (schema/infra,
  consumer migration, legacy removal, biography/description/PhotoComment
  extension) rather than one larger stage takes longer to reach a fully
  cut-over state, in exchange for every intermediate stage boundary
  staying runnable and testable — accepted because a broken intermediate
  stage is a worse outcome than a longer sequence.

### Risks

- If a Story is ever implemented allowing more than one subject FK
  non-null, or none, the `CHECK` constraint is the only safeguard —
  worth a direct constraint-violation test for every combination.
- If the `family_activities`/`notifications`/`notification_deliveries`
  FK repoint is incomplete, a dangling `photo_stories.id` reference could
  survive that table's removal — worth a direct migration test.
- If any mention-extraction table is ever populated by parsing a document
  at read time instead of maintained transactionally at write time, it
  could drift from the document's actual mentions.
- If mention autocomplete is ever implemented against the full Person
  directory for Contributor/Guest, in any authoring context, it becomes
  exactly the hidden directory bypass this ADR rejects.
- If the widened `comment`-category `CHECK` constraint is ever
  implemented to allow both the Photo-comment and Story-comment column
  sets non-null on the same row, that row would represent two
  conversations at once — worth a direct constraint test rejecting it.
- If Story-comment or Photo-comment mention-based notifications are ever
  extended to infer recipients from the relationship graph, it
  reintroduces exactly the noise this project has repeatedly rejected.
- If Story deletion is ever implemented to hard-delete `story_comments`
  immediately rather than soft-deleting them alongside the Story, a
  restored Story would lose its conversation.
- If a client fails to echo an existing mention's `mention_id` back on
  edit, the save-transaction treats it as a brand-new mention (deleting
  the old extraction row, inserting a new one under a fresh id) — a
  functionally correct but historically noisy outcome, not a data-loss
  bug; worth a direct regression test asserting `mention_id` stability
  across an edit that leaves a mention untouched.
- If the Stage `S02` backfill and Stage `S03` cutover (§ Implementation
  notes) are ever implemented far enough apart in practice, the
  idempotent re-run must actually catch every legacy row created in the
  gap — worth a direct test creating a legacy `PhotoStory` row between two
  backfill runs and confirming it appears in `stories` after the second.
- If Story deletion and its comment cascade are ever implemented as two
  separate, non-atomic writes, a comment created between them could be
  missed or incorrectly stamped — worth a direct test asserting the
  cascade happens in the same transaction as the Story's own soft delete.

## Implementation notes

- **Stage ownership is redesigned in this reconciliation so every
  completed Phase 14 stage leaves the repository runnable, testable, and
  internally consistent** — no stage may leave live code reading or
  writing a table that stage has removed, and no stage may make a mention
  document writable before its extraction table and save-transaction
  exist. This corrects the original draft, which removed `photo_stories`
  in the same stage that created `stories` while Search, Discovery,
  homepage activity/memories, notifications, exports, and the
  conversation controller still read the legacy tables directly
  (confirmed against `apps/api/app/Queries/FamilyActivityQuery.php`,
  `HomepageMemoryQuery.php`, `apps/api/app/Search/DatabaseDiscoveryService.php`,
  `DatabaseSearchService.php`, `apps/api/app/Exports/FamilyArchiveBuilder.php`,
  `FamilyExportSelectionService.php`,
  `apps/api/app/Http/Controllers/NotificationController.php`,
  `PhotoConversationController.php`, `apps/api/app/Services/PhotoConversationManager.php`,
  and `AuditRecorder.php`), and which made mention documents writable
  (old `FPA-P14-S03`) before the extraction tables that must be
  transactionally maintained with them existed (old `FPA-P14-S04`). The
  corrected sequence is four new Phase 14 stages, still preceding the
  existing product-integration stages (renumbered accordingly, since
  Phase 14 has not yet started implementation):

  - **`FPA-P14-S02` — Story/comment schema, rich-text and mention
    infrastructure (additive only; legacy untouched)** implements §3-§4,
    §6-§10, §12-§14, §17-§19, §21-§22, and Person-merge integration
    (§13, §27) for every table this stage introduces (`stories`,
    `story_revisions`, `story_comments`, `story_person_mentions`,
    `story_comment_person_mentions`). The rich-text document schema, its
    validation/rendering boundary, the `mention_id`-keyed mention model
    and save/update transaction (§12), and every new Story/Story-comment
    authorization policy (§24-§26) are all implemented here — but
    **nothing user-facing changes yet**: no route or controller exposes
    Story creation, `photo_stories`/`photo_comments` remain the sole live
    read/write path for every existing consumer, and this stage's new
    code is exercised only by its own unit/feature tests. An idempotent,
    re-runnable backfill migration copies every existing
    `photo_stories`/`photo_story_revisions` row into
    `stories`/`story_revisions` (§20) at the end of this stage. Because
    extraction-table creation and the save/update transaction land
    together, in the same stage as the document schema itself, there is
    no sub-state in which a mention document is writable without its
    canonical extraction row.
  - **`FPA-P14-S03` — Migrate every live PhotoStory consumer onto Story**
    re-runs `S02`'s backfill first (closing any gap from legacy rows
    created or edited since `S02`), then repoints
    `family_activities.subject_story_id`, `notifications.story_id`, and
    `notification_deliveries.story_id` from `photo_stories` onto
    `stories` in the same migration (§20); wires the new
    Story/Story-comment controllers and routes to `S02`'s already-complete
    authorization and save-transaction; re-indexes Search and Discovery
    onto `stories` (§28, `DatabaseSearchService`/`DatabaseDiscoveryService`);
    updates family-activity/homepage-memory rendering to branch on
    primary subject type (§29, `FamilyActivityQuery`/`HomepageMemoryQuery`);
    reconciles the `story` notification category per subject type and
    widens the `comment` category with the `story_comment_id` column and
    its second `CHECK` branch for the Story-comment shape (§30-§31,
    `NotificationController`/`NotificationManager`); moves export
    selection and archive building onto `stories`
    (`FamilyExportSelectionService`/`FamilyArchiveBuilder`); retires
    `PhotoConversationController`/`PhotoConversationManager` in favour of
    the new Story API for the Photo-subject case; updates `AuditRecorder`
    to record `Story` rather than `PhotoStory`; and rewrites every
    affected test (`HomepageMemoryTest`, `PhotoConversationTest`,
    `SearchHttpTest`, `NotificationHttpTest`, `SearchRelationshipHttpTest`,
    `FamilyExportHttpTest`, `PhotoDeletionTest`). **After this stage, no
    live code path reads or writes `photo_stories`/`photo_story_revisions`
    for any purpose** — the legacy tables and models still physically
    exist, as a one-stage safety margin, but are dead code.
  - **`FPA-P14-S04` — Remove legacy PhotoStory** drops `photo_stories` and
    `photo_story_revisions`, and deletes `PhotoStory`,
    `PhotoStoryRevision`, and `PhotoStoryPolicy` entirely (§20) — safe and
    purely mechanical, since `S03` already confirmed nothing live
    references them.
  - **`FPA-P14-S05` — Extend rich-text and typed mentions to biography,
    descriptions and Photo comments** implements §11 and §23, following
    `S02`'s own discipline: `person_biography_mentions`,
    `album_description_mentions`, `event_description_mentions`, and
    `photo_comment_person_mentions`, together with their save-transaction
    and `PersonMergeManager` integration, land in the same stage as the
    storage-format conversion for `people.biography`,
    `albums.description`, `events.description`, and
    `photo_comments.body` — never a stage where a field is writable in
    the new format before its extraction table exists. This stage also
    extends the `comment` category's recipient rule to include explicit
    Photo-comment mentions (§31), since `photo_comment_person_mentions`
    only exists from this stage onward.
  - The existing Phase 14 product-integration stages follow unchanged in
    substance, renumbered `FPA-P14-S06` through `FPA-P14-S12`.
  - **Legacy read/write path, by stage**: every existing consumer
    (Search, Discovery, homepage/family-activity, notifications, exports,
    `PhotoConversationController`) keeps reading and writing
    `photo_stories`/`photo_comments` unchanged through `S02`; all of them
    cut over together in `S03`. **`photo_stories`/`photo_story_revisions`
    are dropped in `S04`.** **Rich-text/typed mentions become writable via
    a live API for the first time in `S03`** for Story/Story-comment (the
    schema and save-transaction exist from `S02`, but nothing calls them
    until `S03` wires the routes), **and in `S05`** for
    biography/description/Photo-comment.
  - `FamilySpaceDeletionManager::teardown()` requires no new explicit
    per-table delete lines for any table this ADR introduces (§36) —
    confirmed directly against its current implementation, not assumed.
- **Required regression tests**: (1) a `CHECK` violation for zero or
  multiple non-null subject columns on `stories`; (2) every existing
  `photo_stories` row and its revisions migrate into `stories`/
  `story_revisions` with byte-identical plain-text content and preserved
  authorship/timestamps; (3) zero dangling `family_activities`/
  `notifications`/`notification_deliveries` references to `photo_stories`
  after `S03`'s FK repoint, and `photo_stories` no longer exists after
  `S04`; (4) a Story viewable only through its subject's current
  authorization, for all four subject types; (5) editing a Story's
  mentions correctly updates its extraction table to match exactly; (6) a
  mention created for Person A, A merged into B: the document JSON still
  contains the original historical mention payload (`person_id` = A,
  unchanged `label`), the extraction row's `person_id` now reads B, and
  rendering links to B; (7) the same mention's `label` renders as
  historical fallback text if the extraction row is ever unavailable; (8)
  guarded merge reversal restores every mention extraction row's
  `person_id` back to the pre-merge Person, read from the merge snapshot,
  and mention resolution correctly reverts to A; (9) two separate mentions
  of the same Person inside one document produce two distinct extraction
  rows, both correctly repointed by a subsequent merge, remaining
  unambiguous throughout; (10) a `mention_id` is stable across an edit
  that leaves that mention node untouched, and a fresh `mention_id` is
  assigned only to a genuinely new mention node; (10a) with `A → B`
  active, attempting `B → C` is rejected by
  `PersonMergeManager::validatePair()`, exactly as it is today, with no
  ADR-0021 mention table left in an inconsistent state by the rejected
  attempt; (10b) after `A → B` is reversed, a subsequent valid merge
  (`B → C`, or `A → C`) proceeds under the existing rules and every
  mention extraction row repoints correctly for that new, independent
  merge; (10c) mention M1 created for A; A merged into B; M1's extraction
  row points to B; the same document is then edited only in unrelated
  prose, with the submitted JSON still carrying M1's historical `person_id
  = A`; the save succeeds, M1's extraction row still points to B
  afterwards, and rendering still links to B; (10d) an intentional user
  retarget of an existing mention (e.g. from William/B to Robert/C)
  produces a brand-new `mention_id` M2 pointing to C, retires M1's
  extraction row entirely, and never mutates M1's row in place; (10e) an
  ordinary save payload cannot implicitly change an existing `mention_id`'s
  extraction-row `person_id` under any submitted combination of
  `person_id`/`label`, for a continuing `mention_id`; (10f) after the
  retarget in (10d), a subsequent Person merge or reversal involving B, C,
  or the original A still resolves and repoints correctly, with M1 (now
  retired) and M2 each behaving exactly as §13 specifies for their own,
  independent history; (10g) a submitted `mention_id` that does not match
  any existing extraction row for that owning document (unrecognized or
  replayed from elsewhere) is treated as a new mention — assigned a fresh
  server-side `mention_id`, never adopted as-is; (10h) the invariants in
  (10c)-(10g) hold identically across all six adopting content surfaces
  (Story body, Story comments, Photo comments, Person biography, Album
  description, Event description); (10i) a document containing two
  separate mention occurrences of the same Person, each with its own
  distinct `mention_id` (`M1`, `M2`), is accepted and produces two
  distinct extraction rows; (10j) a document submitting the same
  already-recognized `mention_id` (`M1`) twice is rejected before any
  extraction-table write occurs; (10k) after a rejection under (10j), the
  existing extraction row for `M1` is confirmed completely unchanged;
  (10l) a document submitting the same unrecognized/client-forged id on
  two separate new mention nodes is accepted, but each node is assigned
  its own distinct, server-generated `mention_id`, and the persisted
  canonical document contains no duplicate `mention_id`s as a result;
  (10m) the ordinary continuing/new/removed partition (§12) remains
  atomic and unaffected by the duplicate-recognized-`mention_id` check —
  a document with no duplicates saves exactly as before; (11) mention
  autocomplete for
  Contributor/Guest never returns a Person
  outside their currently-authorized Photo context, in every authoring
  surface (Story body, Story comment, Photo comment, biography,
  description); (12) existing `photo_comments` rows migrate to the
  structured format with byte-identical plain-text content and zero
  mention rows; (13) a Photo comment's explicit mention notifies that
  user, in addition to the existing Album-creator/Photo-creator/prior-commenter
  set; (14) a Story-comment notification reaches the Story author, prior
  commenters, and explicitly-mentioned users, and no one else; (15) both
  the Photo-comment and Story-comment shapes of the `comment` category
  are governed by the same, single `notification_preferences` row per
  user; (16) a row attempting both the Photo-comment and Story-comment
  column shapes simultaneously is rejected by the widened `CHECK`
  constraint; (17) a Person-Story notification reaches only the subject's
  linked User, never a relative; (18) two sequential comments (of either
  kind) on the same target each produce their own independent
  notification; (19) an active Story comment cascade-soft-deletes when
  its Story is deleted (receiving the Story's `deletion_operation_id` as
  its own `deleted_with_story_operation_id`), and is restored — with that
  marker cleared — when the Story is restored; (20) a comment
  independently deleted before its Story was deleted keeps
  `deleted_with_story_operation_id = null`, remains deleted after the
  Story is restored, and is never touched by the Story's delete or
  restore transaction; (20a) two Story delete/restore cycles performed
  back-to-back within the same wall-clock second still receive two
  distinct `deletion_operation_id` ULIDs; (20b) only comments stamped
  with the Story's *current* `deletion_operation_id` restore — a comment
  carrying a stale marker from an earlier, already-cleared deletion
  operation is never resurrected by a later restore; (21) attempting to
  delete or restore a comment while its Story is itself soft-deleted is
  rejected; (22) repeated Story delete/restore cycles are idempotent — a
  retried delete on an already-deleted Story, or a retried restore on an
  already-active Story, generates no additional `deletion_operation_id`
  and never disturbs a comment's own independent deletion history; (22a)
  mention-extraction rows for both the Story and its comments remain
  exactly consistent with §14 throughout every delete/restore cycle in
  this list; (23) Family Space
  teardown removes all nine new tables via cascade, with no explicit
  per-table delete line required; (24) a cross-Family-Space subject or
  mention reference is rejected at the PostgreSQL level; (25) comment
  newline normalization produces identical bounded paragraph rendering
  for a Story comment and a Photo comment given equivalent input; (26)
  the derived display heading is identical regardless of which consuming
  surface requests it; (27) Person biography/Album description/Event
  description each render their adopted rich text and mentions correctly
  and remain editable only by their existing, unchanged authorization;
  (28) a legacy `PhotoStory` row created between `S02`'s backfill and
  `S03`'s cutover is still correctly present in `stories` after `S03`
  re-runs the backfill; (29) after `S03`, no code path reads or writes
  `photo_stories`/`photo_story_revisions`, confirmed by removing them
  entirely in `S04` with the full test suite still passing.

## Review triggers

- **If a genuine requirement for public/externally-shareable Story links
  emerges**: scope it as a separate, deliberate decision.
- **If a genuine reaction-on-Stories requirement emerges**: scope it
  separately, preserving the "no engagement mechanics" discipline already
  applied elsewhere.
- **If a genuine body/description-mention notification requirement
  emerges**: revisit §16's deliberate exclusion with a concrete product
  justification, not as a quiet extension.
- **If the rich-text node vocabulary ever needs to grow**: treat it as a
  schema-version bump (§7), never a silent vocabulary change against
  version 1.
- **If a genuine reason to separate Story-comment and Photo-comment
  notification preferences ever emerges**: revisit §31's shared-category
  decision explicitly, including the migration such a split would
  require, rather than assuming it can be added silently later.
- **If a genuine zero-downtime/concurrent-deployment requirement ever
  applies to this migration**: revisit the `S02` backfill /
  `S03` cutover approach (§ Implementation notes) with a proper
  live dual-write window — not assumed necessary today, since this
  product does not commit to zero-downtime migrations between
  sequential implementation stages.

## Deferred concerns

- The exact editor implementation/library used to produce and edit the
  structured document client-side.
- Exact physical column types, index definitions, and bounded
  size/depth limits for the rich-text document.
- The exact Story detail route path and its integration into Phase 14's
  navigation shell.
- Public/externally-shareable Story links, and any future Story reaction
  model.

## Resolved decisions

1. **Story becomes first-class** — exactly one primary subject drawn from
   Person, Album, Event, or Photo.
2. **Typed subject columns, not a polymorphic pair** — nullable typed FK
   columns plus a `CHECK` constraint.
3. **Supporting constraints** — only `stories` needs a new one; the four
   parent tables already have it.
4. **Story vs. biography/description/caption/comment** — all remain
   structurally distinct.
5. **Rich-text storage** — a small, closed, versioned `jsonb` document
   schema, shared across every adopting field.
6. **Reusable infrastructure adopted now, not deferred** — Person
   biography, Album description, and Event description adopt the shared
   rich-text/mention infrastructure in this same version.
7. **Typed mentions** — embedded nodes for structure/fallback; a
   companion relational extraction table per adopting field is canonical.
8. **Mentions and merge** — repointed via `PersonMergeManager`'s existing
   transaction; because extraction is keyed on `mention_id` rather than
   `person_id`, the repoint can never collide, and no
   collision-and-guarded-reversal handling is needed for mentions.
9. **Display heading** — derived, centralised, never a separately
   maintained title field.
10. **Story revisions** — generalised from `PhotoStoryRevision`,
    preserving complete per-revision documents.
11. **PhotoStory migration** — one-time, lossless, with the three
    dependent composite foreign keys repointed once every live consumer
    has moved onto `stories`, and legacy tables/models removed only in
    the stage immediately following that cutover, never before it.
12. **Story comments** — a distinct model, reusing the same constrained
    document-node family as the Story body, restricted to
    paragraph/text/mention.
13. **PhotoComment gains typed mentions now, not deferred** — the same
    restricted document format and mention-extraction pattern as Story
    comments, without changing its table identity or `(photo_id,
    album_id)` scoping.
14. **Comment newline normalization** — a shared rendering invariant
    applied identically to both comment kinds.
15. **Authorization follows the subject** — no new role matrix, for
    Stories or either comment kind.
16. **Mention autocomplete** — one authorization rule, shared by every
    mention-authoring surface; never a hidden directory bypass.
17. **Search** — re-indexes `stories` in place of `photo_stories`; both
    comment kinds remain excluded from global search.
18. **Family activity** — the existing `story_added` type is unchanged in
    shape; only its rendering now branches by subject type; comments of
    either kind remain excluded from the homepage feed.
19. **Notifications: Story creation** — the existing category's recipient
    rule is reconciled per subject type; no relationship-graph inference.
20. **Notifications: comments share one category** — no new
    `story_comment` category is introduced; Story comments and Photo
    comments share the existing `comment` category, preference row, and
    defaults; the category's typed-column shape and recipient rule are
    both widened to cover both conversation kinds, including explicit
    mentions as a recipient basis for each.
21. **Reactions** — explicitly out of scope.
22. **Teardown and tenancy** — every new table is RLS-scoped and
    cross-Family-Space references are structurally impossible; no new
    explicit `FamilySpaceDeletionManager::teardown()` entry is needed,
    since every table cascades from an ultimate parent teardown already
    hard-deletes, exactly as `photo_stories`/`photo_comments` do today.
23. **Deletion** — Story soft-deletion cascades to its currently-active
    comments only, stamping a fresh `deletion_operation_id` ULID
    (`deleted_with_story_operation_id` on the comment) so restoration is
    deterministic, never relying on timestamp equality — this project's
    `DateTime` binding is whole-second precision, so a timestamp cannot
    safely identify a deletion operation; a comment independently deleted
    before the Story never reappears; mentions remain as historical
    evidence; subjects and unrelated content are never affected.
24. **Navigation** — a stable, authenticated, in-product Story URL
    exists; no public sharing is introduced.
25. **Stage sequencing** — implemented as four new Phase 14 stages
    (`FPA-P14-S02`-`S05`, after `FPA-P14-S01` accepts this ADR), preceding
    the existing, renumbered product-integration stages
    (`FPA-P14-S06`-`S12`); no stage removes a model still in live use, and
    no stage makes a mention document writable before its extraction
    table exists.
26. **Merge-safe mention identity** — every mention node carries a stable
    `mention_id`, and extraction tables are keyed on it rather than on
    `person_id`, so a renderer can always resolve an embedded mention to
    its current canonical Person across every merge and reversal
    operation the existing `PersonMergeManager` permits.
27. **Person-merge boundary preserved, not expanded** — this ADR
    integrates with `PersonMergeManager`'s existing supported model
    exactly as it stands, including its standing prohibition on an active
    chained merge (`B → C` is rejected while `A → B` remains active); it
    adds no chained-merge reversal machinery and does not expand the
    merge model itself.
28. **Roadmap** — ADR-0021 is listed in `PROJECT_ROADMAP.md`'s Phase 14
    required decisions alongside ADR-0019.
29. **Ordinary edits cannot undo a merge; retargeting is create+remove,
    never in-place mutation** — the save/update transaction (§12) never
    reads a continuing `mention_id`'s submitted `person_id`/`label` as
    authoritative, so a stale, pre-merge embedded payload can never
    repoint an already-merged mention's extraction row. Changing which
    Person an existing mention refers to is expressed only as retiring
    that mention occurrence and creating a new one with its own
    `mention_id` — never a field update on the continuing occurrence.
    This holds identically across all six adopting content surfaces
    (§10).
30. **Validation is asymmetric between new and continuing mentions** — a
    new mention's submitted `person_id` must resolve to a current,
    authorized Person; a continuing mention's canonical identity comes
    from its extraction row alone, and its embedded historical `person_id`
    is never required to still be the current Person (§8). A submitted
    document may never contain the same recognized `mention_id` more than
    once — one extraction row backs exactly one embedded occurrence — and
    such a document is rejected before any extraction-table write, with
    the existing row left untouched (§8).
