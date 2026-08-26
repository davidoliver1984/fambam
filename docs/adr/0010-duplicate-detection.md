# ADR-0010: Duplicate Detection

- Status: Accepted
- Date: 2026-08-26
- Decision owners: David
- Related stages: FPA-P08-S01 (accepting this ADR completes that stage),
  implemented by FPA-P08-S02, FPA-P08-S03, FPA-P08-S04

## Context

Phase 5 (ADR-0007) computes and freezes a server-side SHA-256 over every
preserved original the moment it becomes trusted, and named this
deliberately: it "gives Phase 8's future duplicate detection an integrity
hash to build on without Phase 5 designing Phase 8's own perceptual-
hashing or duplicate-index concerns." Phase 6 (ADR-0008) then built
`Photo` as the layer that gives a preserved `MediaUpload` family meaning
— provenance, historical date, who's in it, which Albums it belongs to —
and explicitly deferred "Photo-to-Photo duplicate linkage" to this phase,
naming its own required, unique `Photo.media_upload_id` constraint ("one
`MediaUpload` backs at most one `Photo`") as something this ADR must
specifically confirm before building on it. Phase 7 (ADR-0009) added a
further review trigger: that Event duplicate review and Photo duplicate
detection stay distinct, independently-triggered workflows.
`PROJECT_ROADMAP.md`'s Phase 8 objective is "reduce repeated uploads
without risking automatic data loss."

Before deciding any mechanism, one governing principle sits ahead of
everything else in this ADR:

**Duplicate detection is an assistant, not a gatekeeper.** Fambam
recognises repeated media and offers a simple, honest choice — it never
assumes, infers, or acts as though repeated media represents the same
family memory. **Repeated media does not necessarily represent repeated
family memories.** Fambam cannot know from the bytes alone whether two
uploads are "the same memory told twice" or two genuinely independent
ones, so it never guesses. This is intentionally one of the simplest ADRs
in this series: no workflow engine, no review queue, no duplicate-
management centre, and — settled, final, not open for further
architectural reconsideration — **no merge, redirect, aggregation, or
consolidation mechanism of any kind.** The system detects, suggests, and
does exactly what the family selects. Nothing more.

**Reconciliation note.** This text incorporates a bounded implementation-
readiness reconciliation, made before any Phase 8 code was written. The
settled product model from the prior draft — the three-outcome choice in
§3, no consolidation mechanism of any kind, and comments/reactions scoped
per Photo-within-an-Album — is **unchanged and final**. What this
reconciliation adds is exactly what was still needed to make that
settled model buildable against the live repository, and nothing else:
mapping the three-outcome choice onto both of fambam's actual Photo-
creation paths, one of which is asynchronous and previously created
Photos automatically (§4); disclosure rules for the case where more than
one existing Photo shares a checksum (§3); a minimal, durable record of a
pair the family has already answered a duplicate question about, so it
is not automatically surfaced again unless an Owner or Administrator
explicitly reopens it (§9); and a concrete, minimal migration strategy for
the `PhotoComment`/`PhotoReaction` rows that already exist in the live
schema from before this ADR's Album-scoping decision was made (§7). It
also removes a wording conflict this ADR's predecessor left behind in
`PROJECT_ROADMAP.md` and `docs/IMPLEMENTATION_GUIDE.md`, both of which
still described a consolidation mechanism this ADR does not build.

This ADR decides: the architectural separation between preserved media
and family interpretation, unchanged in cardinality from ADR-0008; the
exact three-outcome interaction offered when a duplicate is detected, and
exactly how it applies to direct Photo creation and to Album/Event
contribution; that comments and reactions are scoped per Photo-within-an-
Album, not per Photo, revising ADR-0008 §11/§12 narrowly, including how
the conversation rows that already exist under the old scoping are
preserved; a minimal, durable memory of an already-answered duplicate
question, so automatic rediscovery does not override the settled decision
unless an Owner or Administrator explicitly reopens it; and the perceptual-similarity
suggestion mechanism and its strictly advisory status. It deliberately
does not decide anything about machine face recognition or embeddings
(Phase 9/10), semantic image understanding (Phase 16), Event management
(Phase 7, already decided), `PhotoPerson` (Phase 6, already decided), or
search (Phase 11).

## Decision

### 1. Separating preserved media from family memory — MediaUpload and Photo remain strictly one-to-one

```text
MediaUpload  -- one upload event, one preserved set of bytes
                (Phase 5's concern)
Photo        -- one family's interpretation of those bytes
                (Phase 6's concern)
```

**Identical media does not imply identical family meaning.** The same
visual content may legitimately support more than one independent
`Photo` record — different provenance, different stories, different
historical dates a family genuinely disagrees about.

**ADR-0008 §1's cardinality is confirmed, unchanged: `Photo.media_upload_id`
remains required, one-directional (`Photo -> MediaUpload`, never the
reverse), and unique.** One `MediaUpload` backs at most one `Photo`. Two
people scanning the same physical print are still two independent upload
events, even when the resulting bytes are identical. Nothing else about
§1 changes: a Photo still cannot exist without a trusted, `ready`
`MediaUpload` behind it; a `ready` `MediaUpload` may still exist
indefinitely without ever becoming a Photo; `Photo.created_by` remains
distinct from `MediaUpload.user_id`.

### 2. Scope

Phase 8 owns:

- exact duplicate detection (checksum-based);
- perceptual similarity detection (near-duplicate suggestion);
- the duplicate review surface;
- duplicate recommendations.

Phase 8 does **not** own, and this ADR decides nothing about:

- machine face recognition or face embeddings (Phase 9/10);
- semantic image understanding or embeddings (Phase 16);
- Event management (Phase 7, already decided by ADR-0009);
- `PhotoPerson` itself (Phase 6, already decided by ADR-0008 §5);
- AI-generated content suggestions of any other kind;
- search (Phase 11);
- any mechanism for combining, merging, or reconciling two Photos once
  they exist.

### 3. Exact duplicate detected: the three-outcome choice, and what "existing Photo" means when there's more than one

Every accepted original is preserved and validated exactly as ADR-0007
already specifies, completely unchanged by this ADR: server-side SHA-256
(`MediaUpload.original_sha256`) is computed once, during `verifying`, and
frozen at `preserved`. **Phase 8 introduces no new `MediaUpload` state, no
new pipeline stage, and no change to when or how that checksum is
computed** — it only adds a check at the moment a `ready` `MediaUpload`
would otherwise become a Photo (§4 defines exactly where that moment is
for each of fambam's two real creation paths).

At that moment, Fambam looks for every other `ready` `MediaUpload` in the
**same Family Space** that carries the identical `original_sha256` **and**
already backs a Photo. **Only Photos the current actor can already view
are ever disclosed.** A checksum match backed by a Photo the actor cannot
see is not shown, not hinted at, and not counted — from that actor's own
point of view, it is exactly as if no duplicate existed at all, and
Photo creation proceeds normally. This is not a new authorization
concept; it is the same "never disclose what you can't otherwise see"
discipline ADR-0008 §5/§7/§16 already apply everywhere else, restated
here because a duplicate check is the first Phase 8 mechanism whose
entire purpose is comparing one Photo against others.

**This ADR does not invent a canonical "the" duplicate.** If exactly one
visible Photo matches, it is the one Photo offered. If more than one
visible Photo matches — several relatives may each have independently
scanned the same print before this feature existed, say — **all of them
are shown, and the actor chooses which one, if any, to reuse.** The
outcome remains exactly the same three choices:

```text
We've already seen this photograph.

It currently appears in:

• Nan's Birthday
• Family BBQ
• Memories of Dad

What would you like to do?

○ Use existing Photo

○ Create a new Photo

○ Cancel
```

with "Use existing Photo" naming, or letting the actor pick, exactly
which of the visible existing Photos is meant when more than one
qualifies. §4 defines precisely what each outcome does on each of
fambam's two real creation paths.

### 4. Mapping the choice onto both real creation paths

fambam has exactly two places a `ready` `MediaUpload` becomes a `Photo`,
and they behave differently enough — one is a synchronous request the
actor is present for, the other is an automatic background job — that
each needs its own precise description. **Neither path gains a new kind
of Photo-creation authority; each reuses exactly the authority it already
had.**

**Direct Photo creation** (no chosen Album — the actor is creating a
standalone Photo from a submitted `MediaUpload` they are already
authorized to promote under ADR-0008 §1):

- **Use existing Photo** — no new `Photo` row is created; the actor's
  request returns/uses the existing Photo they chose (per §3); the
  existing Photo's metadata is untouched; the submitted `MediaUpload`
  is never linked to any Photo and remains `ready` and
  unpromoted, exactly the state ADR-0007 §2 already treats as normal and
  permanent.
- **Create a new Photo** — a completely independent new `Photo` is
  created referencing the submitted `MediaUpload` being promoted, never
  the pre-existing one, keeping §1's cardinality intact. Per §9,
  this also writes a `DuplicateDecision` against every visible match that
  was disclosed on that decision screen — not only one of them — so
  nothing already shown can immediately resurface as a fresh suggestion.
- **Cancel** — no Photo is created; nothing else happens.

Because this path is one synchronous request the actor is actively
making, no durable "waiting for a decision" state is needed: if a visible
match exists and the request does not already carry an explicit choice,
the request is answered with the visible candidates instead of creating
anything, and the actor's next request carries their explicit decision.
Nothing is written to the database until a decision is known. Duplicate
handling changes no Photo-promotion authority: a Member may enter this
flow only for their own ready `MediaUpload`, while an Owner or
Administrator may enter it for any ready `MediaUpload` in the Family
Space, exactly as ADR-0008 §1 already permits.

**Album/Event contribution** (the actor is contributing to a specific
Album, per ADR-0008 §8/ADR-0009 §5 — including a Guest's Event-scoped
contribution): the same three outcomes apply, with the target Album
folded in:

- **Use existing Photo** — no new `Photo` row is created; the chosen
  existing Photo is added to the target Album, using exactly the ordinary
  Album-contribution authority (ADR-0008 §8) or Event-scoped Guest
  authority (ADR-0009 §5/§13) the actor already had for this
  contribution — **never** the general Photo-creation authority
  ADR-0008 §1 reserves for direct creation, and never anything broader.
  If the chosen existing Photo is `private` and the target Album is more
  broadly visible, this is subject to ADR-0008 §5's existing explicit
  visibility-widening authorization and audit unchanged — reusing that
  mechanism exactly as written, not a new one. The actor's own
  newly-uploaded `MediaUpload` is never linked to any Photo and remains
  `ready` and unpromoted.
- **Create a new Photo** — an independent new `Photo` is created
  referencing the actor's own newly-uploaded `MediaUpload`, and that
  Photo is added to the target Album, exactly as an ordinary,
  non-duplicate contribution already works today. Exactly as for direct
  creation, this writes a `DuplicateDecision` (§9) against every visible
  match disclosed when the hold was shown, not only one of them.
- **Cancel** — no Photo is created; the contribution simply does not
  happen; the `MediaUpload` remains `ready`, unpromoted, and still
  carries its `target_album_id`, exactly as any abandoned contribution
  already may.

**This path is asynchronous, and this is the one place this ADR adds a
small, deliberately minimal piece of durable state.** `AlbumContributionFinalizer`
currently promotes a `ready`, Album-targeted `MediaUpload` into a Photo
automatically, inside a background job, with no human present at that
exact moment. It cannot simply pause and wait for an answer the way a
synchronous request can. **When this finalizer detects a visible exact
match, it must not auto-create the Photo.** Instead it records that this
specific contribution is waiting on a duplicate decision — a small,
new **`MediaUploadDuplicateHold`** record (`media_upload_id`,
`family_space_id`, `target_album_id`, `detected_at`, and, once resolved,
`resolved_at`/`resolved_by`/the chosen outcome) — and stops. **This is
not a new `MediaUpload` lifecycle state**: `MediaUpload` itself is
untouched, remains `ready` throughout, and this hold is deliberately a
separate, narrow record rather than an expansion of Phase 5's already-
settled state machine. A new endpoint lets the actor see the hold (the
same visible-candidates disclosure as §3) and resolve it with exactly
one of the three outcomes above; resolving it completes the same work
`AlbumContributionFinalizer` would otherwise have done automatically.
**Only the original uploader may resolve their own hold**, using exactly
the same contribution authority — `AlbumPolicy::contribute`-equivalent,
or `EventAccess::guestMayContributeToAlbum` for a Guest — that let them
upload to this Album in the first place; this is what "the same
scoped contribution flow" means concretely, and it is the only authority
a Contributor or Guest ever exercises here. An unresolved hold is a
harmless, permanent, valid state — nothing forces a decision, exactly as
nothing forces an ordinary `ready`-but-unpromoted `MediaUpload` to ever
become a Photo.

If no visible match exists — the ordinary case, for the overwhelming
majority of contributions — both paths behave exactly as they already do
today, with no observable change at all.

### 5. There is no further resolution mechanism

**This is the entire mechanism for a detected exact duplicate.** Once an
outcome from §4 has happened, Phase 8 has nothing further to do with that
pair beyond remembering that the question was already answered (§9).
There is no consolidation step, no redirect, no runtime aggregation of
content across Photos, no metadata copying, no inheritance, and no merge
engine of any kind. If a family later wants to change their mind about a
Photo they created independently, they use fambam's ordinary,
already-existing Photo and Album tools (ADR-0008 §8's Album management,
ADR-0008 §9's reversible Photo soft-deletion) exactly as they would for
any other Photo.

### 6. Comments and reactions belong to a Photo's experience within an Album, not to the Photo itself

**This is a settled, final product decision.** ADR-0008 §11/§12 modelled
`PhotoComment` and `PhotoReaction` as properties of a `Photo` alone. That
is corrected here: **a Photo represents the family memory; the
conversation about that memory belongs to the Album in which it is being
experienced.**

```text
Photo A
├── Album 1
│   ├── Album 1 comments
│   └── Album 1 reactions
├── Album 2
│   ├── Album 2 comments
│   └── Album 2 reactions
└── Album 3
    ├── fresh comments
    └── fresh reactions
```

Concretely:

- `PhotoComment` and `PhotoReaction` are each scoped to a specific
  `(photo_id, album_id)` pair for every newly created row, not to
  `photo_id` alone;
- the same Photo appearing in more than one Album carries a genuinely
  independent conversation in each — commenting or reacting in one
  Album's context never appears in, and is never merged with, another;
- adding the same Photo to a new Album — via ordinary curation, or via
  §4's "Use existing Photo" outcome — always begins that Album's
  conversation for that Photo empty, regardless of how much conversation
  the same Photo already has anywhere else. This is the intended
  behaviour: same Photo, different setting, different conversation;
- **there is no comment inheritance, no comment copying, and no runtime
  merging of conversations, ever.**

**`PhotoStory` is unaffected and remains Photo-scoped exactly as
ADR-0008 §11 already fixed it**, unless a future ADR explicitly changes
that. A Story is attributed archival narrative about the memory itself —
not conversational reaction to encountering it in a particular curated
collection.

**Authority to comment or react on a Photo within a given Album requires
visibility into both**, using each's own existing, unmodified rules —
ADR-0008 §7 for the Photo, ADR-0008 §8/`AlbumPolicy` for the Album. A
member cannot comment "within" an Album they cannot themselves see, even
if they can see the Photo through some other, unrelated path.

### 7. Preserving the conversation rows that already exist

`PhotoComment` and `PhotoReaction` are not a greenfield design: Phase 6
already shipped them, Photo-scoped, with real rows already in the live
database before this ADR's Album-scoping correction. **Those rows are
preserved exactly as they are — never fabricated an Album context, never
duplicated into more than one Album, and never silently discarded.**

- `album_id` is added to both tables as a **nullable** column — not
  required at the schema level, because a required column cannot
  honestly coexist with rows that genuinely predate the concept of an
  Album context at all. Every existing row keeps `album_id = NULL`
  unchanged; nothing about them is rewritten, guessed at, or migrated
  into a specific Album.
- **Every newly created `PhotoComment`/`PhotoReaction` row always sets
  `album_id`.** The application layer never writes a new row with a null
  `album_id` — nullability exists solely to honestly represent
  already-existing legacy data, not to permit new album-less
  conversation. A Photo with no Album membership at all therefore has no
  surface to add a *new* comment or reaction to until it is added to one
  — the same consequence this ADR already accepted for any Photo outside
  an Album.
- **Legacy (`album_id IS NULL`) conversation is exposed exactly once, on
  the Photo's own direct page** (`PhotoController::show`, independent of
  any Album) — never inside any Album's own conversation view, since it
  was never scoped to one and showing it there would misrepresent its
  origin. It is presented as read-only, clearly distinguished from any
  Album-scoped conversation, and reachable through the same Photo
  visibility rule (ADR-0008 §7) that already governed it before this ADR
  existed — unchanged, because it predates Album-scoping and has no
  Album to check visibility against. **No new comment or reaction can be
  added to the legacy bucket** — new conversation always requires an
  Album context, per §6.
- **Removing a Photo from an Album never destroys that Album's
  conversation for it.** `PhotoComment`/`PhotoReaction` rows are
  identified by the stable `(photo_id, album_id)` pair itself, not by any
  reference to the `AlbumPhoto` link row — so removing the `AlbumPhoto`
  link (ADR-0008 §8's existing, reversible Album-management action)
  leaves the matching comments and reactions in place, simply
  unreachable through that Album while the Photo isn't in it. If the same
  Photo is later re-added to the same Album, its prior `(photo_id,
  album_id)` conversation naturally reappears — nothing needed to be
  restored, because nothing was ever deleted. This falls directly out of
  the schema shape (no foreign key to `AlbumPhoto.id`, no cascade
  delete), not a bespoke preservation mechanism, and matches this
  project's consistent preference for reversibility over destruction.

This is the smallest change that satisfies the requirement: one nullable
column, one narrow rule about what new writes may do, and no data
migration of any kind — existing rows are not touched, only newly
introduced ones follow the new rule.

### 8. Retroactive exact matches and perceptual similarity remain suggestions only

Two Photos can end up with checksum-matching `MediaUpload`s without ever
passing through §3/§4's choice — most plausibly, two family members
independently scanning the same physical print close enough in time that
neither's upload existed yet when the other started, or content
uploaded before this ADR's checks existed at all. Separately, perceptual
matching catches what a checksum cannot: the same photograph scanned
twice at different settings, a resized copy, a recompressed download, a
colour-corrected version, one photograph from a burst of near-identical
shots. **Both surface as a `DuplicateCandidate` — a suggestion, never a
fact, and never a basis for automatic action of any kind.**

**Stage ownership is explicit and does not overlap.** Retrospective/
backfill generation for exact matches that §3/§4's interactive check
never saw — Photos that already existed, or were created concurrently,
before this ADR's checks ran — is exact-checksum work, owned by
`FPA-P08-S02` alongside interactive detection, using the same frozen
`MediaUpload.original_sha256` and the same `DuplicateDecision` suppression
rules. It is not perceptual work and does not wait on §8's calibration
gate below. `FPA-P08-S03` owns perceptual candidate generation only —
the hash generation, the calibration gate, and perceptual
`DuplicateCandidate` rows — and nothing about exact matching.

```text
DuplicateCandidate
- photo_id            (one side of the pair)
- candidate_photo_id   (the other side)
- source              exact | perceptual | member_flagged
- status               pending | dismissed
```

with enough recorded about its origin — the matched checksum, or the
algorithm/version/score for a perceptual match — that the review surface
can explain a suggestion. Any surface that lists or displays a
`DuplicateCandidate` respects the viewer's own Photo visibility exactly
as §3 already requires at creation time — a reviewer never sees a
candidate pair naming a Photo they could not otherwise see. Neither side
of a pair may be currently soft-deleted (ADR-0008 §9); a pair involving a
trashed Photo is not generated or shown until, if ever, that Photo is
restored.

The review surface is exactly two actions: a reviewer may **ignore** a
candidate (leave it `pending`) or mark it **not a duplicate**
(`dismissed`). **There is no third "consolidate" action.** Dismissing a
candidate, and choosing "Create a new Photo" in §3/§4 despite a known
match, both record the pair as settled — see §9 — so it is never
re-surfaced.

Perceptual hashes are computed from each Photo's **canonical asset**,
reusing the boundary ADR-0007 §9 already fixed. Generating a perceptual
hash is deterministic, non-inferential image processing with no ML model
involved — the same category of work ADR-0007 §12 already assigned to
Laravel jobs, not to the Python image-analysis service ADR-0001 reserves
for genuine AI inference from Phase 9 onward. Hash identity is versioned
exactly the way `MediaVariant` already is (ADR-0007 §11):
`(media_upload_id, algorithm, processing_version)`.

**This ADR fixes perceptual similarity's semantics, not its calibration.**
Perceptual similarity is advisory only, at every threshold, with no
automatic merge, deletion, or consolidation triggered by any score. The
specific algorithm and the specific threshold(s) that decide when a
`DuplicateCandidate` is worth generating are **not fixed by this ADR** —
they are an empirical, implementation-stage decision, made by testing
real candidate algorithms against representative fambam images, measuring
false positives and false negatives, and documenting the chosen
threshold, **before** `FPA-P08-S03` is implemented, per the sequence
`docs/IMPLEMENTATION_GUIDE.md` now fixes as an explicit gate. Freezing a
number here, ahead of that evidence, would be worse than not deciding
it at all.

### 9. Remembering an already-answered duplicate question

**If a family has already told fambam two Photos are meant to stay
separate — either by choosing "Create a new Photo" despite a known
match (§3/§4), or by dismissing a `DuplicateCandidate` as not a duplicate
(§8) — that pair must never be presented as an unresolved duplicate
again, unless an Owner or Administrator deliberately reopens it.** This
is a small, durable record of an answer already given, **not** a merge
or consolidation system:

```text
DuplicateDecision
- family_space_id
- photo_low_id    ⎫ canonical unordered pair identity:
- photo_high_id   ⎭ always stored low-ULID-first, so A-B and B-A
                     are always the same row
- source          exact_creation_choice | perceptual_review |
                   member_flagged_review
- decided_by
- decided_at
- reopened_by     (nullable)
- reopened_at     (nullable)
```

Both the exact-detection check (§3/§4) and the retrospective
candidate-generation process (§8) consult this table first and never
surface, or regenerate a `DuplicateCandidate` for, a pair with a
currently-settled row here — see below for exactly what "currently
settled" means once reopening exists.

**Creating a new Photo despite one or more known exact matches writes a
`DuplicateDecision` against every visible match that was actually
disclosed on that decision screen, not only the one the actor might have
been most focused on.** §3 already establishes that when several visible
Photos match, all of them are shown together, on one screen, before the
actor decides. Choosing "Create a new Photo" from that screen is a
single decision against everything shown, not a decision about one
match at a time — so it writes one `DuplicateDecision` row for each
(new Photo, disclosed match) pair, using the same canonical unordered
identity as any other pair, in the same transaction that creates the
Photo. This is what makes the choice deterministic when several matches
exist: nothing displayed on that screen can immediately resurface as a
fresh "possible duplicate" the moment the new Photo exists, whether from
§8's retrospective exact scan or from perceptual matching. Writing one
of these rows is idempotent — if a settled row already exists for a
given pair, writing it again changes nothing and raises no error. **A
`DuplicateDecision` is never written for a match that was not
disclosed** — an invisible match the actor was never shown, per §3, was
never part of what they decided, and gains no suppression from this
action. This bulk-writing rule applies identically to a direct Photo
creation and to an Album/Event contribution's "Create a new Photo"
outcome (§4); it does not apply to "Use existing Photo," which — per §3
— already requires the actor to name the one specific existing Photo
they mean, precisely because reuse is a decision about one Photo, not a
rejection of every alternative.

**A settled `DuplicateDecision` is durable history — the row is never
deleted, and the fact that a decision was once made is never erased —
but "reversible" means an authorized human may explicitly reconsider it,
not that the system quietly forgets or starts nagging again on its
own.** Owner or Administrator may **reopen** a settled pair: an explicit,
audited action, recorded on the same row (`reopened_by`/`reopened_at`
set), never by deleting or replacing it. A row with `reopened_at` set is
no longer "currently settled" — §3/§4's interactive check and §8's
retrospective/perceptual generation are both free to surface that pair
again, exactly as if it had never been decided, the next time detection
naturally runs. Reopening does not itself force an immediate suggestion
into existence; it only removes the suppression. If the pair is
reviewed again and settled the same way — dismissed again, or a fresh
"Create a new Photo" choice reaffirms it — the **same row** is reused:
`reopened_by`/`reopened_at` are cleared back to null and
`decided_by`/`decided_at` are refreshed, rather than a second row being
created for the same pair. There is no multi-stage reconsideration
workflow around this — reopening is one explicit action available to
Owner/Administrator, not a queue or a review state machine.

**Soft deletion and restoration never themselves reopen a
`DuplicateDecision`, and interact with it exactly as they do everywhere
else in this ADR.** If either Photo in a pair is currently soft-deleted,
the pair is not surfaced as a candidate at all — consistent with §8 —
but the underlying `DuplicateDecision` row, settled or reopened, is left
completely untouched by the deletion itself. If a Photo is later
restored, whatever state that pair's decision was already in — settled
or reopened — remains exactly as it was before the deletion; restoration
never resurrects a dismissed or already-settled pair as a fresh,
unresolved suggestion, and it never reopens one either. Reopening is
always a separate, deliberate Owner/Administrator action, never a side
effect of anything else in this ADR.

Exact physical schema, indexing, and whether `source` is stored as a
single column or split further remains implementation-guide detail; the
architectural commitment is the shape — one durable, canonically-ordered,
per-pair record of "already answered," reusable rather than replaceable
when reconsidered, consulted before ever asking again — not its physical
layout.

### 10. Authorization

| Action | Owner | Administrator | Member | Contributor | Guest |
|---|---:|---:|---:|---:|---:|
| See the three-outcome choice at direct Photo creation (§3, §4) | yes | yes | yes | n/a — no direct Photo-creation authority | n/a |
| See and resolve the three-outcome choice for their own Album/Event contribution (§4) | yes | yes | yes | yes, scoped to their own contribution and existing `AlbumGrant`/Event authority | yes, scoped to their own contribution and existing Event admission/`guest_participation` |
| Flag a possible duplicate | yes | yes | yes | no | no |
| View the archive-wide duplicate review surface | yes | yes | no | no | no |
| Dismiss / mark "not a duplicate" | yes | yes | no | no | no |
| Reopen a settled `DuplicateDecision` (§9) | yes | yes | no | no | no |

This is the exact same shape ADR-0006 §4/§12 already established for
Person duplicates — **Member may propose that two records may represent
the same thing; Owner or Administrator alone may confirm or dismiss that
claim** — applied here to Photos instead of People, not a new pattern.
The second row is the only place Contributor or Guest ever touches this
ADR's mechanism, and it grants nothing beyond what ADR-0008 §8/ADR-0009
§5/§13 already gave them for that specific contribution — never general
Photo-creation authority, and never access to any other pending hold. The
remaining rows are the same resource-scoped, no-Family-Space-wide-
directory-access baseline ADR-0008 §16 already fixed for Contributor and
Guest generally — this ADR does not loosen or narrow it.

### 11. Audit

`AuditEvent` records the human decisions this ADR introduces: a possible
duplicate flagged by a Member; a `DuplicateCandidate` dismissed; a
`MediaUploadDuplicateHold` created and, separately, resolved (recording
which of the three outcomes was chosen); a `DuplicateDecision` recorded,
including every row a multi-match "Create a new Photo" writes at once
(§9); a `DuplicateDecision` **reopened**, and, separately, re-settled.
**Automatic `DuplicateCandidate` generation is not itself an audited
event**, matching the same operational-versus-archival distinction
ADR-0007 §17 and ADR-0008 §17 already applied repeatedly — a background
process quietly computing a suggestion has no corresponding archival
value on its own; the moment it becomes meaningful is the moment a human
acts on it. Because there is no consolidation mechanism, there is no
consolidation event and no reversal event to audit — reopening is an
audited state transition on an existing `DuplicateDecision`, not a
reversal of anything else this ADR does.

### 12. Tenancy

`DuplicateCandidate`, `DuplicateDecision`, `MediaUploadDuplicateHold`, and
any perceptual-hash storage §8 requires are ordinary Class C tenant-owned
content tables under ADR-0005 §9.C, exactly as every Photo-domain table
has been since Phase 6. No executable RLS SQL is fixed here, for the same
reason every prior ADR in this series declined to freeze its own policy
implementation.

**Exact and perceptual duplicate comparison is strictly scoped to one
Family Space, always, with no exception.** A naive implementation that
compared checksums or perceptual hashes across the whole platform would
itself be a cross-tenant information leak — the mere fact that two
different families' archives happen to contain the same photograph is
exactly the kind of cross-tenant signal ADR-0005 exists to prevent from
crossing the tenant boundary in any form.

### 13. Explicit non-goals

- **Machine face recognition, face embeddings, or clustering** — Phases
  9/10 own these entirely.
- **Semantic image understanding or embeddings** — Phase 16's concern.
- **Event management, `PhotoPerson`, or search** — already decided
  (ADR-0009, ADR-0008 §5, and Phase 11 respectively).
- **Any automatic deletion, merge, or consolidation** — never built.
- **Any consolidation, redirect, runtime aggregation, metadata copying,
  inheritance, or merge engine of any kind, for any reason** — settled,
  final. §5's "no further resolution mechanism" is final. Resolving a
  flagged duplicate beyond dismissal always uses fambam's ordinary,
  already-existing Photo and Album tools, never a Phase-8-specific one.
- **A general duplicate-management workflow engine, review queue, or
  assignment system** — not built; §8's two-action review is sufficient.
- **A multi-stage reconsideration workflow around reopening a settled
  `DuplicateDecision`** — not built; §9's reopen action is one explicit,
  audited Owner/Administrator state transition, not a queue.
- **A new `MediaUpload` lifecycle state for a pending duplicate decision**
  — not built; §4's `MediaUploadDuplicateHold` is a small, separate
  record precisely so `MediaUpload`'s existing state machine (ADR-0007
  §4) never needs to change.
- **A numeric perceptual-similarity threshold fixed by this ADR** — not
  decided here; §8 fixes the semantics, the Implementation Guide fixes
  the empirical calibration gate.

## Alternatives considered

- **A redirect-based consolidation mechanism, or a Person-merge-style
  physical reassignment of Photo associations** — considered at length
  across earlier drafts of this ADR, and rejected on final, settled
  product direction: §3/§4's interactive choice already gives a family
  everything the product needs at the moment a duplicate is detected, and
  for cases it cannot catch, a lightweight, dismissible suggestion is
  sufficient.
- **Sharing one conversation across every Album a Photo belongs to** —
  rejected: conflates the memory (the Photo) with the experience of
  encountering it in one particular curated context (the Album).
- **Backfilling a guessed `album_id` onto existing `PhotoComment`/
  `PhotoReaction` rows, or copying them into every Album the Photo
  currently belongs to** — rejected: both would fabricate or duplicate
  provenance the data doesn't actually have; nullable `album_id` plus an
  unmodified legacy read-only surface preserves the truth of what's
  actually known about each row instead.
- **A required (`NOT NULL`) `album_id` on `PhotoComment`/`PhotoReaction`
  from the start, migrating legacy rows into a synthetic placeholder
  Album** — rejected: a placeholder Album would itself be a fabricated
  context, exactly what this reconciliation was asked to avoid; nullable
  is the honest representation of "we don't know which Album, if any,
  this predates."
- **Cascading the deletion of `PhotoComment`/`PhotoReaction` when a Photo
  is removed from an Album** — rejected: would silently destroy family
  conversation on an ordinary, already-reversible curation action,
  contradicting this project's consistent preference for preservation
  and reversibility; keying rows to the stable `(photo_id, album_id)`
  pair rather than to the `AlbumPhoto` link avoids needing any
  special-case preservation logic at all.
- **Adding a new `MediaUpload` state (e.g. `duplicate_pending`) for the
  asynchronous Album-contribution path** — rejected: `MediaUpload`'s
  state machine is Phase 5's already-accepted, already-proven boundary;
  a small, separate hold record achieves the same pause-and-resume
  behaviour without reopening it.
- **Silently picking the oldest or "best" existing Photo as the canonical
  match when several visible Photos share a checksum** — rejected: this
  ADR does not invent a canonical winner; disclosing every visible
  candidate and letting the actor choose is the direct, honest
  application of "assistant, not gatekeeper" to this exact situation.
- **Disclosing a checksum match that exists but that the current actor
  cannot view, even just to say "a duplicate exists somewhere"** —
  rejected: even that much is a real information leak about content the
  actor has no access to; from that actor's point of view the correct
  behaviour is indistinguishable from no duplicate existing at all.
- **A single combined table for both "pending decision blocking
  promotion" (§4) and "already-answered pair, don't ask again" (§9)** —
  considered and rejected: they answer genuinely different questions
  (one is transient and per-upload, the other is durable and per-Photo-
  pair) and conflating them would make each harder to reason about for a
  small saving in table count.
- **Fixing a specific perceptual-similarity algorithm or numeric
  threshold in this ADR** — rejected: threshold selection is an
  empirical implementation decision that needs real evidence against
  real images to make responsibly; freezing a number now, to satisfy
  stale planning-document wording, would be worse than deferring it
  properly to an explicit pre-implementation gate.
- **Relaxing `Photo.media_upload_id`'s uniqueness, or a merge/redirect
  system for consolidation** — both considered and rejected in earlier
  drafts of this ADR; see the Resolved Decisions of the prior
  reconciliation for the reasoning, unchanged and final here.
- **Making a settled `DuplicateDecision` permanently immutable, with no
  path to reconsider it** — rejected: contradicts the roadmap's own
  "reversible" exit criterion in its precise sense — an authorized human
  must be able to explicitly reconsider a decision — even though the
  underlying record of the original decision is itself never erased.
- **Deleting and recreating a `DuplicateDecision` row to represent
  reopening, rather than transitioning the same row** — rejected: would
  lose the durable, continuous history of the original decision the
  moment it's reopened, exactly the "the system forgets" outcome
  "reversible" is not supposed to mean; reusing the same row, mirroring
  the revoke/re-admit shape ADR-0009 §7 already established for
  `EventAdmission`, keeps one row per pair for its entire life.
- **Letting automatic detection (§8) itself decide to reopen a settled
  pair once it resurfaces a similar or identical match again** — rejected:
  would silently override a human's already-recorded decision without
  any person choosing to reconsider it, directly contradicting "automatic
  rediscovery must never silently override a settled decision."
- **Writing a `DuplicateDecision` against only one arbitrarily-chosen
  match when "Create a new Photo" is selected with several visible
  matches shown** — rejected: would leave every other match displayed on
  that same screen free to resurface immediately as a fresh suggestion,
  undermining the entire point of disclosing them together in the first
  place.
- **Writing a `DuplicateDecision` against every checksum match that
  exists, including ones the actor's own visibility filtered out and
  never saw** — rejected: the actor never decided anything about a match
  they were never shown; suppressing it on their behalf would silently
  claim a decision that was never actually made.
- **Requiring the actor to individually confirm "not this one" for each
  visible match before "Create a new Photo" is accepted** — rejected:
  adds friction to a choice whose meaning is already unambiguous once
  every visible match was shown together on one screen; the single
  choice already means "not any of these," and asking the actor to
  restate that once per match would be exactly the kind of unnecessary
  administration this ADR's own product philosophy avoids.

## Consequences

### Positive

- Mapping the three-outcome choice explicitly onto both real creation
  paths (§4) — including the asynchronous one that previously created
  Photos automatically with no way to ask a question — closes the one
  remaining gap between this ADR's settled product model and what the
  live repository actually does.
- The `MediaUploadDuplicateHold` record is deliberately small and
  strictly additive: `MediaUpload`'s own state machine, already proven
  since Phase 5, needs no change at all.
- Disclosing every visible candidate rather than inventing a canonical
  winner (§3) means the feature never has to guess on the family's
  behalf, and never leaks the existence of content the current actor
  can't already see.
- Preserving legacy `PhotoComment`/`PhotoReaction` rows with a nullable
  `album_id` and no data migration (§7) is the smallest possible change
  that avoids fabricating or duplicating history — a single column, no
  rewritten rows.
- `DuplicateDecision`'s canonical unordered pair identity (§9) means "has
  this pair already been settled" is one simple, symmetric lookup,
  consulted identically by both the interactive check and the
  retrospective candidate generator.
- Writing a `DuplicateDecision` against every disclosed match, not just
  one, when "Create a new Photo" is chosen with several visible matches
  (§9) makes that choice deterministic: nothing shown on the same screen
  can bounce straight back as a fresh suggestion.
- Representing "reversible" as an explicit, audited reopen action on the
  same durable row (§9), rather than deletion or automatic forgetting,
  gives Owner/Administrator genuine reconsideration without ever losing
  the history of the original decision or risking automatic rediscovery
  silently overriding a human's choice.
- Deferring the perceptual-similarity threshold to an explicit,
  documented, evidence-based gate before `FPA-P08-S03` (§8) means the
  number that ships is one that was actually tested against real images,
  not one invented to fill in an ADR.

### Negative

- The asynchronous contribution path (§4) now has one more possible
  outcome — a paused, unresolved hold — that the frontend must be able
  to surface and let the original uploader resolve; a small but real
  addition to that flow's surface area.
- `PhotoComment`/`PhotoReaction` now carry a genuinely two-shaped history
  — legacy, album-less rows and new, Album-scoped ones — that every
  future feature touching Photo conversation must remember to handle
  correctly, rather than a single uniform shape.
- `DuplicateDecision` and `MediaUploadDuplicateHold` are both genuinely
  new, small pieces of state, on top of `DuplicateCandidate` — three
  small tables rather than one, each answering a narrow, distinct
  question.
- A multi-match "Create a new Photo" can now write several
  `DuplicateDecision` rows in one action rather than one, real if small
  additional write volume in exchange for the determinism §9 requires.

### Risks

- If a future implementation computes or compares checksums or
  perceptual hashes without strict per-Family-Space scoping (§12), it
  would constitute a genuine cross-tenant information leak — worth a
  direct test.
- If the visible-candidates disclosure (§3) is implemented by checking
  existence before checking the actor's own visibility, rather than
  filtering to visible matches first, a duplicate could be revealed
  before the visibility check ever runs — worth a direct test asserting
  an invisible match behaves identically to no match at all.
- If `AlbumContributionFinalizer`'s hold (§4) is implemented so that
  resolving it can be triggered by anyone with ordinary Album-contribute
  authority rather than specifically the original uploader, one
  contributor could resolve another's pending duplicate decision on
  their behalf — worth a direct authorization test.
- If new `PhotoComment`/`PhotoReaction` writes are not enforced at the
  application layer to always set `album_id`, the nullable column meant
  only to preserve legacy rows could silently accumulate new,
  album-less rows — worth enforcing this as a service-layer invariant
  with a direct regression test, since the database column alone cannot
  distinguish "genuinely legacy" from "a bug."
- If `DuplicateDecision` lookups are only consulted by the interactive
  check (§3/§4) and not by the retrospective candidate generator (§8), or
  vice versa, a settled pair could still resurface through the path that
  forgot to check — worth testing both paths against the same decision
  row.
- If the perceptual-similarity calibration gate (§8, Implementation
  Guide) is skipped or treated as optional under schedule pressure, an
  untested threshold could ship, undermining the "explainable" and
  "advisory-only-in-practice" intent even though the architecture itself
  remains correct.
- If multi-match "Create a new Photo" is implemented as a loop that can
  partially fail — writing some `DuplicateDecision` rows but not
  others — an inconsistent suppression state could result; this must be
  atomic with Photo creation, in one transaction, not a best-effort
  fan-out.
- If reopening is implemented by inserting a second `DuplicateDecision`
  row for the same pair instead of transitioning the existing one, the
  canonical-unordered-pair uniqueness this ADR relies on throughout would
  be silently broken — worth a direct uniqueness-constraint test.
- If any automatic process — retrospective exact scanning or perceptual
  matching — is implemented to reopen a pair itself upon rediscovering a
  strong match, that would directly violate "automatic rediscovery must
  never silently override a settled decision" — reopening must remain
  reachable only through the explicit Owner/Administrator action in §10.

## Implementation notes

- **FPA-P08-S02** implements §3 (visible-candidates-only disclosure, no
  canonical winner), §4 (both creation paths, including
  `MediaUploadDuplicateHold` and its resolution endpoint), §6/§7
  (`PhotoComment`/`PhotoReaction` re-scoping: nullable `album_id`, legacy
  rows untouched, new writes always Album-scoped, no cascade on Album
  removal), §8's **exact-only** retrospective/backfill candidate
  generation (idempotent, `DuplicateDecision`-suppressed, for Photos
  §3/§4's interactive check never saw), and §9 (`DuplicateDecision`,
  including the multi-match bulk-write on "Create a new Photo," and its
  reuse-by-both-detection-paths suppression rule). Uses the existing
  frozen `MediaUpload.original_sha256`; introduces no new checksum
  mechanism and no new `MediaUpload` state. **S02 does not implement any
  perceptual work** — that is S03's alone.
- **FPA-P08-S03** implements §8's **perceptual-only** half: hash
  generation as a Laravel job, versioned `(media_upload_id, algorithm,
  processing_version)`, operating on the canonical asset per ADR-0007 §9,
  and perceptual `DuplicateCandidate` generation consulting
  `DuplicateDecision` before creating a candidate. **Before this stage
  begins**, the Implementation Guide's algorithm/threshold calibration
  gate (choose a candidate algorithm, test it against representative
  fambam images, measure false positives and negatives, document the
  selected threshold) must be complete — this stage implements the
  calibrated choice; it does not make it. Exact-match candidate
  generation, retrospective or otherwise, is already complete by the time
  this stage begins — S03 never duplicates or extends S02's exact-match
  work.
- **FPA-P08-S04** implements §8's `DuplicateCandidate` review surface
  (ignore/dismiss only, writing a `DuplicateDecision` on dismissal), §9's
  **reopen** action (Owner/Administrator only, transitioning an existing
  row rather than creating or deleting one), §10 (the authorization
  table), and §11 (audit coverage).
- **Required regression tests**: (1) two exact-checksum-matching uploads
  in different Family Spaces must never surface a `DuplicateCandidate` or
  any other cross-tenant signal; (2) a checksum match backed by a Photo
  the current actor cannot view must be treated identically to no match
  at all, for both creation paths; (3) with two or more visible matching
  Photos, all must be disclosed and the actor's chosen one, specifically,
  must be the one used for "Use existing Photo"; (4) "Create a new Photo"
  with two or more visible matches must write a `DuplicateDecision`
  against every disclosed match, atomically with Photo creation, and must
  not write one against a match that existed but was not disclosed; (5)
  writing a `DuplicateDecision` for a pair that already has a settled row
  must be idempotent — no error, no duplicate row; (6) an Album-
  contribution duplicate hold must only be resolvable by the original
  uploader, using their existing contribution authority, never broader;
  (7) the same Photo added to two different Albums must carry two
  independent, empty-until-used comment/reaction threads; (8) a legacy
  (`album_id IS NULL`) `PhotoComment`/`PhotoReaction` row must appear only
  on the Photo's own direct page, never inside any Album view; (9)
  removing a Photo from an Album and re-adding it must restore exactly
  its prior `(photo_id, album_id)` conversation, unchanged; (10) a
  settled `DuplicateDecision` must suppress both the interactive check
  and retrospective/perceptual candidate generation identically; (11)
  reopening a `DuplicateDecision` must be Owner/Administrator-only,
  audited, must transition the existing row rather than create a second
  one, and must make the pair eligible for normal detection again without
  itself forcing an immediate suggestion; (12) soft-deleting either Photo
  in a decided pair must not surface it as a candidate and must not
  affect the decision's settled/reopened state either way; restoring it
  must not resurrect a dismissed or already-settled pair, and must not
  reopen one either.
- §1's unchanged cardinality and §12's tenancy scoping are cross-cutting:
  every stage above should be checked against both during review.

## Review triggers

- **When Phase 9/10 (face analysis, clustering) are scoped**: confirm
  perceptual-hash infrastructure (§8) is not silently repurposed for face
  embeddings.
- **When Phase 11 (search) is scoped**: confirm search treats each
  independently-created Photo, and each Album's independent
  conversation, as exactly what they are.
- **When Phase 16 (semantic image search) is scoped**: revisit whether
  perceptual-similarity infrastructure (§8) should be unified with
  semantic embedding infrastructure, or remain separate — not decided
  here.
- **If real usage shows a standalone Photo with no Album needs a comment
  surface after all** — revisit deliberately, as its own decision, rather
  than quietly relaxing `album_id` to permit new album-less rows.
- **If real usage repeatedly shows families wanting to combine two
  independently-created Photos after the fact** — this is the one trigger
  under which any consolidation-shaped mechanism should even be
  reconsidered, and it must be as a new, separate ADR-level decision, not
  a quiet addition to this one.
- **After the first real calibration pass (§8, pre-`FPA-P08-S03`)**:
  record the chosen algorithm and threshold, and the evidence behind
  them, in `docs/IMPLEMENTATION_GUIDE.md` before implementation begins,
  not after.
- **If real usage shows Owner/Administrator reopening `DuplicateDecision`
  pairs often enough to suggest the original decision-making moment
  itself needs improvement** — revisit that moment (§3/§4's disclosure or
  interaction), not §9's reopen mechanism itself, which is deliberately
  minimal and should stay that way.

## Deferred concerns

- The exact `DuplicateCandidate`/`DuplicateDecision`/
  `MediaUploadDuplicateHold` physical schema and indexing — the shape is
  fixed, the columns are not.
- The exact perceptual-hashing algorithm and its similarity threshold(s)
  (§8) — a functional requirement is fixed; the algorithm and number are
  an explicit pre-`FPA-P08-S03` calibration gate, not fixed here.
- The exact set of advisory ranking signals used to explain a perceptual
  suggestion (§8) — not decided here.
- The exact `PhotoComment`/`PhotoReaction` migration mechanics beyond the
  fixed shape in §7 — column addition, indexing, and constraint syntax
  remain implementation-guide detail.

## Resolved decisions

1. **Media vs. memory** — `MediaUpload` (one preserved upload event) and
   `Photo` (one family's interpretation of it) remain architecturally
   distinct.
2. **`Photo.media_upload_id` cardinality — unchanged** — remains
   required, one-directional, and unique, exactly as ADR-0008 §1 already
   fixed.
3. **Disclosure and multiple matches** — only checksum matches visible to
   the current actor are ever disclosed; an invisible match behaves as no
   match at all; when several visible Photos match, all are shown and the
   actor chooses; no canonical winner is invented.
4. **The three-outcome choice, mapped to both creation paths** — "Use
   existing Photo," "Create a new Photo," "Cancel," applied identically
   in effect to direct Photo creation (no Album) and Album/Event
   contribution (existing Photo added to the target Album, or new Photo
   created and added to it). Neither path grants new Photo-creation
   authority beyond what each already had. "Use existing Photo" requires
   naming the one specific existing Photo meant, when several visible
   matches exist; "Create a new Photo" is a single decision against every
   visible match shown on that screen, not a per-match decision.
5. **`MediaUploadDuplicateHold`** — a small, separate record pausing the
   asynchronous Album-contribution finalizer when a visible duplicate is
   detected, resolvable only by the original uploader using their
   existing contribution authority; `MediaUpload`'s own state machine is
   unchanged.
6. **No further resolution mechanism** — no consolidation, redirect,
   aggregation, or merge of any kind is built or deferred within Phase 8.
7. **Comments and reactions are Album-scoped, not Photo-scoped** — a
   narrow, explicit revision to ADR-0008 §11/§12: every new
   `PhotoComment`/`PhotoReaction` is keyed to `(photo_id, album_id)`; the
   same Photo in different Albums carries genuinely independent
   conversations. `PhotoStory` is unaffected and remains Photo-scoped.
8. **Legacy conversation preservation** — `album_id` is nullable to
   honestly represent rows that predate Album-scoping; those rows are
   never backfilled, guessed at, or duplicated, and are exposed only on
   the Photo's own direct page, read-only; removing a Photo from an Album
   never destroys that Album's conversation for it, and re-adding it
   restores that conversation automatically.
9. **`DuplicateDecision`** — a durable, canonically-ordered
   (`photo_low_id`/`photo_high_id`) record that a pair has already been
   answered, written when "Create a new Photo" is chosen despite one or
   more known matches (one row per disclosed match, idempotent, never
   against an undisclosed match) or when a `DuplicateCandidate` is
   dismissed; consulted before ever surfacing that pair again. Never
   itself touched by soft deletion or restoration. **Reversible means an
   Owner or Administrator may explicitly reopen a settled row — an
   audited transition on the same row (`reopened_by`/`reopened_at`),
   never deletion or a second row — after which the pair is eligible for
   normal detection again; automatic rediscovery may never itself reopen
   or override a settled decision.**
10. **Retroactive exact matches and perceptual similarity** — both
    surface as `DuplicateCandidate` suggestions only, never facts; review
    is limited to ignore/dismiss, with no consolidate action.
    Retrospective/backfill **exact** candidate generation is owned by
    `FPA-P08-S02`, alongside interactive detection; **perceptual**
    candidate generation is owned by `FPA-P08-S03` alone. Perceptual
    hashing is Laravel-owned, deterministic, canonical-asset-based,
    versioned like `MediaVariant`; its algorithm and threshold are an
    explicit empirical calibration gate before `FPA-P08-S03`, not fixed
    by this ADR.
11. **Authorization** — Member may flag; Owner/Administrator alone
    review, dismiss and reopen; Contributor and Guest may see and resolve
    the three-outcome choice only for their own scoped contribution,
    using authority they already had, and have no archive-wide duplicate
    review.
12. **Audit** — flagging, dismissal, hold creation/resolution, decision
    recording (including every row a multi-match bulk write creates), and
    reopening are all audited; automatic candidate generation is not;
    there is no consolidation or reversal event because there is no
    consolidation mechanism — reopening is a state transition, not a
    reversal of anything else this ADR does.
13. **Tenancy** — ordinary Class C tenant-owned tables under ADR-0005;
    exact and perceptual comparison are strictly scoped to one Family
    Space, with no exception.
14. **Non-goals** — face recognition, semantic embeddings, Event
    management, `PhotoPerson`, search, any automatic deletion/merge, any
    consolidation/redirect/aggregation/merge engine, any new `MediaUpload`
    lifecycle state, and any numeric threshold fixed by this ADR are all
    explicitly not built or decided in Phase 8.
