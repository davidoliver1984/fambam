# ADR-0008: Photo Domain, Provenance and Organisation

- Status: Accepted
- Date: 2026-08-24 (amended 2026-08-24 — pre-implementation reconciliation)
- Decision owners: David
- Related stages: FPA-P06-S01 (accepting this ADR completes that stage),
  implemented by FPA-P06-S02, FPA-P06-S03, FPA-P06-S04, FPA-P06-S05,
  FPA-P06-S06

## Context

Phase 5 (ADR-0007) built the infrastructure boundary a photograph's bytes
pass through — `MediaUpload` and `MediaVariant` — and deliberately decided
nothing about what those bytes *mean* to a family: it named uploader,
photographer, scanner and historical-date provenance as explicitly Phase 6
concerns, and fixed only the boundary Phase 6 must inherit (a nullable,
unique `Photo.media_upload_id` referencing forward at most one
`MediaUpload`; the EXIF capture timestamp is technical metadata Phase 6
must never silently promote to an authoritative historical date). Phase 4
(ADR-0006) separately fixed a semantic contract Phase 6 was always going to
need again — the uncertain-date concept (exact / month-year / year / decade
/ approximate / unknown) — and the proposed-versus-authoritative pattern
that governs who may write which class of identity-bearing fact about a
`Person`. `PROJECT_ROADMAP.md`'s Phase 6 objective is "model photographs as
family archive records rather than anonymous files"; its exit criteria fix
four concrete guarantees this ADR must make architecturally true: one photo
can appear in many collections without asset duplication; technical and
family metadata are clearly separated; provenance is retained through
edits; historical dates can represent exact, month-only, year-only and
approximate values.

This ADR is also where the product's role model gets its first genuinely
resource-scoped test. ADR-0005 fixed five roles and explicitly deferred
Contributor and Guest's concrete meaning to "a Phase 5/6/7 decision, not
this one." ADR-0007 §16 could not give Contributor a Phase 5 upload path
because Phase 5 "has no Album, Event, or other resource to scope a
Contributor grant against," and named that gap as a placeholder Phase 6 or
7 must revisit with a real resource in view. Albums are that resource.

Phase 6 also introduces the first genuine visibility decision below the
Family Space boundary itself: a Photo, and the Album that may contain it,
can each be more restricted than ordinary Family-Space-wide access. Because
Phase 5's delivery endpoints (`MediaUploadPolicy`, per ADR-0007 §15) only
ever understood Family Space role, and a `MediaUpload`'s ULID is a stable,
guessable-shaped identifier once a Member has legitimately seen it once,
this ADR must also decide how those endpoints behave once a `MediaUpload`
acquires a Photo — this is a security-relevant non-bypass guarantee, not an
incidental implementation detail, and belongs here rather than being left
to whichever stage happens to touch the delivery controller next.

This ADR deliberately does not decide anything about: duplicate/visual-hash
linkage between Photos (Phase 8); Events, Event Albums, or guest
contribution links (Phase 7); machine face detection, embeddings, clusters
or suggested identity (Phases 9–10); or persisted/saved dynamic views
(Phase 11). Where this ADR's scope brushes against those — Album as a
resource Phase 7 will extend, `PhotoPerson` as a human-confirmed table
Phase 9/10 must not overwrite — it fixes the boundary those later decisions
must inherit, not their implementation.

**Amendment note (2026-08-24).** A pre-implementation review ahead of
FPA-P06-S02 identified genuine ambiguities in the version of this ADR
originally accepted the same day: a conflated provenance field, a
temporary delivery-security gap between the stage that introduces
`Photo.visibility` and the stage that was going to enforce it, two
roadmap-scoped concerns (location, tags) that had no decision section at
all, an underspecified `AlbumGrant`/original-download interaction, and a
few authority questions (default visibility, who may create a Photo) left
implicit rather than stated. This is a bounded clarification pass over the
same accepted architecture, not a redesign — no prior decision is
reversed, and every amendment below either splits an over-loaded concept,
tightens a sequencing boundary, or fixes a gap the roadmap already
committed to. Section numbers shifted to accommodate two new sections
(§4 location, §6 tags); no numbered decision from the original text was
removed.

## Decision

### 1. Photo ↔ MediaUpload: required, unique, one-directional

**`Photo.media_upload_id` is required and unique.** A `Photo` cannot exist
without a trusted, `ready` (or later-equivalent) media asset behind it —
this is the concrete meaning of "Fambam preserves the exact original
media... while deliberately controlling which derived representations...
are exposed," applied to the Photo domain: there is no such thing as a
Photo record floating free of the asset it presents. The reference remains
exactly as ADR-0007 §2 fixed it:

```text
Photo -> MediaUpload
```

never the reverse, and **one `MediaUpload` backs at most one `Photo`** in
V1 (enforced by the uniqueness constraint, not merely by convention). A
`ready` `MediaUpload` may exist indefinitely without ever becoming a
`Photo` — an Owner may decline to promote an upload during review, or a
Member may have uploaded the wrong file — and Phase 6 introduces no
mechanism that forces or assumes promotion.

This is a straightforward continuation of ADR-0007 §2, restated here
because it is the single fact every other section of this ADR depends on:
provenance, dates, visibility and deletion are all properties of the
`Photo` row, never of the `MediaUpload` row it references.

**`Photo.created_by` identifies the Family Space member who created the
Photo record from an existing `MediaUpload`. It is intentionally distinct
from `MediaUpload.user_id`, which identifies who uploaded the bytes.** The
uploader transfers media; the creator establishes archival meaning — the
two acts are conceptually different and are frequently performed by the
same person, but are not guaranteed to be (an Owner promoting an upload
someone else made, for instance). This distinction is fixed here, as a
core `Photo` schema fact rather than an incidental column, because §7's
and §8's contribution/visibility authority and §9's deletion/restoration
authority all depend on `created_by` specifically, and must never be
derived from `MediaUpload.user_id`.

**Who may create a Photo from a `ready` `MediaUpload`:**

- A **Member** may create a Photo only from their **own** `ready`
  `MediaUpload` — one they themselves uploaded. A Member has no authority
  to promote another member's upload into a Photo.
- **Owner or Administrator** may promote **any** `ready` `MediaUpload` in
  the Family Space into a Photo, regardless of who uploaded it — the same
  administrative floor these two roles hold throughout ADR-0004 through
  ADR-0007.
- A **Contributor** holds no general Photo-creation authority of this
  kind. A Contributor may create a Photo only as an inseparable part of
  contributing to a specific Album on which they hold
  `AlbumGrant.can_contribute` (§8) — from their own `ready` `MediaUpload`,
  resulting in a Photo that is created already attached to that Album. A
  Contributor may never create a freestanding Photo outside that scoped
  contribution action, and never from another member's `MediaUpload`.
- **Guest** may not create a Photo in Phase 6, consistent with Guest's
  baseline throughout ADR-0005/0006/0007.

This keeps uploader ownership and archive administration consistent:
ordinary Members curate their own uploads into the archive; Owner and
Administrator retain the ability to promote uploads that would otherwise
sit unpromoted (an elderly relative who uploads but never gets to "create
the Photo" step, for example); Contributor's narrower trust level is
reflected in a narrower, resource-scoped creation path rather than a
general one.

### 2. Provenance

Phase 6 tracks two genuinely different kinds of information about where a
photograph came from, and this ADR keeps them structurally separate
rather than sharing one field or one authority model.

**Identity-bearing provenance.** Four distinct parties can be associated
with a photograph:

- **Uploader** — who transferred the bytes. Already known: `MediaUpload
  .user_id` (ADR-0007 §2). **Not duplicated on `Photo`.** Any surface that
  needs "who uploaded this" reads it via the `Photo → MediaUpload`
  reference; there is no second source of truth to drift out of sync with
  the first.
- **Photographer** — who took the original picture.
- **Scanner / digitiser** — who scanned or digitised a physical original.
- **Original physical owner** — whose print, album or collection the
  image belonged to (for example: "Mum," "Grandad").

The latter three are **identity-bearing provenance claims**, not
uploader-derived facts, and each is **single-valued in V1**. Each may be
represented as:

- a nullable `Person` reference, where the individual already exists in
  the archive; or
- a mutually-exclusive free-text fallback, where reifying the party as a
  `Person` record would be inappropriate or premature (an unnamed scanning
  service, a photographer nobody can identify, an owner known only by a
  relationship term).

Because these three roles are identity-bearing claims about (often
deceased, often account-less) third parties — exactly the risk ADR-0006
§4 and §7 already reasoned about for Person details and relationships —
**they follow the same Member-proposes / Owner-or-Administrator-confirms
pattern ADR-0006 §4 established**, applied here without modification: a
Member may propose a photographer, scanner or physical-owner claim (as a
Person reference or free text); only Owner or Administrator may confirm it
as authoritative, correct it, or replace a confirmed claim. This is the
same architectural distinction ADR-0006 fixed once and Phase 4 already
told Phase 6 to expect reusing (ADR-0006's Review triggers). The exact
column names backing the photographer, scanner and physical-owner
free-text fallbacks are implementation-guide detail, with one fixed
constraint: none may reuse a name Phase 6 or a later phase gives to a
different concept (`Album`, `Event`, `Person`, or the archive-source
concept immediately below).

**Archive source description — ordinary content, not an identity claim.**
The physical container or collection a photograph came from — "Green
family album," "Box labelled Spain," "Envelope from Aunt Jean" — is
**archival context, not a claim about who a person is.** An earlier
version of this ADR represented this alongside the original-physical-owner
identity claim under one shared field; this amendment separates them
explicitly, because they are genuinely different things governed by
genuinely different authority: identifying *whose* photo album something
came from is a provenance claim about a person (§2's identity-bearing
rules above apply); describing *which physical container* it came from is
ordinary archival narrative, exactly like a caption. The field is named
**`archive_source_description`** — a free-text field, Family-Space-scoped
in the trivial sense every Photo field is, requiring **no
proposed/authoritative workflow**.

**Caption, contextual description, and `archive_source_description` are
all ordinary attributed family content, not identity-bearing claims about
a third party, and none require an approval workflow.** The Photo's
creator (§1) may write or correct these directly. This mirrors the
distinction ADR-0006 §2 already drew for Person biography/notes: shared
archival content everyone with view access can see, contributed without a
confirmation gate, as opposed to identity claims about a person who cannot
necessarily speak for themselves.

### 3. Historical photograph date

The Photo's human-supplied historical date reuses **ADR-0006 §3's
uncertain-date semantic contract unmodified**: exact date, month and year,
year only, decade, approximate, or unknown. This is exactly the reuse
ADR-0006 anticipated and deliberately did not design around — the concept
is a pure date-precision primitive with no Person-specific lifecycle
semantics baked in, so it applies to a photograph's date with no
extension required.

This historical date is **strictly separate from `MediaUpload`'s EXIF
capture timestamp** (ADR-0007 §10) and **must never be silently populated
or confirmed from EXIF**. ADR-0007 §10 already named this as "the single
most likely shortcut a future implementation could quietly take under time
pressure"; this ADR restates it as a Phase 6 obligation rather than
merely a Phase 5 warning, because Phase 6 is where the shortcut would
actually be taken. A photo scanned today of a 1987 print carries today's
EXIF timestamp and a proposed-then-confirmed 1987 historical date — the
two fields must be able to disagree by design, not by bug.

The historical date follows §2's same proposed/authoritative pattern: a
Member may propose it; Owner or Administrator confirms or corrects the
authoritative value. It is implemented in **FPA-P06-S03**, alongside
human-supplied location (§4) and `PhotoPerson` (§5).

### 4. Human-supplied location

`PROJECT_ROADMAP.md`'s Phase 6 scope names "Locations" explicitly, and
`PRODUCT_VISION.md`'s own family metadata list includes "where it was
taken" alongside the approximate date. This ADR fixes a deliberately
lightweight model for it, matching the discipline already applied
elsewhere in this document:

- **human-supplied, free text** — "Blackpool," "Grandma's house," "Lake
  District" — not a structured place record;
- **strictly separate from `MediaUpload`'s GPS coordinates** (ADR-0007
  §10), for exactly the reason §3 already applies to the historical date:
  a technical, machine-captured fact and a human-confirmed archival fact
  must remain independently sourced, never silently derived from one
  another;
- **the same proposed/authoritative pattern** as the historical date: a
  Member may propose a location; Owner or Administrator confirms or
  corrects the authoritative value.

**No GIS, no coordinates, and no reverse geocoding are introduced.** This
is a human narrative field, not a places database — the same
"functional requirement fixed, representation deferred" discipline
ADR-0006 §3 already applied to uncertain dates applies here: the exact
storage shape (a column pair, a small side table) is implementation-guide
detail. Implemented in **FPA-P06-S03**, alongside historical date (§3) and
`PhotoPerson` (§5).

### 5. Photo ↔ Person identity (`PhotoPerson`)

`PhotoPerson` — the claim that a given `Person` appears in a given Photo —
is implemented in **FPA-P06-S03**, using the same established
proposed/authoritative pattern:

- A Member may propose that a Person appears in a Photo.
- Owner or Administrator confirms or rejects the proposal.
- **Only confirmed `PhotoPerson` rows belong to the authoritative
  human-knowledge graph.** Proposed and rejected associations are retained
  for provenance but do not become human ground truth, do not appear in
  any "appears in N photographs" count (`PRODUCT_VISION.md`'s People-page
  example), and are not treated as confirmed by any dynamic view (§13).

**Later machine-recognition output (Phase 9/10's `DetectedFace`,
`FaceCluster`, suggested identity) must remain structurally separate from
`PhotoPerson` and may never overwrite it.** This restates, for Photo, the
same non-overwrite guarantee `PRODUCT_VISION.md` already states for
recognition generally ("the product workflow should assume that AI is
fallible") and gives Phase 9/10 the concrete boundary to inherit: a
machine suggestion may become a *proposed* `PhotoPerson` row for a human
to confirm through the existing pattern, but it may never write directly
into the confirmed state. Phase 6 fixes this boundary; designing the
suggestion-ingestion mechanism itself is Phase 9/10's job.

### 6. Photo tags

`PROJECT_ROADMAP.md`'s Phase 6 scope also names "Tags and family-supplied
metadata" explicitly. This ADR fixes a deliberately lightweight
organisational tagging concept, distinct from both `PhotoPerson` (§5,
identity-bearing, propose/confirm) and Albums (§8, curated collections
with their own visibility and contribution model):

- **free-text tags** — "Christmas," "Holiday," "Pets," "Cars," "School";
- **Family-Space-scoped** — a shared tag vocabulary reused across the
  archive (a `Tag` table keyed to `family_space_id`, joined to `Photo`
  through a many-to-many `PhotoTag` relation), so the same word isn't
  independently retyped, and inconsistently, on every photo; exact
  schema is implementation-guide detail;
- **ordinary Member-editable** — any Member with view access to a Photo
  may add or remove its tags, and any Member may introduce a new tag
  label on the fly while tagging — there is no separate "create a tag"
  approval step;
- **no proposed/authoritative workflow** — tags are organisational
  metadata, not identity or provenance claims, and follow §2's
  ordinary-content treatment, not §2's identity-bearing-claim treatment;
- **no taxonomy engine and no hierarchy** — flat labels only, mirroring
  the same fixed-vocabulary-but-deliberately-simple discipline already
  applied to reactions (§12) and, in ADR-0006 §7, to relationship types.

Tags carry no visibility of their own — a Photo's tags are reachable
wherever the Photo itself is reachable (§7), and nowhere else. **Tags are
not a replacement for Albums**: a tag is a lightweight, unordered,
non-curated label with no audience concept; an Album is a curated,
ordered, explicitly-shared collection with its own visibility and
contribution model. Implemented in **FPA-P06-S02**, alongside caption,
description and `archive_source_description` (§2), as ordinary Photo
content requiring no approval workflow.

### 7. Photo visibility — intrinsic visibility vs. explicit sharing

**A Photo's own visibility and an Album's sharing audience are separate
concerns, decided independently, and this ADR keeps exactly one mechanism
for each rather than two overlapping grant systems for the same
resource.** Every rule in this section is one application of that
principle.

`Photo.visibility` has exactly two values:

```text
family_space  — directly visible to ordinary roles with Family Space
                photo-directory access (the Owner/Administrator/Member
                default already established for Person, ADR-0006 §10)
private       — directly visible only to the Photo's creator (§1) and to
                Owner/Administrator
```

**A new Photo defaults to `family_space`.** Private is an explicit
decision the creator (or Owner/Administrator) must make, not the starting
state — consistent with this product's collaborative, family-archive
ethos everywhere else visibility defaults have already been decided
(ADR-0006 §10, ADR-0007 §16). The one deliberate exception: **a Photo
created via a Contributor's scoped Album contribution (§1, §8) defaults
to `private`, not `family_space`.** Defaulting such a Photo to
Family-Space-wide visibility would immediately broadcast it beyond the one
Album the Contributor was actually granted access to, defeating the entire
purpose of scoping their contribution — reachability for everyone else
then flows only through that Album (directly, or via further widening),
exactly as this section already models for any other private Photo.

No separate `restricted` Photo tier and no second Photo-level grant table
are introduced in V1. Selected-member sharing lives entirely in **Albums**
(§8), which already need their own audience concept; adding a parallel
`photo_grants` mechanism for the same purpose would mean two independently
maintainable systems answering the same question ("who can see this
Photo?") and a real risk of them disagreeing.

**Explicit Album widening.** A private Photo may later be added to an
Album whose audience is broader than the Photo's own direct audience —
this is the mechanism that realises "private by default, explicitly
shared later" as a real, supported workflow rather than a dead end. Doing
so is an **explicit visibility-widening operation**, not an incidental
side effect of ordinary Album curation, and the implementation must:

- **authorize** the actor performing the addition to widen that specific
  Photo's reachability (not merely to edit the Album);
- make the widening **visible in the UI** at the moment it happens — the
  person adding a private Photo to a broader Album must be told, before
  or as they do it, that the Photo becomes reachable by that Album's
  audience;
- be covered by a **regression test proving that knowing a Photo's or its
  `MediaUpload`'s identifier alone cannot bypass the Album/Photo
  authorization model** — this is the same class of guarantee §10
  requires at the delivery layer, tested here at the domain layer.

**The inverse holds with equal force.** Removing the Photo from every
Album that widens its audience immediately removes those additional
access paths and restores the Photo's intrinsic visibility — nothing
further to authorize, signal, or clean up, because nothing was ever
written to the Photo itself. Album membership is therefore an
**additional, explicit access path** layered on top of `Photo.visibility`;
it never mutates the Photo's own `private`/`family_space` value in either
direction. A Photo's reachability is always the union of its own intrinsic
visibility and whatever currently-live Album memberships widen it —
recomputed from live state, not from a cached or once-granted flag — so
removing the last widening Album narrows reachability back down
automatically, by construction, not through a separate revocation step.

### 8. Albums and Contributor's first concrete meaning

An `Album`:

- belongs to exactly one Family Space;
- has a creating/controlling member, `created_by`, acting as **curator**
  of an in-tenant collection — not as the owner of a separate tenant
  boundary (Family Space remains the only tenant boundary, per ADR-0005);
- contains Family Space Photos through a many-to-many `AlbumPhoto`
  relation, supporting ordered explicit collections and satisfying the
  roadmap's "one photo can appear in many collections without asset
  duplication" exit criterion directly — `AlbumPhoto` references `Photo`,
  never copies it;
- has its own visibility, independent of any single Photo it contains:
  **private**, **selected**, or **`family_space`** (the same default tier
  Photo and Person already use).

**Private and Selected are genuinely distinct tiers, not two names for
the same mechanism.** A **private** Album has **no `AlbumGrant` rows at
all** — it is reachable only by its creator and by Owner/Administrator,
full stop, exactly like a private Photo with no widening Album. A
**selected** Album's reachability is governed entirely by its
`AlbumGrant` rows — every membership that can view or contribute to it
holds an explicit grant; there is no implicit "selected but everyone can
still see it" middle state. Moving an Album between `private` and
`selected` is itself an ordinary Album-visibility change, subject to the
same creator/Owner/Administrator authority as any other Album management
action — a private Album intended to be deliberately shared with specific
people is switched to `selected` and given `AlbumGrant` rows, not left
`private` with grants quietly attached to it.

**Visibility and contribution are separate concerns**, matching §7's
governing principle applied one level up. The mechanism is an explicit
grant:

```text
AlbumGrant
- album_id
- family_space_membership_id
- can_view
- can_contribute
```

**Contribution always implies view access.** `can_contribute = true` with
`can_view = false` is not a valid `AlbumGrant` state — an implementation
must not construct, persist, or authorize against that combination.
Every `AlbumGrant` is therefore either `(can_view = true, can_contribute =
false)` or `(can_view = true, can_contribute = true)`; there is no
contribute-without-view grant, because contributing to a collection you
cannot see is incoherent on its own terms. The exact enforcement mechanism
(a database constraint, a service-layer invariant, or both) is
implementation-guide detail; the invariant itself is fixed here.

`AlbumGrant` realises a **selected**-visibility Album's audience directly
(one row per granted membership) and is also how a role that has no
Family-Space-wide default gets Album-scoped access at all. Its exact
physical schema (indexes, uniqueness, cascade behaviour) is
implementation-guide detail; the architectural commitment is the
mechanism — one explicit, resource-scoped grant carrying independent
view/contribute bits — not its physical layout.

**Membership reactivation.** ADR-0005 §5 already fixed that a removed
Family Space membership, on reinvitation, is **reactivated in place** —
same row, same identifier, role and removal metadata reset, never a new
row. Because `AlbumGrant.family_space_membership_id` references that same
enduring membership identifier, **an existing `AlbumGrant` remains
attached to a reactivated membership automatically, with no Phase 6
reconciliation step required**, unless it is deliberately revoked. This
provides the natural continuity ADR-0005 already intended: a member
temporarily removed and later reinstated does not lose Album grants they
were never deliberately stripped of.

**Default contribution authority on a `family_space`-visibility Album is
a product policy, fixed here rather than left to the implementation
guide:**

```text
Family-space visibility Album

Owner            contribute — yes (administrative floor)
Administrator    contribute — yes (administrative floor)
Album creator    contribute — yes (curator)
Member           contribute — yes (default)
Contributor      contribute — only through an explicit AlbumGrant
Guest            contribute — no
```

Ordinary Members contribute to a `family_space`-visibility Album by
default, with no `AlbumGrant` row required — this keeps the archive
collaborative for the role the product already trusts with ordinary
Family Space content everywhere else (ADR-0006 §10, ADR-0007 §16), and
avoids turning every ordinary Album into a curator-plus-explicit-grant
bottleneck. This default applies **only** to `family_space`-visibility
Albums. On a **private** or **selected** Album, contribution authority
does not widen beyond Owner, Administrator, the creator, and whichever
memberships hold an explicit `AlbumGrant.can_contribute` — including an
ordinary Member, who receives no default contribution authority on an
Album they cannot already view by default.

**This is Contributor's first concrete resource-scoped meaning**, closing
the placeholder ADR-0005 opened and ADR-0007 §16 explicitly deferred:

- Contributor receives **no** Family-Space-wide Photo or Album directory
  access by default — the same "no default" baseline already fixed for
  Contributor throughout ADR-0005/0006/0007.
- Contributor may be explicitly granted `can_view` on a specific Album via
  `AlbumGrant`.
- Contributor may additionally be granted `can_contribute` on that same
  Album.
- That authority is scoped to exactly the one Album it was granted on — it
  confers no Family-Space-wide Photo visibility and no access to any other
  Album, and — unlike Member — Contributor never receives it by default on
  a `family_space`-visibility Album either.

**Contributor upload path.** ADR-0007 §16 deliberately deferred
Contributor upload authority "until a concrete contribution surface
exists (an Album, an Event, or an equivalent Phase 6/7 resource)." Albums
are that resource, and this ADR fulfils that placeholder rather than
contradicting it: **a Contributor may upload media, and create the Photo
that results from it, only through an Album on which they hold
`AlbumGrant.can_contribute`** — per §1, that Photo is created already
attached to the granting Album. A Contributor never receives
Family-Space-wide upload capability; their upload authority is exactly as
narrow, and scoped to exactly the same resource, as every other
Contributor authority this section fixes.

**Original download is not part of what Album access grants.**
`AlbumGrant` — whether it resolves a Photo's own `family_space`/`private`
visibility or additionally widens a private Photo's reach — governs
**presentation access only**: the canonical asset and presentation
variants. It does **not** automatically grant preserved-original
download. Original download continues to follow ADR-0007 §16's own
role-based policy (Owner, Administrator and Member; never Contributor or
Guest), evaluated independently of any Album-derived access path — see
§10 for the full delivery-layer statement of this rule.

**Guest remains deferred to Phase 7's Event/guest-access model**, per its
existing baseline throughout ADR-0005/0006/0007 — Phase 6 grants Guest no
Album access of any kind. **Family Circles remain completely unrelated to
Album authorization**, restating ADR-0006 §9's own guardrail ("a Circle
must never become an authorization boundary 'just this once'") in this
new context rather than letting Phase 6 quietly become the phase that
violates it.

Owner and Administrator retain their existing administrative floor: full
view and contribute authority over every Album in their Family Space,
consistent with their role throughout ADR-0004 through ADR-0007, and may
manage (create, edit visibility, grant, revoke) any Album regardless of
its creator. The Album's creator likewise always retains contribute
authority over their own Album.

### 9. Photo deletion and restoration

**There is no destructive Photo deletion in Phase 6, and no wording in
this ADR or its implementation notes should be read otherwise.**
"Deleting" a Photo means reversible soft deletion (tombstoning) only:

- ordinary presentation of the Photo disappears;
- its appearances in every Album it belongs to disappear from ordinary
  presentation, but **the underlying `AlbumPhoto`, `PhotoStory`,
  `PhotoComment` and reaction rows are retained, not deleted** — this is
  what lets restoration "re-establish valid asset references" (the
  roadmap's own Phase 6 verification bullet) as a pure metadata reversal,
  with nothing to re-attach;
- search and dynamic views (§13) must exclude it;
- Phase 5's `MediaUpload`, its preserved original, canonical asset and
  variants are **not** destroyed or affected in any way — this is a
  Photo-domain state change layered entirely on top of an unchanged Phase
  5 asset.

Authority:

- **Owner or Administrator** may soft-delete and restore any Photo in
  their Family Space.
- **The Photo's creator** (`Photo.created_by`, per §1 — distinct from
  `MediaUpload.user_id`) may soft-delete their own Photo and restore
  their own soft-deleted Photo, **while they retain appropriate Family
  Space access**. This mirrors ADR-0006 §16's own principle that
  authority is enforced through *current* membership/role state, not
  through the historical fact of having created something — a creator who
  has since lost Family Space access loses this authority along with it,
  exactly as ADR-0006 already established for other Phase-4-owned
  authority.
- No Member action, including the creator's own, can ever **permanently**
  destroy the preserved media.
- **No automatic purge or retention countdown exists in Phase 6.** A
  soft-deleted Photo remains soft-deleted, and restorable by the roles
  above, indefinitely.

Deletion and restoration authority is always evaluated against
`Photo.created_by`, per §1's definition — never against
`MediaUpload.user_id`, since the uploader and the creator are not
guaranteed to be the same Family Space member.

Permanent destruction of Photo-domain data remains governed entirely by
ADR-0005's Family Space teardown lifecycle (which Phase 6 records must
join, the same way Phase 4 and Phase 5 records already do) and by any
later, explicit retention/deletion architecture (Phase 13). **Phase 6
introduces no standalone permanent-deletion path of its own** — the only
form of "deletion" this phase builds is the reversible tombstone above,
and any implementation-guide language suggesting otherwise is an error to
be corrected, not a second, harder deletion mode to build.

### 10. Phase 5 delivery authorization must become Photo-aware

**This is security-critical.** ADR-0007 §15's delivery endpoints
(`MediaUploadPolicy`) currently authorize by `MediaUpload`/Family Space
role alone, with no concept of Photo or Album privacy — because at the
time Phase 5 was built, no such concept existed. Once a `MediaUpload` is
attached to a `Photo`, that is no longer sufficient, and this ADR fixes
the durable rule Phase 6 must implement, in two deliberately sequenced
layers rather than one:

```text
MediaUpload without a Photo
    -> Phase 5 delivery authorization (ADR-0007 §15) applies unchanged

MediaUpload attached to a Photo, intrinsic visibility only (from S02)
    -> Photo.visibility (§7) is authoritative for canonical, variant and
       original delivery; ADR-0007's Family-Space-role check alone is no
       longer sufficient

MediaUpload attached to a Photo, Album access added (from S04)
    -> Album membership (§8) additionally widens presentation access
       (canonical and variants only — never original download) on top of
       intrinsic visibility, exactly as §7 models
```

**§7 (`Photo.visibility`) and this section's intrinsic-visibility
enforcement must land together, in FPA-P06-S02.** The original version of
this ADR left the entire delivery-awareness rule to FPA-P06-S04, the same
stage that introduces Albums — that would have left `Photo.visibility`
existing as a real, meaningful column from S02 onward while the delivery
layer stayed unaware of it until S04 landed, a genuine window in which a
private Photo's `MediaUpload` would still be reachable through the
unmodified Phase 5 endpoint. This amendment closes that window
architecturally: **`MediaUploadPolicy` must already respect
`Photo.visibility` (`family_space`/`private`) as soon as S02 introduces
the column**, for all three delivery types (canonical, variant, and
original download alike — intrinsic visibility is not presentation-only
the way Album access is, see below). **FPA-P06-S04 then extends the same
Photo-aware check to additionally consult `AlbumGrant`** for the
presentation-only widening §8 describes; it does not introduce
Photo-awareness itself, because S02 already will have.

**A Family Space Member must not be able to bypass a private Photo or a
selected Album simply by calling an existing Phase 5 delivery endpoint
directly with the `MediaUpload` ULID.** That identifier is stable and was,
before Phase 6 existed, a legitimate all-Members-may-view credential
under ADR-0007 §15 — Phase 6 changes what "may view" means for some of
those uploads, and the delivery layer must change with it, starting the
moment a Photo exists, not once Albums are also built. The same is true
of a soft-deleted Photo (§9): once deletion removes a Photo from ordinary
presentation, the delivery layer must honour that too, not only the
domain layer's own listing/query surfaces.

**Original download is governed by intrinsic visibility, never by
Album-derived access alone.** Per §8, an `AlbumGrant`-derived path to a
Photo — whether it is the Photo's own `family_space` visibility resolved
through Album membership, or a private Photo's audience widened by
addition to a broader Album — unlocks canonical and variant delivery
only. Preserved-original download eligibility is evaluated against
ADR-0007 §16's existing role policy (Owner, Administrator, Member; never
Contributor or Guest) **and** the requester's access to the Photo through
its own intrinsic visibility (creator, Owner/Administrator, or ordinary
`family_space` visibility) — never through Album widening alone. Concretely:
a Contributor granted `AlbumGrant.can_view` on a Selected Album containing
a private Photo may view its canonical asset and variants through that
Album, exactly as intended, but never its preserved original, because
Contributor was never eligible for original download under ADR-0007 §16
and Album access does not change that.

This restates §7's governing principle at the delivery layer: intrinsic
Photo visibility and Album access together determine presentation
authority, with original download carved out as intrinsic-visibility-only;
the `MediaUpload` ULID itself confers none of it. The concrete mechanism
— whether `MediaUploadPolicy` is extended to consult the attached
`Photo`/`AlbumPhoto`/`AlbumGrant` state directly, or a new Photo-aware
policy layer wraps it — is implementation-guide detail; the non-bypass
guarantee, its S02/S04 sequencing, and the original-download carve-out are
fixed here.

### 11. Stories and comments

Two distinct content types, matching `PRODUCT_VISION.md`'s own "Photo
stories" and general comment language:

- **`PhotoStory`** — attributed archival narrative: what was happening,
  who's in it, the memory behind it. Editable and correctable by its
  author, and treated as ordinary shared archival content once written
  (no proposed/authoritative gate — it is narrative, not an identity
  claim about a third party in the sense §2 and §5 mean).
- **`PhotoComment`** — ordinary conversational content.

An author may edit or remove their own story or comment at any time. **
Owner or Administrator retains moderation/removal authority over any
story or comment**, regardless of author — inappropriate, mistaken, or
sensitive content must remain administratively removable even if its
author is no longer reachable (account revoked, membership removed,
deceased). No general moderation engine (queues, reports, escalation
tiers) is required or introduced; this is the same "authority matches
actual risk" discipline ADR-0006 already applied to Person and
relationship data, applied here to a smaller, lower-stakes surface.

### 12. Reactions

A small, fixed V1 reaction vocabulary (exact set is implementation-guide
detail, following the same fixed-vocabulary discipline already applied to
relationship types in ADR-0006 §7 and variant transforms in ADR-0007
§11). Architectural guarantees, fixed here because a "harmless" engagement
feature is exactly the kind of thing that accretes social-media dynamics
one small feature at a time if left undecided:

- no popularity ranking or "top Photo" algorithm of any kind;
- no engagement feed;
- no weighting of memories (Phase 12) or search (Phase 11) by reaction
  count.

Reactions remain lightweight family expression only, consistent with
`PRODUCT_VISION.md`'s explicit framing of this product as the deliberate
opposite of an algorithmic feed.

### 13. Dynamic views

Dynamic views (`PRODUCT_VISION.md`'s "Mum and David," "Christmas 1998,"
"photographs without confirmed dates," and similar) are **query-only** in
Phase 6: generated at read time from `Photo`, `PhotoPerson`, historical
date, human-supplied location, tags and other family metadata already
fixed above. **No persisted dynamic-view entity is introduced.** Saving,
naming, or combining views into a durable object is explicitly Phase 11's
concern, not Phase 6's — Phase 6 supplies the queryable metadata; it does
not build the thing that remembers a query.

### 14. Tenancy inheritance

`Photo`, provenance fields, `PhotoPerson`, human-supplied location, `Tag`/
`PhotoTag`, `Album`, `AlbumPhoto`, `AlbumGrant`, `PhotoStory`,
`PhotoComment`, and reactions are ordinary Class C tenant-owned content
tables under ADR-0005 §9.C, exactly as Phase 4's and Phase 5's tables
were: explicit Family Space ownership, application-level query scoping,
policies, `ENABLE`/`FORCE ROW LEVEL SECURITY` with the existing
`family_space_id = app_current_family_space_id()` policy, and fail-closed
behaviour when tenant context is missing. No executable RLS SQL is fixed
here, for the same reason ADR-0005, ADR-0006 and ADR-0007 all declined to
freeze their own policy implementation. No new tenancy, audit, or async
infrastructure is introduced — Phase 6 reuses `TenantOperationContext` and
`AuditEvent` unchanged, exactly as Phase 5 already established for its own
asynchronous processing.

### 15. Explicit non-goals (later-phase seams)

Named explicitly so a future contributor doesn't quietly build any of
these under Phase 6 cover:

- **Photo-to-Photo duplicate linkage** — Phase 8 (Duplicate Detection)
  owns this entirely; Phase 6 introduces no `DuplicateCandidate`-shaped
  table or field.
- **Event IDs or Event-specific Album behaviour** — Phase 7 owns Events
  and Event Albums; `Album` in Phase 6 carries no Event foreign key and no
  Event-specific field. Phase 7 extends Album; Phase 6 does not
  anticipate it structurally.
- **Machine face suggestions, detections, embeddings or clusters** —
  Phases 9/10 own these entirely; §5 fixes only the non-overwrite boundary
  `PhotoPerson` must honour when they arrive.
- **Persisted or saved dynamic views** — Phase 11, per §13.
- **GIS, coordinates, or reverse geocoding for human-supplied location**
  — §4 is a free-text narrative field; a structured places model is not
  built and not anticipated structurally.
- **A tag taxonomy, hierarchy, or moderation engine** — §6 is a flat,
  free-text label set; no parent/child tags, no synonym resolution, no
  approval workflow.

Leave additive seams only (a nullable foreign key a later phase can safely
add, a boundary this ADR names) — do not prebuild any of the above.

### 16. Authorization baseline

| Action | Owner | Administrator | Member | Contributor | Guest |
|---|---:|---:|---:|---:|---:|
| View `family_space`-visibility Photo (canonical/variants) | yes | yes | yes | no default | no |
| View `private` Photo (canonical/variants) | yes | yes | creator only | no | no |
| Download preserved original | yes, if Photo reachable via own intrinsic visibility | yes, if Photo reachable via own intrinsic visibility | yes, if Photo reachable via own intrinsic visibility | no — never, even via AlbumGrant | no |
| View Album (`family_space` visibility) | yes | yes | yes | no default | no |
| View Album (`private` visibility) | yes | yes | creator only | no | no |
| View Album (`selected` visibility) | yes | yes | `AlbumGrant.can_view` only | `AlbumGrant.can_view` only | no |
| Create a Photo from own `ready` MediaUpload | yes | yes | yes (own upload only) | only via `AlbumGrant.can_contribute` (§1, §8) | no |
| Promote another member's `ready` MediaUpload to Photo | yes | yes | no | no | no |
| Propose photographer/scanner/physical-owner claim | yes | yes | yes | no | no |
| Confirm/correct photographer/scanner/physical-owner claim | yes | yes | no | no | no |
| Edit caption / description / archive source description | yes | yes | creator (see §2) | no | no |
| Add/remove Photo tags | yes | yes | yes (any Member with view access) | `AlbumGrant.can_contribute`-scoped only | no |
| Propose historical date / location / PhotoPerson | yes | yes | yes | no | no |
| Confirm historical date / location / PhotoPerson | yes | yes | no | no | no |
| Set/change Photo visibility | yes | yes | creator | no | no |
| Create Album; grant/revoke AlbumGrant; change private↔selected | yes | yes | creator (own Album) | no | no |
| Contribute to Album (`family_space` visibility) | yes | yes | yes (default) | `AlbumGrant.can_contribute` only | no |
| Contribute to Album (`private`/`selected` visibility) | yes | yes | creator, or `AlbumGrant.can_contribute` | `AlbumGrant.can_contribute` only | no |
| Widen a private Photo's audience via Album (§7) | yes | yes | creator, subject to authorization check | n/a | no |
| Soft-delete / restore Photo | yes | yes | creator, while access retained | no | no |
| Add/edit own Story or Comment | yes | yes | yes | `AlbumGrant.can_contribute`-scoped only | no |
| Remove any Story or Comment (moderation) | yes | yes | no | no | no |
| React | yes | yes | yes | where Album access permits | no |

Contributor's rows are the concrete resolution of ADR-0005's and
ADR-0007 §16's deferred placeholder; every "no default" here is deliberate
and matches the same baseline established for Person visibility (ADR-0006
§10) and media delivery (ADR-0007 §16). Guest receives no row above "no"
anywhere in this table, per §8. Member's default `family_space`
contribution row (§8) is the one deliberate exception to "no default
without an explicit grant" among the non-administrative roles, and is
fixed as product policy rather than left open. The "Download preserved
original" row is deliberately separated from the canonical/variant view
rows: reachability through Album access alone never satisfies it, per §10.

### 17. Audit and provenance

`AuditEvent` (the same mechanism ADR-0005 §11, ADR-0006 §15 and ADR-0007
§17 already extend) records, at minimum: Photo created (recording whether
via a Member's own-upload creation, an Owner/Administrator promotion, or a
Contributor's scoped Album contribution); photographer/scanner/
physical-owner claim proposed, confirmed, rejected or corrected;
historical date and human-supplied location each proposed and confirmed/
corrected; `PhotoPerson` proposed, confirmed, rejected; Photo visibility
changed; Album created/updated/removed, including a `private`↔`selected`
visibility change; `AlbumGrant` created/changed/revoked; a private Photo
widened via Album addition (§7) — recorded distinctly from an ordinary
same-audience Album addition, since it is the specific event §7's
authorization and UI requirements exist to make deliberate; Photo
soft-deleted; Photo restored; Story or Comment removed by moderation
(author's own edit/removal of their own content is ordinary write
activity, not a moderation event, and is not separately audited here).
Reactions, tag additions/removals, ordinary caption/description/
archive-source edits, and dynamic-view queries are not audited —
high-frequency, non-trust-bearing activity, consistent with the same
scoping discipline ADR-0007 §17 already applied to variant views and
completion-signal receipts.

## Alternatives considered

- **Allowing `Photo.media_upload_id` to be nullable, or one `MediaUpload`
  to back several Photos** — rejected: a Photo with no trusted asset is a
  contradiction of §1's own premise, and shared-asset Photos would make
  §7's visibility and §9's deletion ambiguous about which Photo's rules
  govern the underlying bytes.
- **Duplicating `MediaUpload.user_id` onto `Photo` as an `uploaded_by`
  field** — rejected: a second source of truth for the same fact, with no
  benefit over reading it through the existing `Photo -> MediaUpload`
  reference.
- **Allowing any Member to promote any ready `MediaUpload` into a Photo,
  not only their own** — rejected: would let one Member unilaterally
  curate another Member's uploaded-but-not-yet-reviewed material into the
  archive without their involvement; reserving cross-member promotion for
  Owner/Administrator keeps that judgment call at the same authority level
  as other archive-administration actions throughout this ADR series.
- **A single free-text "provenance" field instead of distinct
  photographer/scanner/physical-owner roles** — rejected: `PRODUCT_VISION
  .md`'s own worked example distinguishes these roles explicitly, and
  collapsing them would lose the ability to query or display "who took
  this" separately from "who owned the physical print."
- **Keeping the original physical owner's identity claim and the archive
  source (container) description as one shared field** — rejected: this
  was the original text's own ambiguity, identified during pre-
  implementation review; the two are governed by genuinely different
  authority (an identity claim about a person versus ordinary archival
  narrative about a container) and conflating them either over-applies
  the propose/confirm gate to harmless context or under-applies it to a
  real identity claim.
- **Naming a physical-provenance free-text field `source_album`** —
  rejected outright per explicit product direction, in either its
  identity-bearing or archive-source form: Phase 6 introduces a separate,
  unrelated `Album` domain, and reusing the word would be a durable naming
  collision.
- **Extending the proposed/authoritative gate to caption, description,
  archive source description, or tags** — rejected: these are ordinary
  attributed content, not identity claims about a third party; gating
  them the same way as photographer/scanner/owner claims would add
  friction with no corresponding trust benefit, and would be inconsistent
  with ADR-0006 §2's own biography/notes precedent.
- **A `restricted` Photo tier plus a Photo-level grant table, alongside
  Album grants** — rejected per explicit product direction: two
  overlapping systems answering "who can see this Photo?" is a durable
  maintenance and correctness hazard; Album already needs an audience
  concept, and reusing it is simpler and sufficient.
- **Defaulting a new Photo to `private`** — rejected: inconsistent with
  the collaborative default already established for ordinary Family Space
  content throughout ADR-0006/0007; `private` remains available as a
  deliberate choice, not the starting point.
- **Defaulting a Contributor-contributed Photo to `family_space`,
  matching the general default** — rejected: would immediately broadcast
  a Contributor's deliberately scoped contribution Family-Space-wide,
  defeating the purpose of Album-scoped contribution.
- **Treating Album addition as visibility-neutral (no widening check)** —
  rejected: silently exposing a private Photo to a broader audience the
  instant it's added to any Album the actor can edit would quietly
  undermine the entire point of `private` existing as a Photo-level
  concept.
- **Mutating `Photo.visibility` to `family_space` when added to a wider
  Album, or requiring a separate manual "narrow back down" step when
  removed** — rejected: would destroy the Photo's own intrinsic setting
  and make widening a one-way ratchet, contradicting §7's separation of
  intrinsic visibility from explicit sharing and its requirement that
  removal restore intrinsic visibility automatically.
- **Deferring Photo-visibility delivery enforcement to FPA-P06-S04
  (alongside Albums), as the original text specified** — rejected on
  reconciliation: this leaves a real window, between S02 (where
  `Photo.visibility` first exists) and S04, in which a private Photo's
  `MediaUpload` remains reachable through the unmodified Phase 5 delivery
  endpoint. Intrinsic-visibility enforcement now belongs to S02, the same
  stage that introduces the column it enforces.
- **Treating Private and Selected Album visibility as the same mechanism,
  with no explicit rule against `AlbumGrant` rows on a `private` Album** —
  rejected: leaves them functionally identical, undermining the purpose of
  offering two distinct tiers at all.
- **Allowing an `AlbumGrant` with `can_contribute = true` and `can_view =
  false`** — rejected: contribution without visibility is an incoherent
  grant (you cannot meaningfully add photos to a collection you cannot
  see) and a needless extra state to test and reason about.
- **Letting Album-derived access (`AlbumGrant.can_view`) also unlock
  preserved-original download** — rejected: would silently extend
  ADR-0007 §16's Owner/Administrator/Member-only original-download
  baseline to Contributor (and to Members reaching a private Photo only
  through Album widening), a materially more sensitive privilege than
  presentation viewing, and one ADR-0007 never intended to hand to a
  lower-trust, resource-scoped grant.
- **Giving Contributor a Family-Space-wide upload endpoint now that
  Albums exist, rather than an Album-scoped one** — rejected: contradicts
  ADR-0007 §16's explicit resource-scoping intent; Contributor upload must
  remain exactly as narrow as every other Contributor authority this ADR
  fixes.
- **Requiring a new `AlbumGrant` reconciliation step on membership
  reactivation** — rejected: ADR-0005's reactivate-in-place model already
  preserves the membership row's identity, so no Phase 6 mechanism is
  needed; `AlbumGrant` naturally remains attached.
- **Giving Guest any default Album access in Phase 6** — rejected: Guest's
  baseline throughout ADR-0005/0006/0007 has no default access anywhere;
  Guest's first concrete meaning is explicitly reserved for Phase 7's
  Event/guest-link model, which is a materially different trust
  surface (unauthenticated or link-based access) that Phase 6 has no
  reason to pre-empt.
- **Requiring an explicit `AlbumGrant.can_contribute` for ordinary Members
  on `family_space`-visibility Albums, matching Contributor's model** —
  rejected per explicit product direction: Members already receive
  default Family Space content access everywhere else in the product;
  requiring an explicit grant here would make ordinary Albums a curation
  bottleneck and would blur, rather than sharpen, the distinction this ADR
  draws between Member and Contributor.
- **Hard Photo deletion (with or without a delay/grace period)** —
  rejected per explicit product direction and consistent with
  `PRODUCT_VISION.md`'s "original photographs and provenance must be
  preserved": irreversible deletion of family archival content by an
  ordinary Member action is exactly the risk this product exists to
  avoid.
- **Deriving deletion/restoration authority from `MediaUpload.user_id`
  instead of `Photo.created_by`** — rejected: the two can genuinely
  differ (§1, §9), and the uploader is not necessarily the person who
  curated the Photo record from that upload.
- **A structured, coordinate-based location model, or reverse geocoding,
  in Phase 6** — rejected: no demonstrated V1 need beyond the same
  human-narrative pattern already established for historical dates;
  Phase 5's GPS coordinates already exist as the machine-captured
  counterpart and are deliberately not conflated with this field.
- **A hierarchical or taxonomy-based tag system** — rejected: no
  demonstrated need; the roadmap describes tags as lightweight
  organisational metadata, and a taxonomy engine is disproportionate
  scope, mirroring the same discipline already applied to reactions
  (§12) and relationship types (ADR-0006 §7).
- **A general moderation/reporting engine for stories, comments and
  reactions** — rejected as disproportionate: Owner/Administrator removal
  authority is sufficient for a private, invite-only family archive with
  no public surface; the same discipline ADR-0006 already applied to
  Person/relationship moderation.
- **Reaction counts feeding memories (Phase 12) or search (Phase 11)
  ranking** — rejected per explicit product direction: this product is
  the deliberate alternative to an algorithmic, engagement-optimised feed.
- **A persisted dynamic-view/saved-search entity in Phase 6** — rejected:
  not required by any Phase 6 exit criterion, and Phase 11 already owns
  this as a named future concern; building it early would pre-empt that
  phase's own design.

## Consequences

### Positive

- The required, unique `Photo.media_upload_id` makes "a Photo always has a
  trusted asset" a structural database guarantee rather than an
  application-level convention that could be violated by a missed check.
- Reusing ADR-0006's proposed/authoritative pattern for photographer/
  scanner/physical-owner claims, historical date, location and
  `PhotoPerson` means Phase 6 introduces zero new authority-modelling
  concepts — reviewers and future contributors already know this shape
  from Phase 4.
- Separating the original physical owner's identity claim from the
  archive source description (§2) means each is governed by authority
  that actually matches its risk: a real claim about a person gets
  review; a label like "Box labelled Spain" doesn't need to wait on one.
- Collapsing Photo-level sharing into a single `family_space`/`private`
  choice, with Albums as the one selected-audience mechanism, avoids the
  two-systems-that-can-disagree failure mode a parallel `photo_grants`
  table would have introduced, and gives Contributor exactly one place
  (`AlbumGrant`) to reason about.
- The explicit-widening requirement (§7), together with its symmetric
  automatic-narrowing guarantee on removal, turns "private Photo added to
  a broad Album" from a silent, easy-to-miss privacy leak into a
  reviewed, tested, user-visible, fully reversible action.
- Moving intrinsic-visibility delivery enforcement to FPA-P06-S02 (§10)
  closes a real security window that the original acceptance would have
  left open between S02 and S04, before any implementation work began
  against the ambiguous version.
- Fixing default Member contribution (§8), the `can_contribute` →
  `can_view` invariant, and the Private/Selected Album distinction as
  product policy, rather than leaving them to implementation, means
  Codex has one unambiguous authorization model to build against instead
  of three separate judgment calls.
- Carving original download out of what Album access grants (§8, §10)
  keeps ADR-0007 §16's original-download trust boundary intact even as
  Phase 6 introduces new, lower-trust resource-scoped access paths.
- Retaining `AlbumPhoto`/`PhotoStory`/`PhotoComment`/reaction rows across
  soft deletion (§9) makes restoration a pure metadata flip with nothing
  to reconstruct, directly satisfying the roadmap's own restoration exit
  criterion.
- Reusing `TenantOperationContext`, `AuditEvent`, and the Class C RLS
  pattern means Phase 6 introduces no new tenancy or audit infrastructure,
  matching every prior phase's own discipline.

### Negative

- Two new provenance-adjacent fields (location, tags) plus the
  archive-source split mean more schema and UI surface than the original
  text specified — six ordinary/identity-bearing Photo-context concepts
  in total (caption, description, archive source, photographer, scanner,
  physical owner) rather than four.
- The Photo/Album visibility split (§7, §8) means an implementer must
  reason about two independent authorization axes (intrinsic Photo
  visibility, Album access) — now three, counting the original-download
  carve-out (§10) as its own axis — for a single "can this person get
  this asset" question, rather than one flat check.
- §10's delivery-layer change now touches already-shipped Phase 5 code
  (`MediaUploadPolicy`) starting at S02, one stage earlier than the
  original text required — Phase 6 must re-verify Phase 5's existing,
  tested delivery surface sooner, before Albums exist to test the second
  layer against.
- Member's default `family_space` contribution right (§8) means an Owner
  or Administrator who wants a truly curator-only ordinary Album has no
  way to express that on a `family_space`-visibility Album short of
  making it `private` or `selected` instead — the default trades a small
  amount of per-Album flexibility for a simpler, product-level rule.

### Risks

- If a future implementation promotes `MediaUpload`'s EXIF capture
  timestamp into the historical date field, or its GPS coordinates into
  the location field, as a convenience default ("pre-fill from
  technical metadata"), §3's and §4's boundaries are silently violated —
  worth specific tests asserting each pair remains independently sourced.
- If archive_source_description and the identity-bearing physical-owner
  field are conflated again during implementation — for example, by
  reusing one form field or one database column for both — §2's whole
  purpose in splitting them is defeated silently, with no structural
  error to catch it.
- If `MediaUploadPolicy`'s S02 intrinsic-visibility check and its S04
  Album-aware extension are implemented as two independently-reasoned
  patches rather than one evolving mechanism, the original-download
  carve-out (§10) could be dropped or weakened when S04 lands, silently
  granting Album-derived original access nobody intended.
- If Album-widening (§7) is implemented as a background/implicit
  consequence of an unrelated bulk operation (for example, a "move
  photos to Album" action reusing existing Album-edit authorization
  without the dedicated widening check), the authorization and UI
  requirements could be quietly skipped for that one code path.
- If Album membership is cached or denormalized onto the Photo for query
  performance, the automatic-narrowing guarantee (§7) could silently
  become stale — reachability must remain derived from live
  `AlbumPhoto`/`AlbumGrant` state, not a snapshot taken when the Photo was
  last widened.
- If the `can_contribute` → `can_view` invariant (§8) is enforced only at
  the UI layer and not at the service or database layer, a direct API
  call could still create an incoherent grant.
- If `PhotoPerson`'s non-overwrite boundary (§5) is not enforced at the
  schema or service layer before Phase 9/10 exists, there is nothing yet
  stopping a future implementation from taking the shortcut of writing
  machine suggestions directly into the confirmed table "temporarily."
- If Owner/Administrator moderation removal (§11) is the only path that
  actually deletes a `PhotoStory`/`PhotoComment` row, but soft-deletion
  semantics for the Photo itself (§9) are implemented inconsistently with
  it, the two "removal" concepts (moderation delete vs. Photo tombstone)
  could be conflated in code even though this ADR keeps them conceptually
  distinct — and any implementation-guide wording that drifts toward
  "permanent deletion" for either path directly contradicts §9.

## Implementation notes

- **FPA-P06-S02** implements §1 (`Photo` schema, required unique
  `media_upload_id`, `created_by` as defined in §1, and Photo-creation
  authority: Member-own-upload / Owner-Administrator-promote-any /
  Contributor's Album-scoped exception cross-referenced to §8), §2
  (provenance identity claims — photographer/scanner/physical-owner —
  under the proposed/authoritative pattern, and the now-separate ordinary
  `archive_source_description` field), §6 (`Tag`/`PhotoTag`, ordinary
  Member-editable, no approval workflow), and §7 (`Photo.visibility`
  column, defaulting to `family_space` except for a Contributor's scoped
  contribution, which defaults to `private`). **S02 also extends
  `MediaUploadPolicy`** so that, once a `MediaUpload` is attached to a
  Photo, `Photo.visibility` alone is immediately authoritative for
  canonical, variant, *and* original delivery — this closes the temporary
  security gap the original ADR text would otherwise have left open
  until S04, and must be verified before S02 is considered complete, not
  deferred alongside Album work.
- **FPA-P06-S03** implements §3 (historical date, reusing ADR-0006 §3's
  concept and proposed/authoritative pattern), §4 (human-supplied
  location, same pattern, strictly separate from GPS), and §5
  (`PhotoPerson`, including its non-overwrite boundary relative to future
  machine recognition).
- **FPA-P06-S04** implements §8 (`Album`, `AlbumPhoto`, `AlbumGrant` with
  its `can_contribute` → `can_view` invariant, the explicit Private/
  Selected visibility distinction, the fixed default-Member-contribution
  policy, membership-reactivation continuity, the Contributor Album-scoped
  upload/contribution path, and the original-download carve-out), §7's
  Album-side half (the explicit widening authorization, UI signal, the
  automatic-narrowing guarantee on removal, and the required bypass
  regression test), and the Album-aware **extension** of S02's already-
  Photo-aware `MediaUploadPolicy` (§10) — consulting `AlbumGrant` for
  presentation-only widening, explicitly never extending to original
  download — and §13 (dynamic views as query-only behaviour over the
  metadata S02–S03 already established, now including location and tags).
- **FPA-P06-S05** implements §11 (`PhotoStory`/`PhotoComment`, author
  edit/removal, Owner/Administrator moderation authority) and §12
  (reactions, fixed vocabulary, the explicit no-ranking/no-engagement-feed
  guarantees).
- **FPA-P06-S06** implements §9 (soft deletion/restoration, creator vs.
  uploader authority per §1, retained-not-deleted associated rows;
  **no permanent-deletion path of any kind**) and must re-verify, not
  merely assume, that §10's S02-established delivery check also respects
  a soft-deleted Photo's presentation state.
- **Required bypass regression tests**: (1) *from S02*, before any Album
  exists — a Member with legitimate prior access to a `MediaUpload` ULID
  must be denied canonical, variant, and original delivery once it
  becomes part of a private Photo, via the existing Phase 5 endpoints;
  (2) *from S04* — adding a private Photo to a broader Album must be
  independently authorized and must not succeed as a side effect of
  ordinary Album-edit authorization alone; (3) *from S04* — removing a
  Photo from every widening Album must restore exactly its intrinsic
  visibility, verified against live state rather than a cached grant; (4)
  *from S04* — an `AlbumGrant` with `can_view = false` and `can_contribute
  = true` must be rejected or unrepresentable; (5) *from S04* — a role or
  access path that reaches a Photo only through `AlbumGrant` (never
  through the Photo's own intrinsic visibility) must never be authorized
  for original download, regardless of the requester's Family Space role.
- §7's governing principle (intrinsic visibility vs. explicit sharing) and
  §2/§3/§4/§5's shared proposed/authoritative pattern are both
  cross-cutting: every stage above that touches visibility or a
  provenance-shaped field should be checked against them during review,
  not assumed satisfied because the table exists.

## Review triggers

- **When Phase 7 (Events) is scoped**: give Guest its first concrete
  access model via Event/guest links, and confirm Event Albums extend
  `Album` (§8, §15) without requiring a superseding change to this ADR's
  visibility, `AlbumGrant`, or default-Member-contribution mechanism.
- **When Phase 8 (duplicate detection) is scoped**: confirm duplicate
  linkage is added as a Photo-to-Photo (or Photo-to-MediaUpload) relation
  that does not require reopening §1's one-`MediaUpload`-per-Photo
  constraint, and that consolidation respects §9's soft-deletion model
  rather than introducing a second deletion concept.
- **When Phase 9/10 (face analysis, clustering) are scoped**: confirm the
  `PhotoPerson` non-overwrite boundary (§5) is actually enforced by
  whatever ingestion mechanism those phases introduce, not merely
  documented; confirm machine-suggested identity surfaces as a *proposed*
  row through the existing mechanism.
- **When Phase 11 (search) is scoped**: confirm search does not bypass
  §7's visibility model, §8's original-download carve-out, or §10's
  delivery non-bypass guarantee, and that persisted/saved views (and tag-
  or location-based filtering) build on top of §13's query-only
  foundation rather than duplicating it.
- **When Phase 13 (export/backup/retention) is scoped**: define the first
  real permanent-deletion and retention-countdown mechanism §9 explicitly
  declines to build in Phase 6.
- **If real usage shows the photographer/scanner/physical-owner
  proposed/authoritative gate (§2) is too heavy for how families actually
  contribute this information** (for example, wanting to record "probably
  Grandad" without a full proposal/confirmation round-trip), revisit
  deliberately rather than quietly loosening Owner/Administrator-only
  confirmation.
- **If real usage shows Member's default `family_space` contribution
  right (§8) is too permissive for a given family** (for example, an
  Owner wanting a curator-only "official" Album that is still visible to
  everyone), revisit deliberately — the `private`/`selected` visibility
  tiers already provide a workaround, but a dedicated
  view-without-contribute mode for `family_space` Albums is not built in
  Phase 6.

## Deferred concerns

- Photo-to-Photo duplicate linkage — Phase 8.
- Event IDs, Event Albums, and guest contribution links — Phase 7.
- Machine face detection, embeddings, clusters, and suggested identity —
  Phases 9/10.
- Persisted or saved dynamic views — Phase 11.
- Permanent deletion and retention countdowns — Phase 13.
- The fixed V1 reaction vocabulary's exact values (§12) — a functional
  requirement is fixed, the list is not.
- GIS, coordinates, or reverse geocoding for human-supplied location
  (§4) — not built, not anticipated structurally.
- A tag taxonomy, hierarchy, or moderation model (§6) — flat free-text
  labels only.
- Exact `AlbumGrant`/`Photo`/`PhotoPerson`/`Tag` schema, migrations, route
  and policy class names — `docs/IMPLEMENTATION_GUIDE.md`.
- A curator-only `family_space`-visibility Album mode (view-without-
  default-contribute) — not built in Phase 6; §8's Review trigger applies
  if real usage demands it.

## Resolved decisions

1. **Photo ↔ MediaUpload** — required, unique, one-directional
   (`Photo -> MediaUpload`); a `ready` MediaUpload may exist indefinitely
   without a Photo; one MediaUpload backs at most one Photo.
   `Photo.created_by` identifies the Family Space member who created the
   Photo record and is intentionally distinct from `MediaUpload.user_id`
   (who uploaded the bytes). A Member may create a Photo only from their
   own ready MediaUpload; Owner/Administrator may promote any ready
   MediaUpload; a Contributor may create a Photo only as part of a scoped
   Album contribution (§8); Guest may not create a Photo.
2. **Provenance** — uploader derived from `MediaUpload.user_id`, never
   duplicated; photographer, scanner and original physical owner are
   single-valued, Person-reference-or-mutually-exclusive-free-text
   identity claims following the ADR-0006 §4 proposed/authoritative
   pattern. The physical container or collection a photo came from is a
   **separate, ordinary** `archive_source_description` field requiring no
   approval workflow — split explicitly from the identity-bearing
   physical-owner claim it was originally conflated with. Caption,
   description and archive source description are all ordinary content.
3. **Historical date** — reuses ADR-0006 §3's uncertain-date concept
   unmodified; strictly separate from and never silently populated from
   `MediaUpload`'s EXIF capture timestamp; proposed/authoritative pattern
   applies.
4. **Human-supplied location** — free-text, human-supplied, proposed/
   authoritative pattern matching historical date; strictly separate from
   and never derived from `MediaUpload`'s GPS coordinates; no GIS,
   coordinates, or reverse geocoding.
5. **PhotoPerson** — implemented in FPA-P06-S03 with the same proposed/
   authoritative pattern; only confirmed rows are authoritative human
   ground truth; structurally separate from and never overwritten by
   future machine recognition (Phase 9/10).
6. **Photo tags** — Family-Space-scoped, free-text, ordinary
   Member-editable labels with no proposed/authoritative gate, no
   taxonomy and no hierarchy; not a substitute for Albums; carry no
   visibility of their own beyond the Photo they tag.
7. **Photo visibility** — exactly two values, `family_space` (the
   default for an ordinary new Photo) and `private` (the default for a
   Photo created via a Contributor's scoped Album contribution); no
   separate restricted tier or Photo-level grant table; selected-audience
   sharing lives entirely in Albums; adding a private Photo to a broader
   Album is an explicit, authorized, UI-visible, tested widening
   operation, and removing it from every such Album automatically and
   immediately restores exactly its intrinsic visibility; Album
   membership never mutates the Photo's own visibility setting in either
   direction.
8. **Albums and Contributor** — `Album` (one Family Space, `created_by`
   curator, `AlbumPhoto` many-to-many, explicitly distinct
   private/selected/family_space visibility tiers — private meaning zero
   `AlbumGrant` rows, selected meaning grant-governed) plus `AlbumGrant`
   (`can_view`/`can_contribute` per membership, with `can_contribute`
   always implying `can_view`) as the sole selected-audience mechanism.
   `AlbumGrant` survives ADR-0005's in-place membership reactivation
   automatically. Default contribution on a `family_space`-visibility
   Album is fixed product policy for Owner/Administrator/creator/Member;
   Contributor contributes only through an explicit `AlbumGrant`, and may
   upload/create a Photo only through an Album it grants
   `can_contribute` on — fulfilling ADR-0007 §16's deferred placeholder.
   `AlbumGrant` governs presentation access (canonical/variants) only,
   never preserved-original download. Guest deferred to Phase 7; Family
   Circles remain unrelated to Album authorization.
9. **Deletion and restoration** — reversible soft deletion only, with no
   permanent-deletion path anywhere in Phase 6; Owner/Administrator may
   act on any Photo; the creator may act on their own Photo while
   retaining Family Space access; `Photo.created_by` (not
   `MediaUpload.user_id`) is authority-bearing; Phase 5 assets are never
   affected; associated Album/story/comment/reaction rows are retained
   across a soft delete; no automatic purge exists.
10. **Delivery non-bypass** — enforcement begins in FPA-P06-S02, the same
    stage that introduces `Photo.visibility`, not deferred to FPA-P06-S04:
    once a MediaUpload is attached to a Photo, intrinsic Photo visibility
    is immediately authoritative for canonical, variant and original
    delivery; FPA-P06-S04 extends the same check to consult `AlbumGrant`
    for presentation-only widening, which never extends to original
    download; the existing Phase 5 endpoint and the MediaUpload ULID
    alone must never bypass either layer.
11. **Stories, comments, reactions** — `PhotoStory` (archival narrative)
    and `PhotoComment` (conversational) are author-editable/removable,
    with Owner/Administrator retaining moderation authority regardless of
    author; a small fixed reaction vocabulary with explicit no-ranking/
    no-engagement-feed guarantees.
12. **Dynamic views** — query-only in Phase 6, spanning Photo, PhotoPerson,
    date, location and tag metadata; no persisted view entity; saved/
    combined views remain Phase 11.
13. **Tenancy** — Photo-domain tables (including `Tag`/`PhotoTag` and
    location) are ordinary Class C tenant-owned tables under ADR-0005; no
    RLS SQL frozen here; `TenantOperationContext` and `AuditEvent` reused
    unchanged.
14. **Non-goals** — duplicate linkage (Phase 8), Event/Event-Album
    structure (Phase 7), machine face data (Phase 9/10), persisted dynamic
    views (Phase 11), GIS/coordinate location, and tag taxonomy/hierarchy
    are explicitly not built in Phase 6.
