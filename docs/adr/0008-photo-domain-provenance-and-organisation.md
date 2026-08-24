# ADR-0008: Photo Domain, Provenance and Organisation

- Status: Accepted
- Date: 2026-08-24
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
core `Photo` schema fact rather than an incidental column, because §5's
and §6's contribution/visibility authority and §7's deletion/restoration
authority all depend on `created_by` specifically, and must never be
derived from `MediaUpload.user_id`.

### 2. Provenance

Four distinct parties can be associated with a photograph, and Phase 6
must not conflate them:

- **Uploader** — who transferred the bytes. Already known: `MediaUpload
  .user_id` (ADR-0007 §2). **Not duplicated on `Photo`.** Any surface that
  needs "who uploaded this" reads it via the `Photo → MediaUpload`
  reference; there is no second source of truth to drift out of sync with
  the first.
- **Photographer** — who took the original picture.
- **Scanner / digitiser** — who scanned or digitised a physical original.
- **Original physical owner** — whose physical print, album or collection
  the image came from (`PRODUCT_VISION.md`'s own example: "Physical
  source: Mum's family album").

The latter three are **identity-bearing provenance claims**, not
uploader-derived facts, and each is **single-valued in V1**. Each may be
represented as:

- a nullable `Person` reference, where the individual already exists in
  the archive; or
- a mutually-exclusive free-text fallback, where reifying the party as a
  `Person` record would be inappropriate or premature (an unnamed scanning
  service, a physical collection with no single named owner, a
  photographer nobody can identify).

The free-text field backing the original-physical-owner role is named
**`physical_source_description`** — explicitly not `source_album`, because
Phase 6 introduces a separate digital `Album` domain (§6) and reusing that
word for two unrelated concepts would be a durable naming hazard. The
photographer and scanner free-text fallbacks follow the same
Person-reference-or-mutually-exclusive-text shape; their exact column
names are implementation-guide detail, with the same constraint: neither
may reuse a name Phase 6 or a later phase gives to a different concept
(`Album`, `Event`, `Person`).

Because these three roles are identity-bearing claims about (often
deceased, often account-less) third parties — exactly the risk ADR-0006
§4 and §7 already reasoned about for Person details and relationships —
**they follow the same Member-proposes / Owner-or-Administrator-confirms
pattern ADR-0006 §4 established**, applied here without modification: a
Member may propose a photographer, scanner or physical-owner claim (as a
Person reference or free text); only Owner or Administrator may confirm it
as authoritative, correct it, or replace a confirmed claim. This is the
same architectural distinction ADR-0006 fixed once and Phase 4 already
told Phase 6 to expect reusing (ADR-0006's Review triggers).

**Caption, contextual description, and the physical-source description
itself are ordinary attributed family content, not identity-bearing
claims about a third party, and do not require this approval workflow.**
A Member (subject to the ordinary edit-authority rules §14 fixes) may
write or correct these directly. This mirrors the distinction ADR-0006 §2
already drew for Person biography/notes: shared archival content everyone
with view access can see, contributed without a confirmation gate, as
opposed to identity claims about a person who cannot necessarily speak for
themselves.

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
`PhotoPerson` and human-supplied location (§4, §9).

### 4. Photo ↔ Person identity (`PhotoPerson`)

`PhotoPerson` — the claim that a given `Person` appears in a given Photo —
is implemented in **FPA-P06-S03**, using the same established
proposed/authoritative pattern:

- A Member may propose that a Person appears in a Photo.
- Owner or Administrator confirms or rejects the proposal.
- **Only confirmed `PhotoPerson` rows belong to the authoritative
  human-knowledge graph.** Proposed and rejected associations are retained
  for provenance but do not become human ground truth, do not appear in
  any "appears in N photographs" count (`PRODUCT_VISION.md`'s People-page
  example), and are not treated as confirmed by any dynamic view (§11).

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

### 5. Photo visibility — intrinsic visibility vs. explicit sharing

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

No separate `restricted` Photo tier and no second Photo-level grant table
are introduced in V1. Selected-member sharing lives entirely in **Albums**
(§6), which already need their own audience concept; adding a parallel
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
  authorization model** — this is the same class of guarantee §8 requires
  at the delivery layer, tested here at the domain layer.

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

### 6. Albums and Contributor's first concrete meaning

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
  **private** (creator and Owner/Administrator only), **shared with
  selected memberships**, or **visible to the ordinary Family Space
  audience** (the same default tier Photo and Person already use).

**Visibility and contribution are separate concerns**, matching §5's
governing principle applied one level up. The mechanism is an explicit
grant:

```text
AlbumGrant
- album_id
- family_space_membership_id
- can_view
- can_contribute
```

`AlbumGrant` realises an Album's "shared with selected memberships" tier
directly (one row per granted membership) and is also how a role that has
no Family-Space-wide default gets Album-scoped access at all. Its exact
physical schema (indexes, uniqueness, cascade behaviour) is
implementation-guide detail; the architectural commitment is the
mechanism — one explicit, resource-scoped grant carrying independent
view/contribute bits — not its physical layout.

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
Albums. On a **private** or **selected-membership** Album, contribution
authority does not widen beyond Owner, Administrator, the creator, and
whichever memberships hold an explicit `AlbumGrant.can_contribute` —
including an ordinary Member, who receives no default contribution
authority on an Album they cannot already view by default. Viewing a
`family_space`-visibility Album (already default for Owner/Administrator/
Member) and contributing to one are therefore both default for those three
roles on that one visibility tier specifically; nothing here grants
default access to a `private` or `selected` Album's contents.

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

**Guest remains deferred to Phase 7's Event/guest-access model**, per its
existing baseline throughout ADR-0005/0006/0007 — Phase 6 grants Guest no
Album access of any kind. **Family Circles remain completely unrelated to
Album authorization**, restating ADR-0006 §9's own guardrail ("a Circle
must never become an authorization boundary 'just this once'") in this
new context rather than letting Phase 6 quietly become the phase that
violates it.

### 7. Photo deletion and restoration

**There is no destructive Photo deletion in Phase 6.** "Deleting" a Photo
means reversible soft deletion (tombstoning):

- ordinary presentation of the Photo disappears;
- its appearances in every Album it belongs to disappear from ordinary
  presentation, but **the underlying `AlbumPhoto`, `PhotoStory`,
  `PhotoComment` and reaction rows are retained, not deleted** — this is
  what lets restoration "re-establish valid asset references" (the
  roadmap's own Phase 6 verification bullet) as a pure metadata reversal,
  with nothing to re-attach;
- search and dynamic views (§11) must exclude it;
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
later, explicit retention/deletion architecture (Phase 13). Phase 6
introduces no standalone permanent-deletion path of its own.

### 8. Phase 5 delivery authorization must become Photo-aware

**This is security-critical.** ADR-0007 §15's delivery endpoints
(`MediaUploadPolicy`) currently authorize by `MediaUpload`/Family Space
role alone, with no concept of Photo or Album privacy — because at the
time Phase 5 was built, no such concept existed. Once a `MediaUpload` is
attached to a `Photo`, that is no longer sufficient, and this ADR fixes
the durable rule Phase 6 must implement:

```text
MediaUpload without a Photo
    -> Phase 5 delivery authorization (ADR-0007 §15) applies unchanged

MediaUpload attached to a Photo
    -> Photo-domain visibility (§5) and Album access (§6) become
       authoritative for presentation access; ADR-0007's
       Family-Space-role check alone is no longer sufficient
```

**A Family Space Member must not be able to bypass a private Photo or a
selected-membership Album simply by calling an existing Phase 5 delivery
endpoint directly with the `MediaUpload` ULID.** That identifier is stable
and was, before Phase 6 existed, a legitimate all-Members-may-view
credential under ADR-0007 §15 — Phase 6 changes what "may view" means for
some of those uploads, and the delivery layer must change with it. The
same is true of a soft-deleted Photo (§7): once deletion removes a Photo
from ordinary presentation, the delivery layer must honour that too, not
only the domain layer's own listing/query surfaces.

This restates §5's governing principle at the delivery layer: intrinsic
Photo visibility and Album access together determine presentation
authority; the `MediaUpload` ULID itself confers none. The concrete
mechanism — whether `MediaUploadPolicy` is extended to consult the
attached `Photo`/`AlbumPhoto`/`AlbumGrant` state directly, or a new
Photo-aware policy layer wraps it — is implementation-guide detail (§4/S04
per the implementation notes below); the non-bypass guarantee itself is
fixed here.

### 9. Stories, comments and reactions

Two distinct content types, matching `PRODUCT_VISION.md`'s own "Photo
stories" and general comment/reaction language:

- **`PhotoStory`** — attributed archival narrative: what was happening,
  who's in it, the memory behind it. Editable and correctable by its
  author, and treated as ordinary shared archival content once written
  (no proposed/authoritative gate — it is narrative, not an identity
  claim about a third party in the sense §2 and §4 mean).
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

### 10. Reactions

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

### 11. Dynamic views

Dynamic views (`PRODUCT_VISION.md`'s "Mum and David," "Christmas 1998,"
"photographs without confirmed dates," and similar) are **query-only** in
Phase 6: generated at read time from `Photo`, `PhotoPerson`, historical
date and other family metadata already fixed above. **No persisted
dynamic-view entity is introduced.** Saving, naming, or combining views
into a durable object is explicitly Phase 11's concern, not Phase 6's —
Phase 6 supplies the queryable metadata; it does not build the thing that
remembers a query.

### 12. Tenancy inheritance

`Photo`, provenance fields, `PhotoPerson`, `Album`, `AlbumPhoto`,
`AlbumGrant`, `PhotoStory`, `PhotoComment`, and reactions are ordinary
Class C tenant-owned content tables under ADR-0005 §9.C, exactly as
Phase 4's and Phase 5's tables were: explicit Family Space ownership,
application-level query scoping, policies, `ENABLE`/`FORCE ROW LEVEL
SECURITY` with the existing `family_space_id = app_current_family_space_id
()` policy, and fail-closed behaviour when tenant context is missing. No
executable RLS SQL is fixed here, for the same reason ADR-0005, ADR-0006
and ADR-0007 all declined to freeze their own policy implementation. No
new tenancy, audit, or async infrastructure is introduced — Phase 6 reuses
`TenantOperationContext` and `AuditEvent` unchanged, exactly as Phase 5
already established for its own asynchronous processing.

### 13. Explicit non-goals (later-phase seams)

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
  Phases 9/10 own these entirely; §4 fixes only the non-overwrite boundary
  `PhotoPerson` must honour when they arrive.
- **Persisted or saved dynamic views** — Phase 11, per §11.

Leave additive seams only (a nullable foreign key a later phase can safely
add, a boundary this ADR names) — do not prebuild any of the above.

### 14. Authorization baseline

| Action | Owner | Administrator | Member | Contributor | Guest |
|---|---:|---:|---:|---:|---:|
| View `family_space`-visibility Photo | yes | yes | yes | no default | no |
| View `private` Photo | yes | yes | creator only | no | no |
| View Album (`family_space` visibility) | yes | yes | yes | no default | no |
| View Album (`private`/`selected` visibility) | yes | yes | creator, or `AlbumGrant.can_view` | `AlbumGrant.can_view` only | no |
| Create a Photo from a `ready` MediaUpload | yes | yes | yes | no | no |
| Propose photographer/scanner/physical-owner claim | yes | yes | yes | no | no |
| Confirm/correct photographer/scanner/physical-owner claim | yes | yes | no | no | no |
| Edit caption / description / physical-source description | yes | yes | creator (see §2) | no | no |
| Propose historical date / PhotoPerson | yes | yes | yes | no | no |
| Confirm historical date / PhotoPerson | yes | yes | no | no | no |
| Set/change Photo visibility | yes | yes | creator | no | no |
| Create Album; grant/revoke AlbumGrant | yes | yes | creator (own Album) | no | no |
| Contribute to Album (`family_space` visibility) | yes | yes | yes (default) | `AlbumGrant.can_contribute` only | no |
| Contribute to Album (`private`/`selected` visibility) | yes | yes | creator, or `AlbumGrant.can_contribute` | `AlbumGrant.can_contribute` only | no |
| Widen a private Photo's audience via Album (§5) | yes | yes | creator, subject to authorization check | n/a | no |
| Soft-delete / restore Photo | yes | yes | creator, while access retained | no | no |
| Add/edit own Story or Comment | yes | yes | yes | `AlbumGrant.can_contribute`-scoped only | no |
| Remove any Story or Comment (moderation) | yes | yes | no | no | no |
| React | yes | yes | yes | where Album access permits | no |

Contributor's rows are the concrete resolution of ADR-0005's and
ADR-0007 §16's deferred placeholder; every "no default" here is deliberate
and matches the same baseline established for Person visibility (ADR-0006
§10) and media delivery (ADR-0007 §16). Guest receives no row above
"no" anywhere in this table, per §6. Member's default `family_space`
contribution row (§6) is the one deliberate exception to "no default
without an explicit grant" among the non-administrative roles, and is
fixed as product policy rather than left open.

### 15. Audit and provenance

`AuditEvent` (the same mechanism ADR-0005 §11, ADR-0006 §15 and ADR-0007
§17 already extend) records, at minimum: Photo created; photographer/
scanner/physical-owner claim proposed, confirmed, rejected or corrected;
historical date proposed and confirmed/corrected; `PhotoPerson` proposed,
confirmed, rejected; Photo visibility changed; Album created/updated/
removed; `AlbumGrant` created/changed/revoked; a private Photo widened via
Album addition (§5) — recorded distinctly from an ordinary same-audience
Album addition, since it is the specific event §5's authorization and UI
requirements exist to make deliberate; Photo soft-deleted; Photo restored;
Story or Comment removed by moderation (author's own edit/removal of their
own content is ordinary write activity, not a moderation event, and is not
separately audited here). Reactions, ordinary caption/description edits,
and dynamic-view queries are not audited — high-frequency, non-trust-
bearing activity, consistent with the same scoping discipline ADR-0007
§17 already applied to variant views and completion-signal receipts.

## Alternatives considered

- **Allowing `Photo.media_upload_id` to be nullable, or one `MediaUpload`
  to back several Photos** — rejected: a Photo with no trusted asset is a
  contradiction of §1's own premise, and shared-asset Photos would make
  §5's visibility and §7's deletion ambiguous about which Photo's rules
  govern the underlying bytes.
- **Duplicating `MediaUpload.user_id` onto `Photo` as an `uploaded_by`
  field** — rejected: a second source of truth for the same fact, with no
  benefit over reading it through the existing `Photo -> MediaUpload`
  reference.
- **A single free-text "provenance" field instead of distinct
  photographer/scanner/physical-owner roles** — rejected: `PRODUCT_VISION
  .md`'s own worked example distinguishes all three explicitly, and
  collapsing them would lose the ability to query or display "who took
  this" separately from "whose album did this come from."
- **Naming the physical-owner free-text field `source_album`** —
  rejected outright per explicit product direction: Phase 6 introduces a
  separate, unrelated `Album` domain, and reusing the word would be a
  durable naming collision.
- **Extending the proposed/authoritative gate to caption, description and
  physical-source description** — rejected: these are ordinary attributed
  content, not identity claims about a third party; gating them the same
  way as photographer/scanner/owner claims would add friction with no
  corresponding trust benefit, and would be inconsistent with ADR-0006
  §2's own biography/notes precedent.
- **A `restricted` Photo tier plus a Photo-level grant table, alongside
  Album grants** — rejected per explicit product direction: two
  overlapping systems answering "who can see this Photo?" is a durable
  maintenance and correctness hazard; Album already needs an audience
  concept, and reusing it is simpler and sufficient.
- **Treating Album addition as visibility-neutral (no widening check)** —
  rejected: silently exposing a private Photo to a broader audience the
  instant it's added to any Album the actor can edit would quietly
  undermine the entire point of `private` existing as a Photo-level
  concept.
- **Mutating `Photo.visibility` to `family_space` when added to a wider
  Album, or requiring a separate manual "narrow back down" step when
  removed** — rejected: would destroy the Photo's own intrinsic setting
  and make widening a one-way ratchet, contradicting §5's separation of
  intrinsic visibility from explicit sharing and its requirement that
  removal restore intrinsic visibility automatically.
- **Requiring an explicit `AlbumGrant.can_contribute` for ordinary Members
  on `family_space`-visibility Albums, matching Contributor's model** —
  rejected per explicit product direction: Members already receive
  default Family Space content access everywhere else in the product
  (ADR-0006 §10, ADR-0007 §16); requiring an explicit grant here would
  make ordinary Albums a curation bottleneck and would blur, rather than
  sharpen, the distinction this ADR exists to draw between Member and
  Contributor.
- **Giving Contributor a Family-Space-wide default Album/Photo grant,
  matching Member** — rejected: Contributor's entire premise since
  ADR-0005 is resource-scoped contribution; an unscoped grant would
  collapse the distinction between Contributor and Member that this ADR
  exists to finally give concrete meaning to.
- **Giving Guest any default Album access in Phase 6** — rejected: Guest's
  baseline throughout ADR-0005/0006/0007 has no default access anywhere;
  Guest's first concrete meaning is explicitly reserved for Phase 7's
  Event/guest-link model, which is a materially different trust
  surface (unauthenticated or link-based access) that Phase 6 has no
  reason to pre-empt.
- **Hard Photo deletion (with or without a delay/grace period)** —
  rejected per explicit product direction and consistent with
  `PRODUCT_VISION.md`'s "original photographs and provenance must be
  preserved": irreversible deletion of family archival content by an
  ordinary Member action is exactly the risk this product exists to
  avoid.
- **Deriving deletion/restoration authority from `MediaUpload.user_id`
  instead of `Photo.created_by`** — rejected: the two can genuinely
  differ (§1, §7), and the uploader is not necessarily the person who
  curated the Photo record from that upload.
- **Leaving Phase 5's delivery endpoints untouched and relying on the
  frontend to simply not link to a private Photo's asset** — rejected:
  a client-side-only control is not an authorization boundary; the
  `MediaUpload` ULID would remain a working bypass for anyone who already
  saw it once or guesses/enumerates it, directly contradicting ADR-0007
  §15's own fail-closed, signature-scoped delivery model.
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
  scanner/physical-owner claims, historical dates and `PhotoPerson` means
  Phase 6 introduces zero new authority-modelling concepts — reviewers and
  future contributors already know this shape from Phase 4.
- Collapsing Photo-level sharing into a single `family_space`/`private`
  choice, with Albums as the one selected-audience mechanism, avoids the
  two-systems-that-can-disagree failure mode a parallel `photo_grants`
  table would have introduced, and gives Contributor exactly one place
  (`AlbumGrant`) to reason about.
- The explicit-widening requirement (§5), together with its symmetric
  automatic-narrowing guarantee on removal, turns "private Photo added to
  a broad Album" from a silent, easy-to-miss privacy leak into a
  reviewed, tested, user-visible, fully reversible action.
- Fixing default Member contribution (§6) as product policy, rather than
  leaving it to implementation, means Contributor's scoped-grant model
  and Member's collaborative default are both settled facts an
  implementer can build against immediately, with no risk of the two
  roles converging by accident.
- Fixing the delivery non-bypass rule (§8) now, rather than discovering it
  during Phase 6 implementation under time pressure, closes a real gap
  before any private Photo or restricted Album exists in production to be
  exposed by it.
- Retaining `AlbumPhoto`/`PhotoStory`/`PhotoComment`/reaction rows across
  soft deletion (§7) makes restoration a pure metadata flip with nothing
  to reconstruct, directly satisfying the roadmap's own restoration exit
  criterion.
- Reusing `TenantOperationContext`, `AuditEvent`, and the Class C RLS
  pattern means Phase 6 introduces no new tenancy or audit infrastructure,
  matching every prior phase's own discipline.

### Negative

- Three independent proposed/authoritative provenance roles (photographer,
  scanner, physical owner), each with a Person-or-text fallback, is real
  schema and UI surface — three fields and three review flows rather than
  one generic "provenance note."
- The Photo/Album visibility split (§5, §6) means an implementer must
  reason about two independent authorization axes (intrinsic Photo
  visibility, Album access) for a single "can this person see this photo"
  question, rather than one flat check — more conceptual surface than a
  single-tier model, in exchange for avoiding two competing grant tables.
- §8's delivery-layer change is a real modification to already-shipped
  Phase 5 code (`MediaUploadPolicy`), not purely additive new-table work —
  Phase 6 must touch and re-verify Phase 5's existing, tested delivery
  surface rather than building entirely alongside it.
- Member's default `family_space` contribution right (§6) means an Owner
  or Administrator who wants a truly curator-only ordinary Album has no
  way to express that on a `family_space`-visibility Album short of
  making it `private` or `selected` instead — the default trades a small
  amount of per-Album flexibility for a simpler, product-level rule.

### Risks

- If a future implementation promotes `MediaUpload`'s EXIF capture
  timestamp into the historical date field as a convenience default
  ("pre-fill from EXIF"), §3's boundary is silently violated — worth a
  specific test asserting the two remain independently sourced once
  Phase 6 ships.
- If `MediaUploadPolicy` (§8) is extended by pattern-matching a "usually
  works" case rather than by exhaustively covering every delivery type
  (canonical, variant, original) for every Photo state (visible, private,
  soft-deleted), a partial fix could leave one delivery path bypassable
  while the others are correctly Photo-aware.
- If Album-widening (§5) is implemented as a background/implicit
  consequence of an unrelated bulk operation (for example, a "move
  photos to Album" action reusing existing Album-edit authorization
  without the dedicated widening check), the authorization and UI
  requirements could be quietly skipped for that one code path.
- If Album membership is cached or denormalized onto the Photo for query
  performance, the automatic-narrowing guarantee (§5) could silently
  become stale — reachability must remain derived from live
  `AlbumPhoto`/`AlbumGrant` state, not a snapshot taken when the Photo was
  last widened.
- If `PhotoPerson`'s non-overwrite boundary (§4) is not enforced at the
  schema or service layer before Phase 9/10 exists, there is nothing yet
  stopping a future implementation from taking the shortcut of writing
  machine suggestions directly into the confirmed table "temporarily."
- If Owner/Administrator moderation removal (§9) is the only path that
  actually deletes a `PhotoStory`/`PhotoComment` row, but soft-deletion
  semantics for the Photo itself (§7) are implemented inconsistently with
  it, the two "removal" concepts (moderation delete vs. Photo tombstone)
  could be conflated in code even though this ADR keeps them conceptually
  distinct.

## Implementation notes

- **FPA-P06-S02** implements §1 (`Photo` schema, required unique
  `media_upload_id`, `created_by` as defined in §1), §2 (provenance fields
  and the proposed/authoritative pattern applied to photographer/scanner/
  physical-owner claims, including `physical_source_description`), and
  the Photo-created/provenance-proposed/confirmed audit events.
  `Photo.visibility` (§5) is established here as a column even though its
  full authorization behaviour, including Album widening, is completed in
  S04.
- **FPA-P06-S03** implements §3 (historical date, reusing ADR-0006 §3's
  concept and proposed/authoritative pattern) and §4 (`PhotoPerson`,
  including its non-overwrite boundary relative to future machine
  recognition).
- **FPA-P06-S04** implements §6 (`Album`, `AlbumPhoto`, `AlbumGrant`, the
  fixed default-Member-contribution policy, and Contributor's first
  concrete resource-scoped grant), §5's Album-side half (the explicit
  widening authorization, UI signal, the automatic-narrowing guarantee on
  removal, and the required bypass regression test), §8 (making
  `MediaUploadPolicy` Photo/Album-aware — the non-bypass guarantee itself
  is fixed by this ADR, its mechanism is S04's to build), and §11
  (dynamic views as query-only behaviour over the metadata S02–S03 already
  established).
- **FPA-P06-S05** implements §9 (`PhotoStory`/`PhotoComment`, author
  edit/removal, Owner/Administrator moderation authority) and §10
  (reactions, fixed vocabulary, the explicit no-ranking/no-engagement-feed
  guarantees).
- **FPA-P06-S06** implements §7 (soft deletion/restoration, creator vs.
  uploader authority per §1, retained-not-deleted associated rows) and
  must re-verify, not merely assume, that §8's delivery-layer check also
  respects a soft-deleted Photo's presentation state.
- **Required bypass regression tests**: (1) a Member with legitimate
  access to a `MediaUpload` ULID before it became part of a private Photo
  or restricted Album must be denied delivery afterward via the existing
  Phase 5 endpoints (§8); (2) adding a private Photo to a broader Album
  must be independently authorized and must not succeed as a side effect
  of ordinary Album-edit authorization alone (§5); (3) removing a Photo
  from every widening Album must restore exactly its intrinsic visibility,
  verified against live state rather than a cached grant (§5).
- §5's governing principle (intrinsic visibility vs. explicit sharing) and
  §2/§3/§4's shared proposed/authoritative pattern are both cross-cutting:
  every stage above that touches visibility or a provenance-shaped field
  should be checked against them during review, not assumed satisfied
  because the table exists.

## Review triggers

- **When Phase 7 (Events) is scoped**: give Guest its first concrete
  access model via Event/guest links, and confirm Event Albums extend
  `Album` (§6, §13) without requiring a superseding change to this ADR's
  visibility, `AlbumGrant`, or default-Member-contribution mechanism.
- **When Phase 8 (duplicate detection) is scoped**: confirm duplicate
  linkage is added as a Photo-to-Photo (or Photo-to-MediaUpload) relation
  that does not require reopening §1's one-`MediaUpload`-per-Photo
  constraint, and that consolidation respects §7's soft-deletion model
  rather than introducing a second deletion concept.
- **When Phase 9/10 (face analysis, clustering) are scoped**: confirm the
  `PhotoPerson` non-overwrite boundary (§4) is actually enforced by
  whatever ingestion mechanism those phases introduce, not merely
  documented; confirm machine-suggested identity surfaces as a *proposed*
  row through the existing mechanism.
- **When Phase 11 (search) is scoped**: confirm search does not bypass
  §5's visibility model or §8's delivery non-bypass guarantee, and that
  persisted/saved views build on top of §11's query-only foundation rather
  than duplicating it.
- **When Phase 13 (export/backup/retention) is scoped**: define the first
  real permanent-deletion and retention-countdown mechanism §7 explicitly
  declines to build in Phase 6.
- **If real usage shows the photographer/scanner/physical-owner
  proposed/authoritative gate (§2) is too heavy for how families actually
  contribute this information** (for example, wanting to record "probably
  Grandad" without a full proposal/confirmation round-trip), revisit
  deliberately rather than quietly loosening Owner/Administrator-only
  confirmation.
- **If real usage shows Member's default `family_space` contribution
  right (§6) is too permissive for a given family** (for example, an
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
- The fixed V1 reaction vocabulary's exact values (§10) — a functional
  requirement is fixed, the list is not.
- Exact `AlbumGrant`/`Photo`/`PhotoPerson` schema, migrations, route and
  policy class names — `docs/IMPLEMENTATION_GUIDE.md`.
- A curator-only `family_space`-visibility Album mode (view-without-
  default-contribute) — not built in Phase 6; §6's Review trigger applies
  if real usage demands it.

## Resolved decisions

1. **Photo ↔ MediaUpload** — required, unique, one-directional
   (`Photo -> MediaUpload`); a `ready` MediaUpload may exist indefinitely
   without a Photo; one MediaUpload backs at most one Photo.
   `Photo.created_by` identifies the Family Space member who created the
   Photo record and is intentionally distinct from `MediaUpload.user_id`
   (who uploaded the bytes); every later section's creator-based authority
   depends on `created_by` specifically.
2. **Provenance** — uploader derived from `MediaUpload.user_id`, never
   duplicated; photographer, scanner and original physical owner are
   single-valued, Person-reference-or-mutually-exclusive-free-text roles
   following the ADR-0006 §4 proposed/authoritative pattern; the
   physical-owner free-text field is named `physical_source_description`,
   never `source_album`; caption/description/physical-source description
   are ordinary content requiring no approval workflow.
3. **Historical date** — reuses ADR-0006 §3's uncertain-date concept
   unmodified; strictly separate from and never silently populated from
   `MediaUpload`'s EXIF capture timestamp; proposed/authoritative pattern
   applies.
4. **PhotoPerson** — implemented in FPA-P06-S03 with the same proposed/
   authoritative pattern; only confirmed rows are authoritative human
   ground truth; structurally separate from and never overwritten by
   future machine recognition (Phase 9/10).
5. **Photo visibility** — exactly two values, `family_space` and
   `private`; no separate restricted tier or Photo-level grant table;
   selected-audience sharing lives entirely in Albums; adding a private
   Photo to a broader Album is an explicit, authorized, UI-visible,
   tested widening operation, and removing it from every such Album
   automatically and immediately restores exactly its intrinsic
   visibility; Album membership never mutates the Photo's own visibility
   setting in either direction.
6. **Albums and Contributor** — `Album` (one Family Space, `created_by`
   curator, `AlbumPhoto` many-to-many, private/selected/family_space
   visibility) plus `AlbumGrant` (`can_view`/`can_contribute` per
   membership) as the sole selected-audience mechanism. Default
   contribution on a `family_space`-visibility Album is fixed product
   policy: Owner, Administrator, the Album's creator, and ordinary Member
   all contribute by default; Contributor contributes only through an
   explicit `AlbumGrant`; Guest never contributes. This is Contributor's
   first concrete resource-scoped grant, strictly Album-scoped, with no
   Family-Space-wide default under any visibility tier; Guest deferred to
   Phase 7; Family Circles remain unrelated to Album authorization.
7. **Deletion and restoration** — reversible soft deletion only; Owner/
   Administrator may act on any Photo; the creator may act on their own
   Photo while retaining Family Space access; `Photo.created_by` (not
   `MediaUpload.user_id`) is authority-bearing; Phase 5 assets are never
   affected; associated Album/story/comment/reaction rows are retained
   across a soft delete; no automatic purge exists.
8. **Delivery non-bypass** — once a MediaUpload is attached to a Photo,
   Photo/Album visibility becomes authoritative for canonical, variant
   and original delivery; the existing Phase 5 endpoint and the
   MediaUpload ULID alone must never bypass it; enforced and tested
   explicitly, mechanism left to FPA-P06-S04/implementation guide.
9. **Stories, comments, reactions** — `PhotoStory` (archival narrative)
   and `PhotoComment` (conversational) are author-editable/removable, with
   Owner/Administrator retaining moderation authority regardless of
   author; a small fixed reaction vocabulary with explicit no-ranking/
   no-engagement-feed guarantees.
10. **Dynamic views** — query-only in Phase 6; no persisted view entity;
    saved/combined views remain Phase 11.
11. **Tenancy** — Photo-domain tables are ordinary Class C tenant-owned
    tables under ADR-0005; no RLS SQL frozen here; `TenantOperationContext`
    and `AuditEvent` reused unchanged.
12. **Non-goals** — duplicate linkage (Phase 8), Event/Event-Album
    structure (Phase 7), machine face data (Phase 9/10), and persisted
    dynamic views (Phase 11) are explicitly not built in Phase 6.
