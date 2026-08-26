# ADR-0009: Events and Collaborative Sharing

- Status: Accepted
- Date: 2026-08-25
- Decision owners: David
- Related stages: FPA-P07-S01 (accepting this ADR completes that stage),
  implemented by FPA-P07-S02, FPA-P07-S03, FPA-P07-S04, FPA-P07-S05

## Context

Phase 6 (ADR-0008) closed with Photo as a real archival record: provenance,
historical date, `PhotoPerson`, tags, location, a two-value visibility
model, and Album as the one mechanism for sharing something more
selectively than the whole Family Space — deliberately built with no
Event foreign key and no Event-specific field, naming Phase 7 as the phase
that extends it. `PROJECT_ROADMAP.md`'s Phase 7 objective is "support
birthdays, holidays, weddings and other shared family occasions"; its exit
criteria fix three concrete guarantees this ADR must make architecturally
true: multiple relatives can contribute safely to one event; guest
contribution cannot expose the wider family archive; event exports retain
useful metadata.

This phase also closes two placeholders earlier ADRs deliberately left
open. ADR-0005 §3 already named all five roles, including Guest —
"explicitly limited or temporary access to resources specifically granted
to that guest. Concrete meaning deferred to Phase 7" — but gave Guest no
concrete meaning of its own. ADR-0007 §16 could not give Guest any access
because "Phase 5 has no Album, Event, or other resource to scope a
Contributor grant against," and named Phase 7 specifically as the phase
that would have to revisit Guest's baseline "since a guest-link
contribution surface is a meaningfully different trust level than today's
invite-only membership." That observation turns out to matter concretely:
`docs/IMPLEMENTATION_GUIDE.md` already names `FPA-P07-S04` **"Implement
restricted guest upload links"** and fixes "expired event links stop
working" as a phase-verification requirement — both written before this
ADR, both pointing at something more specific than an ordinary standing
membership: access that is admitted through a link and genuinely
time-bounded, not merely an invitation whose *unaccepted* token expires.

**A load-bearing fact this ADR now depends on: Phase 6 has been corrected
and the correction has landed.** `PhotoPolicy::hasAlbumAccess()`,
`AlbumQuery`/`PhotoQuery`'s visibility scoping, and `AlbumPolicy`/
`AlbumContributionFinalizer`'s contribution checks all now correctly deny
Guest access via `AlbumGrant` under the Phase 6 baseline — the gap this
ADR's first draft found and had to work around no longer exists. Phase 7
therefore does not "close a latent Phase 6 gap"; it narrowly *reopens* one
specific, Event-scoped exception to an already-correct blanket denial.
§15 states this precisely, because getting the framing backwards would
invite an implementation that reintroduces the exact bypass Phase 6 just
finished closing.

A second thread runs through this ADR that is worth naming as its own
principle, because it resolves what would otherwise be a real design
choice throughout this phase, and because it has now surfaced repeatedly
enough across ADR-0006 through this ADR to be treated as a durable product
principle rather than a one-off convenience:

**fambam should become richer naturally as a family uses it. It should
never require a family to maintain duplicate state or complete complex
structure before receiving value.** Where the system can reliably compute
a fact from data it already treats as authoritative, it should compute it
rather than ask a family to maintain a second, parallel record that can
drift from the first. ADR-0008 §7 already computes a Photo's reachability
live from current `AlbumPhoto`/`AlbumGrant` state rather than a cached
flag, for exactly this reason. Phase 7 applies the same discipline twice
over: Event attendance is derived from confirmed `PhotoPerson`
associations rather than a maintained roster (§11), and a Guest's ongoing
access is computed live — from a timestamp and the *currently effective*
configured lifetime, never a value frozen at admission time — rather than
maintained by a cleanup process (§8) or a scheduled sweep (§6, §7). These
are instances of one principle, not unrelated simplifications.

This ADR decides: the Event resource, who may create and edit it, how it
is discovered, and its relationship to Album; the guest-admission and
guest-participation model, including its deliberate departure from
ADR-0007/ADR-0008's existing original-download baseline; the full
lifecycle of an Event admission, including revocation and re-admission;
what a Guest's `family_space_membership` actually means and how it
relates to genuine family membership; how attendance is known at all; who
may assign a Photo's primary Event; and Event's own reversible removal
lifecycle. It deliberately does not decide anything about duplicate/
visual-hash detection between Photos (Phase 8), machine face suggestions
(Phase 9/10), or persisted dynamic views (Phase 11) — Phase 6's own
non-goals continue unchanged.

**Reconciliation note.** This text incorporates two bounded rounds of
reconciliation against Codex's implementation-blocker review, run before
any Phase 7 code was written. Nothing in the overall Event/Album/Guest
architecture has changed across either round. The first round closed an
implied Event visibility enum, an unaddressed Event removal/duplicate-
reference lifecycle, the S02/S04 boundary, an internal inconsistency
around the original-download rule, the snapshot-vs-live question for
guest-access lifetime, invitation/admission behaviour for existing
members, and the Guest conversation/Story split. This second round makes
Event-invitation acceptance fully deterministic under concurrent/
interleaved admission, gives Event admission an explicit state machine
(including revocation and re-admission), fixes an authorization baseline
for Event creation/editing and `primary_event_id` assignment that had been
left implicit, corrects §15's framing now that Phase 6 has actually
shipped its fix, and confirms duplicate-Event detection stays
implementation-guide detail rather than being decided here.

## Decision

### 1. Event is a first-class, Family-Space-owned resource

An `Event`:

- belongs to exactly one Family Space;
- has a creating/organising member, `created_by`;
- has a name, an optional description, an optional date range (start and
  end), and an optional free-text location;
- has a presentation-only status: `planned`, `active`, `completed`,
  `archived`. **`completed` does not imply immutable** — a family
  routinely keeps adding photographs and stories to an event long after
  it happened, and no status value gates ordinary contribution, viewing,
  or editing. Status is a display/organisational signal only, exactly as
  originally proposed, and is a fully independent concern from the
  reversible removal lifecycle §16 defines — an `archived` Event is not a
  deleted one, and a soft-deleted Event carries whatever status it last
  had.

Unlike a Photo's historical date, an Event's date range and location are
**not** run through the proposed/authoritative pattern §3 and §4 of
ADR-0008 established for uncertain historical facts. An Event's own date
and location are ordinarily known directly by the person creating it — "my
wedding is on the 12th" is not an uncertain claim about the deep past —
so they are edited directly by the creator or Owner/Administrator, the
same ordinary-content authority already established for a Photo's caption
and description (ADR-0008 §2). This is a deliberate, narrower reuse of an
existing pattern, not a new one.

### 2. Event creation and editing authority

An earlier draft of this ADR left Event creation and editing implicit.
Fixed explicitly:

| Action | Owner | Administrator | Member | Contributor | Guest |
|---|---:|---:|---:|---:|---:|
| Create an Event | yes | yes | yes | no | no |
| Edit any Event | yes | yes | no | no | no |
| Edit an Event they created | yes | yes | yes | no | no |
| Soft-delete / restore an Event | yes | yes | no | no | no |

Event creation is ordinary, collaborative Family Space participation,
matching the same Owner/Administrator/Member-by-default baseline this
product uses throughout (ADR-0006 §10, ADR-0008 §7/§8) — any Member can
start a wedding's Event just as they can start an Album. Editing is
narrower: Owner and Administrator may edit any Event, while a Member may
edit only the Event they themselves created, mirroring the
creator-or-manager pattern already established for Photo (ADR-0008 §1)
and Album (ADR-0008 §8). Soft deletion and restoration remain
Owner/Administrator-only, as §16 already fixes; that authority is not
repeated as a separate decision here, only cross-referenced for
completeness of this table.

### 3. Event discoverability

**There is no Event visibility enum in Phase 7.** Owner, Administrator and
Member discover every Event in their Family Space — full stop, not a
default among several possible values, because no other value exists. A
currently admitted Event participant (§6, §7) additionally discovers the
specific Event or Events they are admitted to, reached only through the
Event access path (§9) — never a general listing of the Family Space's
Events.

The distinction this section fixes is deliberately narrow:

```text
Event discovery       — whether the Event itself can be reached at all
Album visibility /     — which of that Event's content can be reached,
guest_participation      once the Event itself has been reached
```

Discovering that "Dave & Sarah's Wedding" exists tells a Member nothing
about whether they can see the Ceremony album — that remains entirely
governed by the Album's own visibility tier (ADR-0008 §8), unchanged by
this section. **No `private`/`selected` Event tier is introduced in
Phase 7.** Albums remain the sole fine-grained visibility boundary below
the Event; if a family ever needs an Event itself to be hidden from
ordinary Members (not merely its content), that is a deliberate future
decision, not something this phase should approximate with a half-built
enum.

### 4. Albums and Events

An Album may optionally belong to exactly one Event, via a nullable
`event_id` on `Album` — not a many-to-many relation. This matches the
product direction directly: an "Album" like *Ceremony* or *Reception*
belongs to one wedding, unambiguously. Albums remain exactly what ADR-0008
§8 already made them: independent, Family-Space-owned resources with
their own private/selected/family_space visibility, reachable directly
from the Family Space by Members regardless of whether they also belong to
an Event. Attaching an Album to an Event, detaching it, or moving it to a
different Event is an ordinary Album-management action under ADR-0008
§8's existing creator/Owner/Administrator authority — no new authority
model is introduced for this. `Album.event_id` is, together with `Event`
itself, introduced in **FPA-P07-S02** — see the S02/S04 boundary fixed
explicitly in the Implementation notes below; it is authorization-inert
until `guest_participation` (§5) exists.

### 5. Guest participation is an Album-level, Event-wide setting — not a grant

Every Album optionally carries a `guest_participation` value, meaningful
only when it belongs to an Event:

```text
guest_participation
none        — invisible to this Event's guests: no access of any kind
view        — guests may browse, comment, react, and download the
              preserved original (§10) — download is part of view, not
              an additional tier
contribute  — everything in view, plus guests may upload photographs.
              PhotoStory creation is not included at either tier — see
              §14
```

This value applies uniformly to **every** guest admitted to that Event —
there is no per-guest Album ACL matrix in V1, exactly as directed. This is
deliberately **not** implemented as `AlbumGrant`, even though it looks
superficially similar. `AlbumGrant` (ADR-0008 §8) is keyed to one
`family_space_membership_id` — one row per named individual — and using it
here would mean silently writing one `AlbumGrant` row per guest per Album
behind the scenes, which is exactly the per-guest ACL matrix this
direction explicitly rejects, just hidden one layer down. `guest_participation`
is instead a property of the Album itself (alongside its `event_id`):
one value, shared by an entire Event's guest cohort, with no per-membership
row to create, maintain, or clean up as guests come and go.

**`AlbumGrant` and `guest_participation` are additive, not
alternatives.** A named individual — most obviously a Contributor, but
also, deliberately, an individual Guest who should have more than their
cohort's shared level (the one guest who's also the semi-official
photographer, say) — can still receive their own `AlbumGrant` on an
Event-linked Album exactly as on any other Album, subject to §15's
Event-scoping. The one restriction carried over from today's corrected
Phase 6 baseline: a Guest-role membership only ever reaches an Album
through an Event they are currently admitted to (§6, §7) — either via that
Event's `guest_participation`, or via an individual `AlbumGrant` on one of
that Event's Albums. A bare `AlbumGrant` on an Album with no `event_id`
grants a Guest nothing, exactly as Phase 6 now correctly enforces, because
nothing in this product gives a Guest a route to discover or reach a
non-Event Album in the first place (§9).

### 6. Guest admission: invitation acceptance is deterministic under any membership state

`FPA-P07-S04`'s existing name — "restricted guest upload links" — and its
"expired event links stop working" verification requirement both describe
something with a genuine, ongoing expiry, not a one-time acceptance token.
This ADR resolves that by extending the invitation mechanism ADR-0004
already built and proved, rather than inventing a parallel, account-free
session model.

**The purpose of accepting an Event invitation is admitting the invitee to
the target Event. Creating or reactivating a Family Space membership is
conditional infrastructure — needed only when no suitable membership
already exists — never the point of the action itself.** Because of that,
acceptance must behave deterministically regardless of what membership
state the invitee happens to be in *at the moment they click accept*, not
only the state that was true when the invitation was issued. Concretely,
acceptance always proceeds in this order:

1. Resolve or create the `User` as usual (ADR-0004 §8, unchanged).
2. Resolve that `User`'s `family_space_membership` for this Family Space,
   if any.
3. **No membership exists** — create a `Guest`-role membership.
4. **A removed membership exists** — reactivate it in place, per ADR-0005
   §5's existing "newly invited role" behaviour, unmodified.
5. **An active membership already exists** — reuse it, unchanged:
   - the Event invitation is **never rejected** merely because a
     membership is now active;
   - the acceptance **never demotes or overwrites** an existing Owner,
     Administrator, Member or Contributor role — an Event invitation can
     only ever result in a `Guest`-role membership when it creates one
     from nothing (step 3); it never downgrades an existing higher role
     to Guest.
6. Create or reactivate the `event_admissions` record (§7) for the
   invitation's `event_id`, regardless of which of steps 3–5 applied.

This makes two Event invitations for the same email a legitimate,
correctly-handled case, not a conflict:

```text
Event A invitation issued
Event B invitation issued

Accept Event A
    -> no membership existed -> Guest membership created (step 3)
    -> Event A admission created (step 6)

Accept Event B later
    -> an active membership now exists -> reused unchanged (step 5)
    -> Event B admission created (step 6)
    -> no membership conflict, no rejection, no role change
```

Both invitations were validly issued (ADR-0005 §5's issuance-time guard —
rejecting an invitation issued to an already-active email — is unaffected
and continues to govern issuance); it is specifically *acceptance* that
must remain deterministic even though the invitee's membership state
changed between the two acceptances. The same determinism applies
regardless of ordering, timing, or whether the two invitations target the
same or different Events.

**Naming.** The dedicated admission record is named **`event_admissions`**
(§7), not `event_guest_admissions` as an earlier draft of this ADR had it.
This is a deliberate rename, not a cosmetic one: because step 5 lets an
already-active Member, Contributor, Administrator or Owner also receive an
admission, the record is no longer Guest-exclusive in scope, even though
Guest remains the only role whose *access* actually depends on it (§8).

**A second entry point exists alongside invitation acceptance**: Owner or
Administrator may directly admit an existing active membership — any
role — to a further Event without issuing or requiring acceptance of any
invitation at all. This is the same step 6 action, triggered
administratively instead of by an invitee's own acceptance, useful when
the family already knows exactly who they want admitted and sees no
reason to route it through an email round-trip. Like the six-step
algorithm above, it never touches `family_space_membership.role`.

Simultaneous valid admissions to different Events are expected, not an
edge case, and `Invitation`'s uniqueness for Event-scoped invitations is
scoped to `(family_space_id, email, event_id)`, not `(family_space_id,
email)` alone, so an email may hold more than one concurrent outstanding
Event-scoped invitation for different Events at once. The original
`(family_space_id, email)` uniqueness continues to govern ordinary,
non-Event membership invitations unchanged — this scoping applies only to
the Event-invitation case this section adds. Every path — new membership,
reactivated membership, or an existing membership reused or admitted
directly — **results in the same one membership row**; nothing in this
ADR ever creates a second membership for the same person.

This ADR does not introduce anonymous, account-free contribution. Every
Event participant reached through either entry point is a real `User`
with a real `family_space_membership`, reusing every existing tenancy,
RLS, audit and policy guarantee this project has already built and
proved, rather than standing up a second, parallel authorization surface
for one feature — §8 explains why that reuse is the right trade for Guest
specifically, and what it deliberately does not mean.

### 7. Event admission: state, validity, revocation and re-admission

`event_admissions` carries, at minimum — exact physical schema remains
implementation-guide detail, these fields are architectural:

```text
event_admissions
- event_id
- family_space_membership_id
- admitted_at
- revoked_at      (nullable)
- revoked_by      (nullable)
```

with a uniqueness constraint on `(event_id, family_space_membership_id)`:
**there is at most one admission record per membership per Event, ever** —
revocation and re-admission both act on that same row, never creating a
new one, for exactly the reason §7's "provenance, not attendance"
paragraph below already establishes: an admission's identity is "this
membership's relationship to this Event," not a log entry.

**Validity** is computed, live, at authorization time:

```text
valid admission =
    not revoked
    AND
    now() < admitted_at + current configured lifetime (§6)
```

**Authority:**

- Owner or Administrator may admit, re-admit, or revoke an Event
  admission for any membership.
- Invitation acceptance (§6) may establish or re-establish the admission
  for the specific Event named in the invitation being accepted — no
  broader authority than that.
- A Guest cannot revoke or otherwise alter their own admission state.

**Revocation:**

- is idempotent — revoking an already-revoked admission is a no-op
  success, matching this project's established idempotency discipline
  (ADR-0004 §8, ADR-0007 §4);
- records `revoked_at` and actor attribution (`revoked_by`);
- immediately removes Event authority the next time validity is
  evaluated — it does not touch, and has no effect on, the underlying
  `family_space_membership`;
- is audited.

**Re-admission** (whether via a fresh acceptance of an Event-scoped
invitation for the same Event, or a direct Owner/Administrator action):

- reuses the existing `event_admissions` row for that `(event_id,
  family_space_membership_id)` pair where one already exists, rather than
  creating a second one;
- clears any prior revocation state (`revoked_at`/`revoked_by` reset to
  `null`);
- sets a fresh `admitted_at`, and therefore begins a fresh validity
  window computed the same live way as any other admission;
- is audited distinctly from the initial admission where that
  distinction is useful for a family reviewing their own history (for
  example, "re-admitted" versus "admitted"), though both remain ordinary
  `AuditEvent` coverage, not a separate mechanism.

**No scheduled expiry process is introduced.** Validity is computed
live from the fields above every time it is checked, exactly as §6
already establishes for the lifetime itself — revocation is the only
thing that changes admission state before its natural lifetime would
otherwise have elapsed, and even that is an explicit, audited,
Owner/Administrator action, never a sweep.

**Provenance, not attendance.** `event_admissions` answers exactly one
question — *is this membership currently admitted to this Event?* — for
every role, not only Guest. It is distinct from the Guest membership
itself (§8), from derived attendance (§11, which is never built from this
table, for any role), and from the `Invitation` that may have established
it. For Guest, an admission is load-bearing: it is one of the two facts
§8's access composition requires. For every other role, recording an
admission is deliberate provenance and a basis for notifications (§18) —
it grants nothing, because that role's access already comes from
Family-Space membership or `AlbumGrant`, unaffected by this ADR.

### 8. Security membership is not family membership

**`family_space_membership` represents an authenticated security
relationship with a Family Space — the thing every policy, every RLS
check, and `TenantOperationContext` already key against — not
genealogical or social membership of the family.** ADR-0005 through
ADR-0008 never had to draw this line explicitly, because until now every
role a membership could hold (Owner, Administrator, Member, Contributor)
did, in practice, correspond to a real, ongoing family relationship. Guest
is the first role where that correspondence breaks down: a wedding
guest's caterer, uploading three photographs and never interacting with
this family again, is a legitimate `Guest`-role `family_space_membership`
under §6 — and is obviously not "a member of the family" in any sense a
person would recognise.

fambam's model is therefore explicitly three separate concepts, not two:

```text
Person and relationships  — family identity: who someone is to this
                             family, whether or not they can log in
                             (ADR-0006)
FamilySpaceMembership     — authenticated security relationship: what an
                             authenticated principal is currently allowed
                             to do (ADR-0005, this section)
EventAdmission             — this specific Event: which Events a
                             membership is currently admitted to,
                             load-bearing for Guest, provenance for
                             every other role (§6, §7)
```

A Guest membership exists solely to reuse the proven `TenantContext`,
PostgreSQL RLS, policy and audit infrastructure this project already
built and tested — nothing more. It must never make the product imply
that an Event guest has thereby become part of the family in the
genealogical or social sense. This ADR fixes that as an explicit,
durable architectural invariant:

> **Guest memberships must never be presented as ordinary family members.**

This is not a preference for tidier screens — it is the safeguard that
keeps §8's three-concept model honest in practice, not only on paper.
Concretely:

- **Guest memberships must not appear in ordinary "Family Members"
  management or presentation surfaces.** They are the least-trusted
  authenticated principal within the Family Space, present in the data
  model for security-boundary reasons, not because the product considers
  them part of the family's membership roster. Any current or future
  surface that lists, counts, or manages "the family's members" must
  exclude Guest-role rows by construction.
- **A Guest membership by itself grants no meaningful resource access —
  no general Family Space access of any kind.** Its authority is entirely
  composed from two further, independent facts:

  ```text
  Guest membership
          +
  valid EventAdmission (§7)
          +
  Album guest_participation (§5)
          =
  Event-scoped access
  ```

  and, symmetrically, the inert case:

  ```text
  Guest membership
          +
  no valid EventAdmission
          =
  no Event access
  ```

  The membership row itself is never an access grant — it is the
  authenticated identity the other two facts are evaluated against. The
  one explicit exception, already fixed in §5, is an individual
  Event-scoped `AlbumGrant`, which composes with a valid `EventAdmission`
  the same way `guest_participation` does; it does not bypass the
  composition, it is part of it. §18 fixes what this invariant requires
  of every other Family-Space-facing surface, not only Photo and media
  delivery.

**No automatic cleanup or removal of dormant Guest memberships is
introduced in V1.** Once every `EventAdmission` for a Guest membership has
expired or been revoked, the inert case above already applies — a dormant
Guest membership authorizes nothing, computed live, with nothing to run
and nothing to sweep. The membership row itself is retained indefinitely
by default, because it is what preserves audit history, uploaded
photographs and comments, `Person` linkage, and historical provenance for
that participant — deleting or archiving it would sever exactly the
archival continuity this product exists to protect, for no benefit beyond
a tidier membership count. This is a direct consequence of the
presentation invariant above, not a separate concern: the invariant
already means a dormant Guest is invisible in every ordinary "family
members" context, so there is no clutter left for cleanup to solve. If
real operational experience later shows dormant Guest memberships should
be archived or removed regardless, that is a separate, deliberate
lifecycle decision for a future ADR, not something this phase pre-builds
speculatively.

**Guest and Member are intentionally separate trust levels. Identity
remains stable; trust changes explicitly.** Promotion from Guest to
Member reuses ADR-0005 §3's existing role-change authority unchanged —
Owner or Administrator changes a membership's role, exactly as they
already can for any non-Owner role. This ADR fixes explicitly that such a
promotion:

- is Owner/Administrator-controlled;
- is **never automatic**;
- is **never inferred** from the number of Events attended or admitted
  to, photographs uploaded, comments made, or time spent in the system,
  however much of any of those has accumulated;
- **changes the existing membership's role rather than creating a new
  identity.**

The underlying `User` and their authenticated identity never change when
their trust level does — only the role on their existing membership row
changes, and every already-confirmed `PhotoPerson`, `PhotoStory`, and
uploaded Photo they're responsible for carries forward unchanged, because
none of it was ever tied to their role in the first place. No other
behaviour changes as a result of promotion.

### 9. Guest navigation vs. authorization

Guests experience Albums only through the Event they were admitted to —
they never browse the Family Space directly, and the Event landing
experience ("You're cordially invited... Enter Wedding") is the only route
a Guest's frontend ever offers them. **This must be a real backend
authorization boundary, not only a frontend routing convenience.** A Guest
role continues to receive **zero** default Family-Space-wide access of any
kind (ADR-0008 §16's existing baseline, unchanged and now correctly
enforced per Phase 6's own correction, and §18 below fixes the full
surface this must be checked against, not only Photo/Album); their only
access paths are an admitted Event's `guest_participation` (§5) and any
individual `AlbumGrant` scoped to that same Event (§5) — which is exactly
§8's "Guest membership by itself grants no meaningful access" restated at
the delivery layer. Guessing or enumerating a non-Event Album's
identifier grants a Guest nothing, and this must be proven by the same
class of bypass regression test ADR-0008 §5/§10 already required for
private Photos and restricted Albums — knowing an identifier must never
substitute for a real access path.

### 10. Original download is a deliberate, narrow exception to the existing baseline

ADR-0007 §16 and ADR-0008 §8/§10 fixed preserved-original download as
available to Owner, Administrator and Member only — **never** Contributor
or Guest, and never through `AlbumGrant` alone. `PROJECT_ROADMAP.md`'s
Phase 7 exit criteria and `PRODUCT_VISION.md` both name downloading a
curated event archive as a real, expected feature, which this baseline
would otherwise forbid outright for every Guest. **This ADR deliberately
and narrowly supersedes that baseline for one specific case**: a
currently-admitted Event guest may download the preserved original of any
Photo in an Album whose `guest_participation` is `view` or `contribute` —
**download is part of what `view` already grants (§5); `none` is the only
participation level that withholds it** — for that Event only, for as
long as their admission remains valid (§7).

This exception is scoped as narrowly as possible: it does **not** extend
to `AlbumGrant`-derived access outside an Event (a Contributor with an
ordinary `AlbumGrant.can_view` still cannot download originals, exactly as
ADR-0008 already fixed), and it does not extend to a Guest once their
Event admission has lapsed or been revoked, or to a Guest accessing an
unrelated Event or Album. The distinction that justifies loosening the
rule here and nowhere else: an Event guest downloading photographs of an
occasion they personally attended is the ordinary, expected use this
product exists to serve, not an incidental side effect of a lower-trust
grant.

### 11. Attendance is derived from confirmed `PhotoPerson`, not maintained

This is a direct application of "derived truth over maintained truth"
(Context): **the authoritative record of who attended an Event is the set
of distinct, confirmed People appearing in that Event's Photos**, not a
list anyone maintains by hand, and — this bears repeating now that §6/§7
introduce admissions for every role — **not `event_admissions` for any
role either.**

```text
Event
  ↓
Photos
  ↓
confirmed PhotoPerson
  ↓
distinct People
```

There is therefore:

- **no `EventAttendee` entity;**
- **no RSVP table in Phase 7.**

Computed by joining `PhotoPerson` (confirmed rows only, per ADR-0008 §5)
against Photos belonging to that Event's Albums (or carrying that Event as
their primary Event, §12). An Event's page projects this set directly; a
Person's page derives "appeared at these Events" from the same query, run
the other direction. As more photographs are tagged over time, both views
become richer automatically, with nothing to maintain and nothing that
can drift out of sync with the tagged photographs themselves — keeping
Event pages, Person pages, timelines and any future search or dynamic
view all synchronised from the same one authoritative source.

**This is deliberately a display concept, and it is not the access-control
mechanism.** Confirmed `PhotoPerson` cannot answer "who is currently
admitted to this Event" — an Event routinely exists, and people are
routinely admitted to it, before a single photograph has been taken or
tagged (§1 already allows this: `planned` status, no photos yet). §7's
`event_admissions` is the admission/access-control record for every role;
this section is the presentation record for every role. The two are
deliberately independent concepts (§8) and are expected to overlap
imperfectly in the way real families actually behave — someone admitted
who never appears in a photo, someone tagged who was never formally
admitted (a Member's own relative, tagged in a photo, who was never
issued an Event admission at all because they didn't need one for
access) — and neither is a weaker version of the other.

If a genuine future need emerges for participants who are relevant to an
Event but will never appear in a photograph (a registrar, an officiant), a
distinct, explicit `EventParticipant` concept can be introduced later, as
its own deliberate decision — not pre-built speculatively now.

### 12. A Photo's primary Event

A Photo may optionally carry one primary Event, via a direct,
explicitly-set `primary_event_id` field — **not** derived from which
Event-linked Album(s) the Photo happens to sit in. A Photo can belong to
several Albums (ADR-0008 §8), including Albums from different Events or no
Event at all; deriving "primary Event" from Album membership would make it
ambiguous whenever a Photo sits in two Event-linked Albums, and would make
it silently shift whenever Album membership later changes — exactly the
kind of implicit, surprising behaviour ADR-0008 §7 went out of its way to
avoid for visibility. It is introduced in **FPA-P07-S02** alongside
`Event` and `Album.event_id` (§4), authorization-inert from the moment it
exists.

**Assignment and correction authority**, left implicit in an earlier
draft and fixed explicitly here:

| Action | Owner | Administrator | Photo's creator | Other Member | Contributor / Guest |
|---|---:|---:|---:|---:|---:|
| Set/correct `primary_event_id` | yes | yes | yes, while they retain ordinary Photo edit authority | no, unless separately granted explicit authority over that Photo | no |

This is not a new authority — it follows exactly the same
creator-or-Owner/Administrator edit authority ADR-0008 already
established for a Photo's other ordinary, directly-editable fields
(caption, description; ADR-0008 §2, §16's "creator (see §2)" row), applied
here to one more field rather than inventing a parallel rule.
`primary_event_id` carries **no authorization consequence whatsoever** —
it is organisational provenance, in the same spirit as
`archive_source_description` (ADR-0008 §2), used for §11's attendance
projection and for a Photo's own display ("from: Dave & Sarah's
Wedding"), nothing more. Nothing about who may set it changes that; the
authority table above governs only who may write the field, never what
the field is permitted to influence.

### 13. Contribution

Members and Contributors contribute to Event Albums exactly as they
already contribute to any Album under ADR-0008 §8 — the default
`family_space`-visibility contribution policy, or an explicit
`AlbumGrant.can_contribute`, are both unchanged by this ADR. Guests
contribute media only where an Event's `guest_participation` is
`contribute` (§5), or where they individually hold
`AlbumGrant.can_contribute` on one of that Event's Albums (§5). Bulk
contribution reuses Phase 5's existing independent-per-file upload
semantics unchanged (ADR-0007 §14) — every file in a batch succeeds or
fails on its own — targeted at one Album via `MediaUpload.target_album_id`,
a field Phase 6 already introduced and `MediaUploadPolicy::complete()`
already authorises against `AlbumPolicy::contribute`. Phase 7 extends
that same authorisation check to also recognise a Guest whose admission
and Album `guest_participation` permit it — no new upload mechanism is
introduced, only a new authorisation path into the one that already
exists. This section governs **media contribution only**; §14 governs
conversational and archival-narrative contribution, which follow
different rules.

### 14. Guest conversational participation vs. archival Story authoring

A currently admitted Guest's authority over comments, reactions, and
Stories is **not** one undifferentiated "can this Guest interact"
question — it is two separate questions with two different answers:

```text
Guest, guest_participation = view or contribute, valid admission

  browsing        — yes
  comments        — yes
  reactions       — yes
  PhotoStory       — no, by default
  creation
```

Browsing, commenting and reacting are lightweight, ordinary conversational
participation, available to a Guest at `view` or `contribute` — the same
Album access that lets them see a Photo is what lets them comment on and
react to it; no additional grant is required. **`PhotoStory` is
different: it is archival narrative content, and remains governed by the
ordinary trusted-family/Contributor authority ADR-0008 §11 already
established for it, unchanged by this ADR.** A Guest does not gain Story
authority by being admitted to an Event, by holding `contribute`
participation, or even by holding an individual Event-scoped
`AlbumGrant.can_contribute` — `contribute` in this ADR means Guest *media*
contribution to the Event Album (§13), not blanket archival-authoring
capability. A Guest who should be trusted to write the family's narrative
about an occasion is a Guest the family should consider promoting to
Member (§8), not a permission this ADR grants at the Event-participation
layer.

This is a deliberate, narrow correction to how comment/reaction and Story
authority were previously modelled as one combined check: the two must be
authorised separately going forward, with Guest's comment/reaction
authority keyed to Album access (this section) and Story authority keyed
to the existing, unchanged ADR-0008 rule (which this ADR does not reopen
for Contributor or any other role — only Guest's position relative to it
is being fixed here, because Guest did not exist as a legitimate
Album-access holder before this ADR).

### 15. Delivery authorization: a narrow, additive exception to an already-correct baseline

Phase 6 has already shipped its own correction: `PhotoPolicy`,
`AlbumQuery`/`PhotoQuery`, `AlbumPolicy`, and `AlbumContributionFinalizer`
all now correctly deny Guest access via `AlbumGrant` under the Phase 6
baseline, with no Event concept involved at all. **`FPA-P07-S04`'s job is
therefore not to close a Guest bypass — Phase 6 already closed it — it is
to narrowly reopen one specific, Event-scoped exception on top of that
already-correct blanket denial**, while leaving the denial intact
everywhere else:

```text
Guest + AlbumGrant on an Album with no event_id
    -> denied (Phase 6 baseline, unchanged)

Guest + AlbumGrant or guest_participation on an Event-linked Album,
with a currently valid EventAdmission for that Event
    -> the narrow Phase 7 exception (§5, §7, §8)

Guest, otherwise
    -> denied
```

`FPA-P07-S04` extends `PhotoPolicy` and `MediaUploadPolicy` to consult
Event admission and `guest_participation` for canonical, variant, and —
per §10 — original delivery, and to authorise comments/reactions per §14,
**strictly by adding this one additive condition to the Guest branch each
policy already correctly denies by default** — never by loosening the
underlying denial itself, and never by reverting any part of Phase 6's
correction. No intermediate stage may leave a Guest able to reach
Event-scoped media or conversation through a route the policy layer
doesn't yet know about; §4 and §12 already fix that `Album.event_id` and
`Photo.primary_event_id` themselves land earlier, in `FPA-P07-S02`, as
inert columns — this section is specifically about when they start being
*consulted* for Guest authorization, which is later, and deliberately so.

### 16. Event lifecycle and removal

**Phase 7 does not hard-delete Events.** Event removal is reversible soft
deletion — tombstoning, in the same sense ADR-0008 §9 already established
for Photo — available to Owner or Administrator only (§2). Soft-deleting
an Event:

- hides it from ordinary presentation, including §3's discovery surfaces;
- disables Guest admission and access through that Event — an
  `EventAdmission` that would otherwise still be within its live-computed
  validity window (§7) stops granting anything the moment its Event is
  soft-deleted, checked live alongside admission validity and
  `guest_participation`, not by mutating the admission itself;
- **retains** `Album.event_id`, `Photo.primary_event_id`,
  `Invitation.event_id`, and every `event_admissions` row referencing it,
  unchanged — these references are not nulled, cascaded, or otherwise
  disturbed;
- does **not** destroy Albums, Photos, or any Phase 5 media. An Album that
  belonged to a soft-deleted Event remains fully reachable through its own
  direct, non-Event visibility and `AlbumGrant` state (ADR-0008 §8),
  exactly as it would if it had never belonged to an Event at all — only
  the Event-linked guest-participation experience disappears.

Owner or Administrator may restore a soft-deleted Event, symmetric with
Photo's own restore pattern (ADR-0008 §9): presentation and discovery
resume, and Guest access resumes wherever an `EventAdmission` is still
within its live-computed validity window — nothing needed to be
reconstructed, because nothing was ever destroyed or swept while the
Event was soft-deleted.

**Family Space teardown (ADR-0005) remains the sole destructive path**
and must be extended to remove this phase's own rows (`Event`,
`event_admissions`, and the Event-scoped columns on `Album`/`Photo`) as
part of tenant deletion, the same way every prior phase's teardown
extension has worked (ADR-0007 §18, ADR-0008 §9). Phase 7 introduces no
standalone permanent-deletion path of its own.

### 17. Duplicate Events

Duplicate Events are never merged automatically. Owner or Administrator
review possible duplicates, choose a surviving Event, and resolve the
duplicate by hand:

- Album re-parenting (§4) and `Photo.primary_event_id` corrections (§12)
  to the surviving Event are ordinary edits, each already covered by its
  own existing authority and audit trail;
- the duplicate Event is then soft-deleted (§16) — never hard-deleted,
  and never left simply orphaned or ambiguously "resolved" with no
  disposition of its own.

**The exact duplicate-candidate heuristic is not decided here.** This ADR
fixes only that `FPA-P07-S02`'s duplicate-review surface requires a
deterministic candidate-selection heuristic, documented in
`docs/IMPLEMENTATION_GUIDE.md` before implementation — not invented ad hoc
while writing the query. That heuristic may reasonably use signals such
as normalized title similarity, overlapping or near-adjacent dates, and
similar location text, but whatever it uses is **advisory only**: it
surfaces candidates for a human to look at, and never on its own
constitutes an identity decision, never triggers an automatic merge, and
is never authorization-relevant — the same discipline ADR-0006 §12
already applies to Person duplicate suggestions, reused here rather than
reinvented.

Unlike Person merge (ADR-0006 §12), this ADR does **not** adopt the full
tombstone/structured-provenance/bounded-reversal machinery, and it is
worth being precise about why, now that §16 gives the retired Event a
real tombstone of its own: `Album.event_id`, `Photo.primary_event_id`,
`Invitation.event_id` and `event_admissions` rows genuinely do reference
an Event, and continue to after a duplicate is resolved — the retired
Event row is retained, soft-deleted, not removed, so none of those
references ever point at a row that no longer exists. What Person merge
needed and this does not is the *reconciliation*: Person merge atomically
collapses many interconnected reference types (relationships, circle
memberships, User links) with conflict resolution and a structured ledger
built specifically to support safe, bounded reversal of that one atomic
operation. Resolving a duplicate Event is a handful of independent,
ordinary edits (each individually reversible through its own existing
history) followed by one already-reversible soft-delete (§16) — there is
no batch operation here whose own bespoke reversal ledger would earn its
keep. A lightweight review surface — flagging likely duplicates for
Owner/Administrator attention using the implementation-guide-defined
heuristic above — is sufficient, and matches this phase's own "avoid
unnecessary workflow engines" instruction.

### 18. Explicit non-goals

- **Anonymous, account-free contribution** — not built. Every Event
  participant is a real `User` with a real `family_space_membership`
  (§6).
- **A maintained `EventAttendee` or RSVP entity, for any role** — not
  built. Attendance is derived (§11), independent of `event_admissions`
  for every role; a distinct `EventParticipant` concept for
  non-photographed participants is a deliberate later decision if a real
  need emerges (§11).
- **Per-guest Album permissions as the default mechanism** — not built;
  an individual `AlbumGrant` remains available (§5) but is the exception.
- **Automatic Guest-membership cleanup, archival or removal** — not
  built (§8). A dormant Guest membership with no valid admission simply
  authorizes nothing; the row itself is retained.
- **Automatic Guest-to-Member promotion** — not built (§8). Role change
  is always an explicit Owner/Administrator action.
- **An Event visibility enum, or a `private`/`selected` Event tier** —
  not built (§3). Discovery is binary by role (Owner/Administrator/Member
  discover every Event; Guests discover only Events they're admitted to);
  fine-grained content access remains entirely Album-governed.
- **Hard deletion of an Event, or an Event-specific merge/tombstone
  ledger for duplicates** — not built (§16, §17). Event removal is
  reversible soft deletion; duplicate resolution is manual re-parenting
  plus that same soft deletion, guided by an advisory-only heuristic.
- **A scheduled Event-admission expiry or cleanup process** — not built
  (§7). Validity is computed live; revocation is the only state change,
  and it is always an explicit, audited, Owner/Administrator action.
- **Photo-to-Photo duplicate linkage, machine face suggestions, persisted
  dynamic views** — continue to belong to Phases 8, 9/10 and 11
  respectively, per ADR-0008 §15, unchanged by this ADR.

## Alternatives considered

- **Implementing `guest_participation` as one `AlbumGrant` row per guest
  per Album** — rejected: technically satisfies the schema but recreates
  the per-guest ACL matrix this direction explicitly rejects, with real
  ongoing write/cleanup cost as guests are admitted and removed.
- **A many-to-many `Album`↔`Event` relation** — rejected: no stated
  product need for one Album to belong to several Events simultaneously,
  and the product's own examples (Ceremony, Reception) consistently treat
  an Album as belonging to exactly one occasion.
- **An anonymous, account-free "upload link" with no underlying `User` or
  membership** — considered, given `PRODUCT_VISION.md`'s "event upload
  links" phrasing and the implementation guide's existing "restricted
  guest upload links" stage name. Rejected for V1: every tenancy, RLS,
  audit and policy guarantee this project has built since ADR-0005 depends
  on an authenticated `User` with a real `family_space_membership`;
  standing up a second, parallel, account-free authorization surface for
  one feature would duplicate that entire proven mechanism rather than
  reuse it, for a trust/convenience trade-off with no clearly demonstrated
  V1 requirement.
- **A lighter "authenticated `EventAdmission`" principal that never
  becomes a `family_space_membership` at all** — considered directly as
  an alternative to reusing membership. Rejected: `TenantContext`
  resolution, every RLS policy, every policy class, and `AuditEvent`
  attribution all key off a resolved membership today; a membership-free
  Guest principal would need its own parallel tenant-context-resolution
  path and either a second RLS policy shape or no database-level
  isolation for Guest sessions at all, directly against the defence-in-
  depth principle ADR-0005 established from the start. §8 resolves the
  legitimate concern this alternative was reaching for (a Guest
  shouldn't *look* like a family member) at the presentation layer
  instead, without paying that cost.
- **An Event visibility enum mirroring Photo/Album's `family_space`/
  `private` (or three-tier) model** — rejected: nothing in this phase
  demonstrates a need for an Event itself, as opposed to its content, to
  be hidden from ordinary Members; discovery-vs-content-access (§3) is
  sufficient, and a speculative enum with only one real value in use
  would be worse than no enum at all.
- **Rejecting Event-invitation acceptance whenever the invitee's
  membership state has changed since issuance** — rejected: would make
  two validly-issued, concurrent Event invitations for the same email an
  unpredictable race instead of a deterministic outcome, and would
  contradict this ADR's own goal of a Guest (or any role) plausibly
  holding admissions to several Events over time (§6).
- **Treating an accepted Guest invitation's own `accepted_at` as
  sufficient to compute admission and expiry, with no separate admission
  record** — rejected: cannot represent the same membership being
  admitted to a second Event later, cannot represent revocation or
  re-admission (§7), and cannot represent a directly-admitted
  already-active membership that never went through acceptance at all;
  the small, dedicated `event_admissions` record is simpler than
  overloading `Invitation`'s existing, already-audited semantics to mean
  something new.
- **Recording a new `event_admissions` row on every re-admission instead
  of reusing and resetting the existing one** — rejected: would turn a
  single-fact "is this membership currently admitted" record into a
  history log, complicating the uniqueness and validity model for no
  benefit `AuditEvent` doesn't already provide for anyone wanting the
  history itself.
- **Snapshotting the configured guest-access lifetime onto the admission
  row at creation time** — rejected: would mean a family's later decision
  to shorten or lengthen the lifetime silently fails to apply to guests
  already admitted, contradicting "access is computed, not swept" and
  requiring an explicit backfill or second mechanism to actually change
  existing access.
- **A scheduled sweep that expires or revokes guest admissions** —
  rejected: nothing needs to be mutated for "expired... links stop
  working" to be true; computing validity live at authorization time is
  simpler and mirrors the existing computed-due-work pattern already
  proven for Family Space deletion and abandoned uploads; revocation
  remains a deliberate, audited, human action, not an automated process.
- **Allowing a Guest to revoke or otherwise manage their own admission**
  — rejected: admission authority mirrors every other Event-management
  decision in this ADR (Owner/Administrator-controlled); a Guest managing
  their own access would be a new, unscoped self-service capability with
  no stated product need.
- **Automatically archiving or removing a Guest membership once it has no
  valid admission** — rejected: would sever audit history, uploaded
  photographs and comments, `Person` linkage, and provenance attribution
  for no benefit beyond a tidier membership count; §8 fixes that a
  membership with no current access is already fully inert, which is
  sufficient on its own.
- **Automatically promoting a Guest to Member after repeated Event
  attendance** — rejected: trust-level changes are a deliberate family
  decision, not an earned/automatic outcome of activity; conflating
  "attended several times" with "should be trusted like a Member" is
  exactly the kind of inference this product avoids making on a family's
  behalf.
- **Granting Story-authoring authority to a Guest at `contribute`
  participation, on the reasoning that they can already upload media** —
  rejected: uploading a photograph and authoring the family's archival
  narrative about an occasion are different acts with different trust
  implications; conflating them would let Event-scoped participation grant
  an archival-authoring capability ADR-0008 deliberately reserves for
  trusted, ongoing family roles.
- **Leaving Event creation/editing authority and `primary_event_id`
  assignment authority implicit** — rejected: both are ordinary write
  authority this ADR otherwise fixes precisely for every other Phase 7
  resource; leaving them unstated would hand Codex an ambiguity this
  reconciliation exists specifically to remove.
- **Deriving a Photo's primary Event from Event-linked Album membership
  rather than an explicit field** — rejected: ambiguous whenever a Photo
  sits in Albums from two different Events, and would silently shift as
  Album membership changes later, contradicting the explicit, non-implicit
  discipline ADR-0008 §7 already established for Photo state.
- **Hard-deleting an Event, or requiring Family Space teardown as the
  only way to remove one** — rejected: an Owner correcting a mistakenly
  created or genuinely unwanted Event needs an ordinary, reversible
  removal path, the same as every other archival resource in this
  product; hard deletion would also orphan or force awkward handling of
  the very references (`Album.event_id`, `Photo.primary_event_id`) this
  ADR otherwise keeps stable.
- **Fixing an exact duplicate-Event heuristic in this ADR** — rejected:
  the specific signals worth weighing (title similarity, date proximity,
  location text) are a tuning concern that may reasonably change with
  real usage; the architectural commitment is that a deterministic,
  documented, advisory-only heuristic exists, not which one.
- **Full Person-merge-style tombstone/reversal machinery for duplicate
  Events** — rejected as disproportionate, and reconsidered directly
  against the fact that §16 already gives a retired Event a real
  tombstone: what Person merge needed beyond a tombstone was atomic,
  conflict-resolving reconciliation of many interconnected reference
  types with its own bounded-reversal ledger; resolving a duplicate Event
  is a handful of independently-reversible ordinary edits plus one
  already-reversible soft delete, with no batch operation requiring a
  bespoke ledger of its own.
- **Extending the Guest original-download exception to ordinary,
  non-Event `AlbumGrant` access** — rejected: would quietly loosen
  ADR-0007/ADR-0008's original-download baseline far beyond what this
  phase's actual requirement (downloadable event archives) demands; the
  exception is scoped to admitted Event guests only.
- **Making guests' entry point a UI-only convention, with the backend
  continuing to authorize by ordinary Family-Space role alone** —
  rejected: "guests never browse the Family Space directly" must be a
  real authorization boundary or it provides no actual protection against
  a guest who guesses or is given a non-Event Album's URL.

## Consequences

### Positive

- Making Event-invitation acceptance deterministic under any membership
  state (§6) means two concurrently outstanding Event invitations for the
  same email are a correctly-handled, expected case rather than an
  unspecified race Codex would have had to guess about.
- Giving `event_admissions` an explicit state machine (§7) — admit,
  revoke, re-admit, all live-computed — means Owner/Administrator have a
  real, auditable lever over Guest access without needing a second
  mechanism or a scheduled process to make it take effect.
- Fixing Event creation/editing and `primary_event_id` assignment
  authority explicitly (§2, §12) closes two authorization gaps before
  implementation rather than leaving them to be improvised.
- Correcting §15's framing now that Phase 6 has actually shipped its fix
  means `FPA-P07-S04` can be implemented and reviewed as "add one narrow,
  additive exception" rather than "find and close a gap that no longer
  exists," which is both more accurate and a smaller, more reviewable
  change.
- Keeping the duplicate-Event heuristic itself out of the ADR (§17) means
  it can be tuned against real usage without reopening architecture, while
  still guaranteeing it will never be treated as an identity or
  authorization decision.
- Reusing ADR-0004's invitation mechanism for new participants, and a
  role-blind direct-admission action for already-active members, means
  Phase 7 introduces no second, parallel account-creation or
  authorization system, and makes "an Event invitation can never silently
  change an existing role" true by construction rather than by
  convention.
- Fixing "Guest memberships must never be presented as ordinary family
  members" as an explicit invariant (§8), rather than an implied
  convention, resolves the Guest role's real conceptual awkwardness at
  the presentation layer, where it belongs, without paying the cost of a
  second authorization model.
- Deriving attendance from confirmed `PhotoPerson`, independent of
  `event_admissions` for every role, means Event pages, Person pages, and
  any future search or timeline feature all read from one authoritative
  source, with nothing that can quietly drift the way a separately
  maintained attendee list could.
- Splitting Guest conversational participation from Story authoring (§14)
  closes a real gap before implementation rather than after.
- Giving Event a real, reversible removal lifecycle (§16) before any
  implementation began means Owner/Administrator correction of a mistaken
  or unwanted Event, and duplicate resolution (§17), both have a properly
  considered disposition.

### Negative

- `event_admissions` now carries real state-machine complexity — admitted,
  revoked, re-admitted, each live-computed — rather than a single
  timestamp; a small but real increase in what an implementer and a
  reviewer both have to hold in mind correctly.
- The deterministic six-step acceptance algorithm (§6) must be
  implemented exactly, including the case where membership state changes
  between two acceptances of different Event invitations for the same
  email; an implementation that only tests the simple "no prior
  membership" case would miss the scenario this reconciliation exists to
  fix.
- Guests can now legitimately hold an `AlbumGrant` (§5), which means the
  authorization logic must correctly distinguish an Event-scoped grant
  (valid) from a bare one (meaningless, and denied by the Phase 6
  baseline) — one more case for a future reviewer to hold in mind.
- Retaining every dormant Guest membership indefinitely (§8) means a
  Family Space's total membership count grows unbounded over years of
  events, with low-engagement participants (a caterer, a one-off guest)
  permanently present in the data model — an accepted trade for
  provenance preservation, not a defect, but a real and permanent
  characteristic of the design.
- The original-download exception (§10) is a real, if narrow, carve-out
  in an otherwise consistent rule, and depends on correctly checking a
  live admission validity window every time.
- Splitting comment/reaction authority from Story authority for Guest
  (§14) means `PhotoPolicy` carries one more authorization axis than a
  single combined `interact()` check did.

### Risks

- If the six-step acceptance algorithm (§6) is implemented as two
  independent code paths (one for "new membership," one for "existing
  membership") rather than one deterministic flow, a future change to one
  path could silently desynchronise it from the other, reintroducing the
  exact non-determinism this reconciliation exists to close.
- If `event_admissions`' uniqueness constraint `(event_id,
  family_space_membership_id)` is not enforced at the database level, a
  race between re-admission and a fresh acceptance could create a second
  row instead of updating the existing one, breaking §7's "at most one
  admission per membership per Event" invariant.
- If revocation is implemented by deleting the `event_admissions` row
  instead of setting `revoked_at`/`revoked_by`, re-admission would have
  no prior state to clear or audit trail to distinguish itself against,
  and idempotent re-revocation would have nothing to act on.
- If a future implementation surfaces Family Space membership counts or
  lists without deliberately excluding Guest-role rows, §8's explicit
  invariant — "Guest memberships must never be presented as ordinary
  family members" — would be silently violated the first time a new
  membership-facing surface is built without checking this ADR.
- If §15's narrow, additive exception is implemented by loosening the
  Guest branch's default behaviour instead of adding one specific,
  scoped condition on top of it, Phase 7 could silently reintroduce the
  exact non-Event Guest bypass Phase 6 just finished closing.
- If the original-download carve-out (§10) is implemented as a broad "is
  this an Event guest" check rather than checking the specific Album's
  `guest_participation` (`view` or `contribute`, never `none`) and the
  admission's current validity together, a guest could end up downloading
  originals from an unrelated Album, or after their admission has lapsed
  or been revoked.
- If `primary_event_id` (§12) is ever consulted by an authorization check
  in a later phase, that would silently contradict this ADR's fixed
  position that the field carries no authorization consequence.
- If a future implementation authorizes Guest comment/reaction and
  Story-creation through the same check after all (reverting §14's
  split), a Guest could gain archival-authoring authority ADR-0008 never
  intended for their role.
- If Event soft-deletion (§16) is implemented by nulling or cascading
  `Album.event_id`/`Photo.primary_event_id` rather than leaving them
  pointed at the retained, soft-deleted Event row, restoration would lose
  exactly the associations it's meant to restore.

## Implementation notes

- **FPA-P07-S02** implements §1 (`Event` schema, status as presentation
  only), §2 (Event creation/editing authority), §3 (Event discoverability:
  Owner/Administrator/Member discover every Event; no visibility enum),
  §4 (`Album.event_id`, nullable, single-Event, introduced here and
  authorization-inert until S04), §12 (`Photo.primary_event_id`, likewise
  introduced here, explicit, authorization-inert, and its own assignment-
  authority table), §11 (the derived-attendance query, both directions:
  Event → confirmed People, Person → Events), and §17 (the lightweight
  duplicate-Event review surface — this stage must also produce the
  deterministic, documented candidate-selection heuristic §17 requires
  before the review surface can be considered complete).
- **FPA-P07-S03** implements §13 (Member/Contributor Event-Album media
  contribution, reusing ADR-0008 §8's existing default-contribution and
  `AlbumGrant` mechanisms unchanged, and Phase 5's existing
  `target_album_id` bulk-upload path extended to Event Albums).
- **FPA-P07-S04** implements §5 (`Album.guest_participation`), §6 (the
  deterministic six-step Event-invitation acceptance algorithm,
  `Invitation.event_id` and its `(family_space_id, email, event_id)`
  uniqueness scoping, and the direct-admission entry point), §7
  (`event_admissions`' full state machine: admission, revocation,
  re-admission, and live-computed validity), §8 (the security/
  family-membership presentation distinction, including excluding Guest
  rows from any membership-facing surface, and the explicit no-cleanup /
  explicit-promotion-only rules), §9 (the backend Guest navigation
  boundary, proven by bypass regression tests), §10 (the narrow
  original-download exception), §14 (splitting `PhotoPolicy::interact()`
  into separately-authorised comment/reaction and Story-creation checks,
  with Guest gaining only the former), and §15 (extending `PhotoPolicy`/
  `MediaUploadPolicy` with the one additive, Event-scoped exception on
  top of Phase 6's already-correct blanket Guest denial).

  **This stage's scope is explicitly not limited to Photo and media
  delivery.** §8's invariant — a Guest membership alone equals no general
  Family Space access — must be audited and regression-tested across
  every existing surface that currently treats "an active membership
  exists" as sufficient, at minimum: Family Space discovery/details;
  membership lists, counts and management; People; relationships;
  invitations; Albums; Photos; media delivery/download; and any other
  route or query not listed here that makes the same assumption. A Guest
  must only ever reach a resource through one of the explicitly accepted
  Event-scoped paths this ADR defines (§5, §6, §9) — never through a
  surface that simply forgot Guest was a possible active-membership role
  when it was written, before this ADR existed.
- **FPA-P07-S05** implements notifications and curated Event archive
  export (originals plus a metadata manifest, satisfying the roadmap's
  "event exports retain useful metadata" exit criterion). This ADR fixes
  only that these exist; before FPA-P07-S05 implementation begins, the
  Implementation Guide must separately resolve export authorization,
  export storage and lifetime, notification recipients, and the concrete
  export format — all implementation decisions, not architecture, unless
  one of them is later found to alter the trust model this ADR fixes.
- **Required bypass and correctness regression tests**: (1) a Guest
  admitted to Event A must be denied access — view, download, and
  enumeration — to any Album not linked to Event A, including by directly
  requesting a known Album or `MediaUpload` identifier; (2) a Guest's
  access to Event A's Albums must correctly stop once their admission's
  computed validity window has elapsed, without any row needing to change
  state for this to be true; (3) a Guest's original-download eligibility
  must be checked against the specific Album's `guest_participation`
  (`view` or `contribute`, never `none`) and current admission validity
  together, not a broad "is-an-Event-guest" flag; (4) an `AlbumGrant` for
  a Guest role on an Album with no `event_id` must grant nothing,
  matching the corrected Phase 6 baseline; (5) §8's presentation
  invariant — every current or future endpoint, query, or view that
  lists, counts, or manages Family Space members must exclude Guest-role
  rows by construction; (6) accepting or being admitted to an Event
  invitation as an already-active Owner, Administrator, Member or
  Contributor must never change that membership's role; (7) two Event
  invitations for the same email, issued while no membership exists, must
  both succeed on acceptance regardless of order, with the second
  acceptance reusing the membership the first created and creating only
  a second `event_admissions` row; (8) a Guest at `view` or `contribute`
  participation must be able to comment and react but must be denied
  creating a `PhotoStory`; (9) soft-deleting an Event must immediately
  deny Guest access through it even for an otherwise-still-valid
  admission, and restoring it must resume access with no data to
  reconstruct; (10) lowering or raising a Family Space's configured
  guest-access lifetime must immediately change the validity of
  already-existing admissions, not only new ones; (11) revoking an
  admission must immediately deny access and must be idempotent; re-
  admitting must reuse the same `event_admissions` row, clear prior
  revocation, and begin a fresh validity window; (12) only Owner/
  Administrator may create/revoke/re-admit an Event admission for another
  membership, and a Guest may never alter their own.
- The "derived truth over maintained truth" principle, §7's live-computed
  validity model, and §8's no-cleanup rule are all cross-cutting: any
  future Phase 7 addition that's tempted to introduce a maintained list,
  a snapshotted value, or a scheduled expiry/cleanup job should be
  checked against them first.

## Review triggers

- **When Phase 8 (duplicate detection) is scoped**: confirm Event
  duplicate review (§17) and Photo duplicate detection remain distinct,
  independently-triggered workflows that don't get silently merged into
  one, and that neither's heuristic is ever treated as authorization-
  relevant.
- **When Phase 9/10 (face analysis) are scoped**: confirm machine-
  suggested `PhotoPerson` rows, once confirmed by a human, correctly and
  automatically enrich §11's derived attendance — no new attendance
  mechanism should be needed, only correctly feeding the existing query.
- **When Phase 11 (search/dynamic views) is scoped**: confirm search does
  not bypass §9's Guest boundary or §10's original-download carve-out,
  and that "who attended" as a dynamic view builds on §11's derived query
  rather than a new index.
- **If real usage shows families want a genuinely hidden Event (not only
  hidden content)** — revisit §3's deliberately binary discovery model
  rather than approximating it with Album visibility alone.
- **If real usage shows families want to invite non-Guest roles
  (Member, Contributor) to a formal "attending" list distinct from
  ordinary Album access** — revisit deliberately; this ADR deliberately
  does not build RSVP/attendee tracking for any role, Guest included
  (§11), and the roadmap's "Contributors and attendees" scope line is
  resolved here as: Contributors get Album-scoped access exactly as
  before, with no separate attendee concept for them either.
- **If real usage shows the configurable guest-access lifetime (§6) is
  poorly suited to some occasions** (a slow-burn "family archive" Event
  with no natural end, versus a single-day wedding) — revisit the default
  and whether an Event should be able to set "no expiry" explicitly,
  rather than only a longer configured duration.
- **If operational experience later shows dormant Guest memberships
  should be archived or removed after all** — revisit as its own
  deliberate lifecycle decision (§8); this ADR deliberately does not
  pre-build that mechanism speculatively.
- **If real usage shows a Guest should be able to earn or request Story
  authority for a specific Event without a full promotion to Member** —
  revisit §14 deliberately rather than quietly loosening it.
- **If the duplicate-Event heuristic (§17) proves noisy or ineffective
  once real usage exists** — tune or replace it in the Implementation
  Guide; this remains an implementation concern, not a reason to revisit
  this ADR, unless the family ever wants it to become more than
  advisory.

## Deferred concerns

- Anonymous, account-free Event contribution (§6, §18) — not built;
  every participant is a real membership.
- `EventParticipant` for non-photographed participants (§11) — a
  deliberate later decision if a real need emerges.
- The exact guest-access-lifetime configuration mechanism, its
  precedence (Family-Space vs. Event-level), and its default value (§6)
  — a functional requirement (live-computed, never snapshotted) is
  fixed, the mechanism is not.
- The exact `event_admissions` physical schema beyond the fields fixed in
  §7 — migrations, indexes, and cascade behaviour remain
  `docs/IMPLEMENTATION_GUIDE.md` detail.
- The exact duplicate-Event candidate-selection heuristic (§17) — a
  requirement that one exist, and that it stay advisory-only, is fixed;
  its signals and thresholds are not.
- Event notification content, delivery, and recipients; export
  authorization, storage/lifetime, and concrete format (§18/FPA-P07-S05)
  — all `docs/IMPLEMENTATION_GUIDE.md` concerns, to be resolved before
  FPA-P07-S05 implementation begins.
- Whether an Event can ever have "no expiry" for guest access (Review
  triggers) — not decided here.
- Dormant Guest-membership archival or removal (§8, Review triggers) — not
  decided here; V1 retains every membership indefinitely by design.
- A genuinely hidden (not merely content-restricted) Event (§3, Review
  triggers) — not built; discovery is binary by role in V1.
- A path for a Guest to gain Story authority for a specific Event without
  full promotion to Member (§14, Review triggers) — not built.

## Resolved decisions

1. **Event** — a first-class, Family-Space-owned resource with a name,
   optional description/date-range/location edited directly (not
   proposed/authoritative), and a presentation-only status, independent
   of the removal lifecycle (§16), that never implies immutability.
2. **Event creation/editing authority** — Owner, Administrator and Member
   may create an Event; Owner/Administrator may edit any Event; a Member
   may edit only the Event they created; Contributor/Guest may do
   neither. Soft deletion/restoration remains Owner/Administrator-only
   (§16).
3. **Event discoverability** — no visibility enum; Owner, Administrator
   and Member discover every Event; a currently admitted participant
   additionally discovers the Event(s) they're admitted to, through the
   Event access path only. Discovery (can the Event be reached) and
   Album visibility/`guest_participation` (what content within it can be
   reached) are independent axes; no `private`/`selected` Event tier
   exists.
4. **Album↔Event** — a nullable single `event_id` on `Album`, introduced
   in FPA-P07-S02; Albums remain independent, directly reachable
   resources regardless of Event membership.
5. **Guest participation** — `Album.guest_participation`
   (`none`/`view`/`contribute`), shared by an Event's entire guest
   cohort; `view` includes original download; additive to, never a
   replacement for, individual `AlbumGrant`.
6. **Guest admission — acceptance mechanics** — Event-invitation
   acceptance always resolves membership deterministically (no membership
   → create Guest; removed → reactivate per ADR-0005 §5; active → reuse
   unchanged, never rejected, never demoted) before establishing the
   `event_admissions` record for that invitation's Event; two Event
   invitations for the same email may legitimately coexist and both
   succeed on acceptance regardless of order. A parallel direct-admission
   entry point lets Owner/Administrator admit an existing active
   membership to a further Event without an invitation. Neither path ever
   creates a second membership or alters an existing role.
7. **Event admission — state and lifecycle** — `event_admissions`
   (`event_id`, `family_space_membership_id`, `admitted_at`, nullable
   `revoked_at`/`revoked_by`), unique per `(event_id,
   family_space_membership_id)`; validity is `not revoked AND now() <
   admitted_at + current configured lifetime`, computed live, never
   snapshotted or swept. Owner/Administrator may admit, revoke, or
   re-admit (idempotent, audited, reusing the same row and clearing prior
   revocation); a Guest may never alter their own admission.
8. **Security membership vs. family membership** — `family_space_membership`
   is an authenticated security relationship, not genealogical or social
   family membership; the ADR fixes an explicit, durable invariant that
   **Guest memberships must never be presented as ordinary family
   members**, so they are excluded from ordinary "Family Members"
   surfaces by construction, across every current and future
   membership-facing surface, not only Photo/Album. A Guest membership
   grants no access on its own — only the composition of a valid
   `EventAdmission` and Album `guest_participation` or an Event-scoped
   `AlbumGrant` does. Dormant Guest memberships are never automatically
   cleaned up or archived. Identity remains stable while trust changes
   explicitly: promotion to Member is Owner/Administrator-controlled,
   never automatic, never inferred from activity, and changes only the
   existing membership's role — never a new identity.
9. **Guest boundary** — Guests never receive default Family-Space-wide
   access and reach Albums only through an admitted Event's
   `guest_participation` or an Event-scoped individual `AlbumGrant`; this
   is a real backend authorization boundary, proven by bypass regression
   tests, not a frontend routing convenience.
10. **Original download** — narrowly, deliberately superseded from
    ADR-0007 §16/ADR-0008 §10's Guest-never baseline: a currently-admitted
    Event guest may download originals from Albums with
    `guest_participation` of `view` or `contribute` (download is part of
    `view`, not a separate tier), for only as long as their admission
    remains valid; no other Guest or Contributor access path gains
    original download.
11. **Attendance** — derived from confirmed `PhotoPerson` associations
    within an Event's Photos, in both directions (Event → People, Person →
    Events); no maintained `EventAttendee` entity for any role; explicitly
    distinct from, and not a substitute for, `event_admissions`, for every
    role, not only Guest.
12. **Primary Event** — an explicit, directly-set `Photo.primary_event_id`,
    introduced in FPA-P07-S02, with no authorization consequence, never
    derived from Album membership; settable by Owner/Administrator or the
    Photo's creator (while they retain ordinary Photo edit authority),
    following ADR-0008's existing creator-editable-field pattern.
13. **Media contribution** — Member/Contributor Event-Album contribution
    reuses ADR-0008 §8 unchanged; Guest media contribution requires
    `guest_participation` of `contribute` or an individual
    `AlbumGrant.can_contribute`; bulk upload reuses Phase 5's existing
    per-file, target-album mechanism.
14. **Guest conversation vs. Story authority** — a Guest at `view` or
    `contribute` may comment and react; `PhotoStory` creation is not
    granted by Event participation at any level and remains governed by
    ADR-0008's existing trusted-family/Contributor rule, unchanged.
15. **Delivery** — Phase 6 already correctly denies Guest `AlbumGrant`
    access with no Event involved; `FPA-P07-S04` adds one narrow,
    additive, Event-scoped exception to that already-correct baseline —
    it does not "fix a gap," and must not loosen the underlying denial.
16. **Event lifecycle** — no hard deletion; reversible soft deletion by
    Owner/Administrator, which disables Guest access immediately and
    retains every reference to the Event (`Album.event_id`,
    `Photo.primary_event_id`, `Invitation.event_id`, `event_admissions`)
    unchanged; Albums, Photos and Phase 5 media are never affected;
    Family Space teardown remains the sole destructive path and is
    extended to include Phase 7's own rows.
17. **Duplicate Events** — never auto-merged; Owner/Administrator manually
    re-parent Albums and `primary_event_id` references, then soft-delete
    the duplicate (§16); a deterministic, documented, advisory-only
    candidate-selection heuristic is required before implementation, but
    its exact signals are implementation-guide detail; no Person-merge-
    style tombstone/reversal ledger.
18. **Non-goals** — anonymous account-free contribution, a maintained
    attendee/RSVP entity for any role, per-guest Album permissions as a
    first-class concept, automatic Guest-membership cleanup, automatic
    Guest-to-Member promotion, an Event visibility enum, Event hard
    deletion, and a scheduled admission-expiry/cleanup process are all
    explicitly not built in Phase 7.
