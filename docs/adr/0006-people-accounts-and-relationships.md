# ADR-0006: People, Accounts and Relationships

- Status: Accepted
- Date: 2026-08-05
- Decision owners: David
- Related stages: FPA-P04-S01 (accepting this ADR completes that stage), implemented by FPA-P04-S02, FPA-P04-S03, FPA-P04-S04, FPA-P04-S05

## Context

Phase 3 established the Family Space as the tenant boundary: ULID-identified `family_spaces` and `family_space_memberships`, five roles with a membership-administration baseline meaning, a proven three-class RLS treatment (tenant registry, access-control table, ordinary tenant-owned content tables), a `TenantOperationContext` envelope for asynchronous work, a generic `AuditEvent` model, and an Owner-gated deletion lifecycle. ADR-0001 fixed Laravel as the sole Postgres writer. ADR-0004 authenticated a `User` but explicitly declined to model anything richer, stating directly that "a `User` is not a `Person`" and that the richer model was reserved for this phase.

`PRODUCT_VISION.md` requires that Fambam represent real people — including children, deceased relatives, non-technical relatives, family friends, and people known only from old photographs — independently of whether they can or ever will hold a login. It is equally explicit that relationships describe family structure and must never substitute for permissions, and that "family circles" are a looser, presentation-only grouping distinct from the Family Space security boundary.

This ADR decides the Person identity model, the User-to-Person link, the relationship model, Family Circles, a Phase 4 privacy/role baseline, an uncertain-date semantic contract, and the merge/correction model — all as ordinary tenant-owned data inheriting ADR-0005's guarantees. It deliberately does not decide anything about photographs, face recognition, or search (Phases 6, 9, 10, 11), and it does not give Contributor or Guest a resource-level grant model — ADR-0005 itself named that as a Phase 5/6/7 decision, not this one. Where Phase 4 must nonetheless say something about Contributor/Guest visibility now, that is recorded below as an explicit, named placeholder, not a preview of that later design.

## Decision

### 1. Person identity is Family-Space-local

There is no global `Person` identity shared across Family Spaces. The same real human represented in two Family Spaces is represented by two independent `Person` records, with no identity bridge, shared key, or cross-reference between them. `PRODUCT_VISION.md`'s statement that a person may "belong to several family branches" is interpreted as relationships and/or Family Circles *within* one Family Space (§9), not as license for a cross-tenant `Person` record.

No automatic or manual cross-Family-Space Person matching is introduced in Phase 4. This follows directly from ADR-0005: a shared identity row or matching mechanism across tenants would itself be a cross-tenant channel, undermining the isolation ADR-0005 exists to guarantee.

### 2. Person identity and lifecycle

A `Person` has an immutable ULID identity and mutable archival/presentation data. Phase 4 supports, at the architectural level:

- a preferred/display name;
- former, alternate or maiden names;
- birth information where known, using the uncertain-date concept (§3);
- an independent deceased-state fact (`is_deceased`), which may be true or false regardless of whether a death date is known — see §3 for how this composes with date uncertainty rather than being absorbed into it;
- death information where known, using the same uncertain-date concept as birth information;
- bounded biography or notes;
- provenance and audit of meaningful changes (§15).

**Biography and notes visibility.** Biography and notes are ordinary shared archival content, not private administrative or staff notes. They are visible wherever the Person record itself is visible, under §10's whole-record visibility model — Phase 4 introduces no separate, more restrictive visibility tier for this field, and no field-level privacy engine. Anything that should not be visible to an ordinary Member with Person-directory access must not be stored in the biography/notes field; Phase 4 has no private-notes destination for that purpose.

No generic key/value attributes bag and no broad genealogy schema (structured birthplace, occupation, and similar) are introduced — these are not required by any Phase 4 exit criterion, and an open attributes bag would trade discipline for flexibility no requirement currently demands.

Person records are not hard-deleted through ordinary application flows, mirroring the soft-removal precedent already established for `family_space_memberships`.

### 3. Uncertain historical dates — semantic contract only

A single uncertain-date concept is adopted for both birth and death information, because Phase 6 will need the same semantics for photograph dates and defining it twice would be needless duplication. The concept must support:

- an exact date;
- month and year, without a day;
- year only;
- a decade;
- an approximate date;
- an unknown date.

"Deceased, date unknown" is **not** a seventh precision level of this concept. It is the composition of two independent facts: the Person's `is_deceased` state (§2) being true, and the death-date value itself using this concept's "unknown" precision. Keeping deceased state and date-uncertainty independent — rather than folding lifecycle state into the date primitive — preserves §2's requirement that deceased status and death-date knowledge vary independently, and keeps the uncertain-date concept a pure date-precision primitive that Phase 6 can reuse unmodified for photograph dates, without inheriting Person-specific lifecycle semantics.

This ADR fixes the *semantics* the concept must express — not its physical representation. No JSON shape, column layout, or storage encoding is frozen here; the implementation guide defines the concrete representation, and it must support sensible validation, display, chronological ordering, and querying. Reusing the same semantic contract in Phase 6 does not require a superseding ADR unless Phase 6 needs to change what the concept *means*, not merely how photographs use it.

### 4. Proposed versus authoritative information

Because a `Person` record may represent an active family member, a deceased relative, a child, someone with no account, a family friend, a neighbour, an event guest, someone identified from an old photograph, or someone whose identity is genuinely uncertain or disputed, ordinary Family Space membership must not carry blanket authority to overwrite any Person's identity — and, since relationships are themselves identity-bearing claims about how one Person relates to another (§7), the same reasoning extends to them. Phase 4 draws one durable line, applied uniformly to Person details and to relationships:

- **Contributing or proposing** identity-bearing information is reachable by Member.
- **Confirming, replacing, or resolving as authoritative** is Owner/Administrator-only.

For Person details specifically, a Member may create a provisional Person record; propose a name, alternate name, or correction; add contextual or biographical notes with provenance; suggest birth or death information; suggest that two Person records may be duplicates; and flag an identification as uncertain or disputed. A Member must not replace a confirmed preferred identity; resolve conflicting names or identity claims; confirm disputed birth or death information; mark or unmark a Person as deceased; approve a User-to-Person link; or merge or reverse Person identities. Owner and Administrator may review, approve, reject, correct, or directly perform authoritative identity changes. §7 states the equivalent application of this same pattern to relationships in full, rather than duplicating it here.

This ADR fixes the *architectural distinction* between proposed and authoritative information, not a moderation workflow. A minimal status model (for example, provisional / confirmed / disputed) may be used to express this distinction for both Person details and relationships, but its exact schema and transition mechanics belong to the implementation guide. No general-purpose review/assignment/SLA workflow engine is introduced — the distinction is about *who may write which class of identity-bearing fact*, not a queue to manage.

### 5. User-to-Person cardinality

Within one Family Space:

- one `User` may link to at most one `Person`;
- one `Person` may link to at most one `User`;
- the same `User` may link to different, independent `Person` records in different Family Spaces, consistent with §1's Family-Space-local identity;
- `User` and `Person` remain separate entities at all times — linkage does not confer ownership of the `Person` record to the linked `User`.

### 6. Link authority and self-claiming

- Owner and Administrator may establish, approve, correct, or remove a User-to-Person link.
- A Member may propose that an existing, unlinked `Person` is them.
- A Contributor may also propose a self-claim, where they can legitimately reach the claim flow — this is a permission ceiling on who *may* propose, not a commitment to a dedicated Contributor-facing entry point in Phase 4.
- A Guest may not self-claim in Phase 4.
- A proposed claim has no effect until Owner or Administrator approval.
- Every proposal, approval, rejection, unlink, and correction action is audited (§15).

Unrestricted self-claiming is rejected: without an approval step, any member could claim to be an existing historical `Person` record — including one representing another living relative — which is exactly the impersonation risk this section exists to prevent.

### 7. Relationship model

One canonical, directed or symmetric relationship edge is stored per relationship concept; inverse wording is derived at read time and never stored as a second, independently-writable row. Storing both directions of one inverse pair as separately maintainable types is rejected outright — it doubles the write and audit surface and creates a correction hazard when the two rows drift apart.

The V1 relationship vocabulary is centrally defined and additive, not user-configurable. The accepted starting vocabulary includes: `parent_of`, `partner_of`, `sibling_of`, `guardian_of`, `step_parent_of`, and `close_family_friend_of`. The exact final set of concepts and their derived inverse wording is implementation-guide detail; the architectural commitment here is the *mechanism* — a fixed, centrally-defined, additive-only vocabulary — not a promise that this exact list is final.

Symmetric concepts (`partner_of`, `sibling_of`, `close_family_friend_of`) are stored as one edge with symmetric interpretation from either person's perspective — no mirrored row. Directed concepts (`parent_of`, `guardian_of`, `step_parent_of`) are stored in one direction; their inverse (child-of, ward-of, step-child-of) is derived wording only. A direct `grandparent_of` concept may exist for cases where the intermediate generation is unknown or not modelled, but `grandchild_of` is not stored as an independent inverse type — it is derived wording of the same stored edge, viewed from the other person.

**Relationships are identity-bearing archival claims, not incidental metadata.** A Member directly stating that one Person is another's parent, partner, guardian, or sibling — or removing such a claim — can alter the archive's representation of a third party as materially as replacing a confirmed name, birth date, or deceased state, and frequently about people who cannot review or dispute the change themselves: deceased relatives, children, people with no account, people identified only from old photographs. Phase 4 therefore applies the same proposed-versus-authoritative pattern established in §4 to relationships specifically:

- A Member may propose a new relationship; propose a correction to an existing relationship; flag an existing relationship as incorrect, uncertain, or disputed; and supply contextual information supporting the proposal.
- A Member must not directly confirm a proposed relationship as authoritative; replace an authoritative relationship; remove an authoritative relationship; or resolve conflicting relationship claims.
- Owner and Administrator may create an authoritative relationship directly; approve or reject a relationship proposal; replace or remove an authoritative relationship; and resolve conflicting or disputed relationship claims.

This reflects the expected family dynamic: Owners and Administrators are generally the elders or trusted archive custodians best placed to confirm family relationships, while Members remain able to contribute knowledge and corrections. As with Person details (§4), this reuses the same minimal proposed/authoritative pattern rather than introducing a separate relationship-specific moderation engine; the exact proposal/status schema and transition mechanics are implementation-guide concerns.

Relationships never grant or imply permission. This restates `PRODUCT_VISION.md`'s own principle as an enforced architectural invariant for Phase 4: no policy, query, or authorization decision may consult relationship data — proposed or authoritative — as a basis for access.

### 8. Relationship validation

Structural validation applies whenever a relationship is proposed, and again — independently — whenever it is approved or created directly as authoritative. Approval is not a blind promotion of whatever was true at proposal time: an approving Owner or Administrator's action must re-validate the proposal against the *current* state of the Person records and their existing relationships, since intervening changes may have made a formerly-valid proposal invalid or contradictory. A proposed relationship does not participate in authoritative inverse-wording rendering or any relationship-graph traversal until it is approved; a disputed or rejected proposal never becomes an active relationship edge.

Both proposal-time and approval-time validation reject the same cheap, direct contradictions: self-relationships (a Person related to themselves); direct inverse parent cycles (A parent-of B and B parent-of A simultaneously); incompatible duplicate edges where determinable; and structurally impossible same-pair combinations (for example, the same pair simultaneously stored as both `parent_of` and `partner_of` in a way the vocabulary defines as incompatible).

Full transitive genealogy validation and deep ancestry-cycle analysis remain explicitly deferred. Neither is required by any stated Phase 4 exit criterion, and both are real graph-algorithm scope disproportionate to a first family archive.

### 9. Family Circles

Family Circles are implemented as a real but minimal presentation/grouping construct:

- scoped to exactly one Family Space;
- contain People, not Users and not Family Space memberships;
- flat in V1 — no nested circles;
- suited to freeform groupings that cannot be derived from genealogy or relationship data (for example, "Close Family Friends" or "Wedding Group");
- never consulted by authorization policies, RLS, membership checks, or any resource-access decision.

Family Circles are not tenants, not roles, and not permission groups. The last guarantee is the durable guardrail this section exists to state: a Circle must never become an authorization boundary "just this once."

### 10. Privacy and role visibility baseline

For Phase 4: Owner, Administrator, and Member may list and view Person records in their Family Space by default. Contributor and Guest receive no default Person-directory visibility. Later resource-specific grants (Phase 5, 6, or 7) may expose relevant People context to Contributor or Guest without granting unrestricted directory access — this is named explicitly as the Phase 4 placeholder those later phases must revisit, in the same spirit that ADR-0004's `can_invite` was named as a disposable stand-in for Phase 3's role model.

No field-level privacy engine is introduced in Phase 4. Person visibility is a whole-record authorization decision for V1, and this applies uniformly — including to biography/notes content (§2). Phase 4 introduces no separate, more restrictive visibility tier for any subset of Person fields. Role-level visibility is enforced through application authorization policies, consistent with how `FamilySpacePolicy` already checks role via `TenantContext`; ADR-0005's ordinary tenant-table RLS continues to enforce tenant isolation only, and does not become a second, database-level role engine.

### 11. Tenancy inheritance

`Person`, User-to-Person link, relationship, Family Circle, and circle-membership data are ordinary tenant-owned content tables under ADR-0005 §9.C. Each requires: explicit Family Space ownership; application query scoping at the call site; policies; both read and write RLS isolation; and fail-closed behaviour when tenant context is missing. No cross-Family-Space identity matching of any kind is permitted (restating §1 as a tenancy guarantee, not only a product decision), and no client-supplied Family Space identifier is trusted outside ADR-0005's accepted tenant-resolution flow. Frontend query keys remain tenant-aware, following the existing `features/family-spaces` precedent.

No executable RLS SQL is specified here, for the same reason ADR-0005 declined to freeze its own policy implementation: the trust boundary and required guarantees are the durable decision, and the concrete PostgreSQL mechanism belongs to the implementation guide and migrations.

### 12. Merge, tombstone and reversal

Person merge is in scope for Phase 4, as already committed by `PROJECT_ROADMAP.md` and `tasks.json` (`FPA-P04-S05`). The durable model is:

- one Person is selected as survivor;
- the absorbed Person is not hard-deleted;
- a redirect/tombstone record captures that the absorbed identity resolves to the survivor;
- Phase-4-owned relationships, circle memberships, and User-to-Person links are reconciled atomically as part of the merge;
- duplicate or conflicting relationships that the merge would otherwise produce are resolved deliberately, not blindly duplicated;
- the merge produces structured provenance and audit records — the structured provenance is the authoritative ledger for reversal, distinct from (in addition to) ordinary `AuditEvent` rows;
- every future Person-referencing domain (photographs, face-recognition assignments, search indices, and similar) must explicitly integrate with merge behaviour when it is introduced; this ADR cannot and does not guarantee that integration on a future phase's behalf.

Reversal is supported for the structures Phase 4 itself owns, where the original assignments can be restored unambiguously from the structured merge provenance — not assumed recoverable from an ordinary `AuditEvent` log alone. Automatic reversal may be refused where subsequent changes make safe separation ambiguous (for example, new relationships added to the surviving record after the merge that cannot be unambiguously attributed back to one side); in that case the system falls back to an explicit manual correction workflow rather than guessing. This ADR does not promise perfect automatic reversal of arbitrary future state, only of what Phase 4 itself can still account for.

### 13. Routes

- `/families/{familySlug}/people`
- `/families/{familySlug}/people/{personUlid}`

The Family Space keeps its existing friendly, mutable slug (ADR-0005 precedent). Person detail identity uses the immutable ULID, because display names are mutable and duplicate names are normal within one family (a grandfather and grandson sharing a name, for example). No Person slug is required in Phase 4.

### 14. Authorization baseline

| Action | Owner | Administrator | Member | Contributor | Guest |
|---|---:|---:|---:|---:|---:|
| List/view People | yes | yes | yes | no default | no |
| Create a provisional Person | yes | yes | yes | no | no |
| Contribute or propose ordinary Person details | yes | yes | yes | no | no |
| Confirm or replace authoritative identity details | yes | yes | no | no | no |
| Mark or unmark deceased | yes | yes | no | no | no |
| Contribute or propose relationships | yes | yes | yes | no | no |
| Confirm, replace or remove authoritative relationships | yes | yes | no | no | no |
| Manage Family Circles | yes | yes | yes | no | no |
| Propose self-claim | yes | yes | yes | yes, where reachable | no |
| Approve/create User-to-Person link | yes | yes | no | no | no |
| Unlink/correct User-to-Person link | yes | yes | no | no | no |
| Propose duplicate or identity correction | yes | yes | yes | no | no |
| Merge/reverse duplicate People | yes | yes | no | no | no |
| View administrative audit history | yes | yes | no | no | no |

Member-level contribution is intentionally collaborative, matching the product's family-archive spirit; authoritative identity changes, authoritative relationship confirmation, and other harder-to-reverse identity operations stay Owner/Administrator-only. Contributor and Guest's "no default" rows remain the placeholder named in §10, explicitly deferred to Phase 5/6/7's resource-grant model rather than a finished design.

### 15. Audit and provenance

At minimum, `AuditEvent` records:

- Person created;
- Person detail proposed;
- Person detail proposal approved or rejected;
- authoritative Person identity changed;
- deceased state or death information changed;
- relationship proposed;
- relationship proposal approved;
- relationship proposal rejected;
- relationship marked disputed;
- authoritative relationship created directly;
- authoritative relationship replaced;
- authoritative relationship removed;
- Family Circle created/changed/removed;
- Person added to or removed from a Circle;
- User-to-Person claim proposed/approved/rejected;
- link created/removed/corrected;
- duplicate proposed;
- Person merged;
- merge reversed or manual correction required.

No full field-version history is introduced in Phase 4. Ordinary `AuditEvent` rows are sufficient for this action provenance, including the relationship proposal/approval/rejection/dispute events above; structured merge provenance (§12) is a separate, additional mechanism specifically for safe reversal, not a general moderation or field-history replacement.

### 16. Access loss, revocation and deletion lifecycle

A User-to-Person link is archival identity metadata, not an access grant. Therefore:

- account revocation does not remove or clear the link;
- Family Space membership removal does not remove or clear the link;
- loss of access is enforced entirely through User/account state and membership state, never by mutating the Person link;
- the Person record remains intact regardless of the linked User's access state;
- the identity association is retained for provenance and potential later reactivation;
- only an explicit unlink/correction operation removes or changes the link, and that operation is Owner/Administrator-only and audited.

A revoked account or a removed membership gains no access merely because a historical identity link remains — this is a non-bypass guarantee, not an incidental consequence.

When a containing Family Space enters ADR-0005's deletion lifecycle, Phase 4 records (Person, link, relationship, circle, circle-membership) participate in that same teardown — there is no independent Person deletion path outside it.

Marking a Person deceased is a domain-state change, not a deletion event.

## Alternatives considered

- **Global/shared Person identity across Family Spaces** — rejected: creates a cross-tenant identity bridge that directly contradicts ADR-0005's isolation guarantees.
- **Automatic or manual cross-family Person matching in Phase 4** — rejected: no demonstrated need at this data volume, and it is a duplicate-detection feature more sensibly scoped alongside Phases 9/10 once real matching signal (face recognition) exists.
- **A generic key/value attributes bag for Person** — rejected as premature flexibility; add fields when a concrete need appears.
- **A broad genealogy schema** (structured birthplace, occupation, and similar) — rejected: not required by `PRODUCT_VISION.md` or the roadmap's Phase 4 exit criteria.
- **Allowing ordinary Member-level membership to overwrite authoritative Person identity unilaterally** — rejected: undermines the archive-trust the product exists to build, and directly conflicts with the vision's emphasis on human-confirmed knowledge.
- **Allowing Members to directly create, edit, or remove authoritative relationships** — rejected: relationships are identity-bearing claims about third parties, often people who cannot review or dispute a change themselves (deceased relatives, children, people with no account); unrestricted Member authority here carries the same risk unrestricted Member authority over confirmed Person identity would.
- **A large moderation/workflow engine** (multi-stage review states, assignment, SLAs) — rejected as disproportionate; a minimal proposed/authoritative distinction is sufficient for Phase 4's actual need.
- **A separate, relationship-specific moderation mechanism distinct from the Person proposed/authoritative pattern** — rejected: the same minimal pattern already established for Person details applies to relationships without modification; a second bespoke mechanism would be needless duplication.
- **Many-to-many User-to-Person linking** — rejected: reopens the account-ownership-of-identity conflation this ADR exists to prevent.
- **Unrestricted self-claiming** — rejected: enables impersonation of another family member's historical Person record.
- **Storing both directions of an inverse relationship pair as independently storable types** — rejected: doubles write/audit surface and creates a divergence hazard between the two rows.
- **User-configurable relationship types in V1** — rejected: no demonstrated need, adds validation and UI complexity without product benefit.
- **Deep transitive/ancestry-cycle validation in Phase 4** — rejected: real graph-algorithm scope with no stated Phase 4 requirement.
- **Deriving Family Circles purely from the relationship graph, with no table** — rejected: cannot represent freeform, non-genealogical groupings the product vision explicitly names.
- **Nested/hierarchical Family Circles** — rejected for V1: adds complexity with no stated requirement.
- **Granting Contributor/Guest default Person-directory visibility** — rejected: inconsistent with their explicitly limited baseline meaning already fixed by ADR-0005.
- **A field-level privacy engine in Phase 4** — rejected: no demonstrated requirement; whole-record visibility is sufficient for V1.
- **Treating biography/notes as private administrative content, or introducing a separate visibility tier for them** — rejected: Phase 4 has one whole-record Person visibility model (§10); a field-specific visibility tier for notes would itself be exactly the field-level privacy engine this ADR declines to build.
- **Enforcing role-based Person visibility inside PostgreSQL RLS** — rejected: keeps RLS scoped to tenant isolation only, consistent with ADR-0005's own separation of registry/access-control/content concerns, and keeps role logic in one place (application policies).
- **Treating "deceased, date unknown" as a seventh precision level of the uncertain-date concept** — rejected: conflates a Person lifecycle fact with a date-precision primitive meant to be reused unmodified by Phase 6 photograph dates; keeping `is_deceased` and date-uncertainty independent preserves both the existing decision that deceased state doesn't depend on death-date knowledge and the date concept's reusability.
- **Freezing the uncertain-date representation in this ADR** — rejected, for the same reason ADR-0005 declined to freeze its own RLS SQL: it would make a later equivalent implementation change look like an architectural change when it isn't one.
- **In-place merge with no tombstone** — rejected: gives future Person-referencing tables no way to discover or resolve a stale reference.
- **Guaranteeing full automatic reversal of merge under all future conditions** — rejected as an unrealistic promise once later phases add their own Person references this ADR cannot anticipate.
- **A Person slug in Phase 4 routes** — rejected: duplicate display names within one family are normal and expected, making a display-derived identifier a durability hazard.
- **Clearing or nulling the User-to-Person link on account revocation or membership removal** — rejected: would erase archival provenance and directly contradicts the product's stated principle that a person does not disappear because their account can no longer log in.

## Consequences

### Positive

- Person, link, relationship, and circle authority each match their actual risk profile, rather than one uniform membership-role check applied everywhere.
- The proposed/authoritative distinction protects the archive's trustworthiness for both Person identity and relationships without requiring a general moderation subsystem.
- Relationships receive the same proposed/authoritative protection as Person identity, closing a gap where a Member could otherwise alter the archive's record of a third party's family structure unilaterally.
- The merge tombstone gives every future Person-referencing table (photos, face clusters, search) an explicit mechanism to resolve a stale reference, instead of leaving that problem for whichever later phase discovers it first.
- Reusing ADR-0005's Class C tenant-table pattern, `AuditEvent` model, and `TenantOperationContext` means Phase 4 introduces no new tenancy or audit infrastructure — only new tables and policies following an already-proven shape.
- The uncertain-date concept is named once, ahead of Phase 6 needing the same semantics, without freezing its representation prematurely, and without absorbing Person-specific lifecycle state into a primitive Phase 6 must reuse unmodified.

### Negative

- Two additional authority tiers (contribute vs. confirm-authoritative; propose-link vs. approve-link) — now extended to a third area, relationships — add conceptual surface beyond a flat "any Member can edit" model.
- The merge/tombstone mechanism is real, ongoing structure that every future Person-referencing table must remember to consult — a maintenance obligation, not a one-time cost.
- Contributor/Guest's "no default visibility" is an explicit placeholder that Phase 5/6/7 must deliberately revisit, not a finished resource-permission design.
- Approval-time re-validation (§8) means an Owner or Administrator's approval action can fail, or require a fresh look, if intervening changes made a formerly-valid proposal contradictory — a small extra step compared to a simpler "trust the proposal as submitted" model.

### Risks

- If a future contributor adds a new Person-referencing table without integrating it with the merge redirect, a stale reference to an absorbed Person could silently persist.
- If Family Circle membership is ever consulted by a policy, query, or RLS check "just this once," it becomes an undocumented second authorization boundary — exactly what §9 forbids.
- If the proposed/authoritative distinction's minimal status model is implemented inconsistently across Person fields, relationships, and links, a reviewer may encounter several slightly different mini-workflows instead of one shared pattern — worth a consistency check during implementation.
- If approval-time re-validation (§8) is implemented as a rubber-stamp promotion of the proposal's originally-submitted state rather than a fresh check against current data, a stale or now-contradictory relationship could be silently confirmed as authoritative.
- If the biography/notes field is used to store information that shouldn't be visible to ordinary Members, no Phase 4 mechanism prevents that misuse — the whole-record model relies on correct usage, not a technical barrier, exactly as §2's clarification states.
- If Phase 6 needs uncertain-date behaviour beyond what Phase 4 anticipated, extending the shared concept may also touch Phase 4's own storage.

## Implementation notes

- **FPA-P04-S02** implements §2 (Person fields, including the biography/notes visibility clarification), §3 (uncertain-date concept and its independence from deceased state), §13 (ULID route), the Class C tenant pattern for the `Person` table, and the Person-created/updated/deceased-changed audit events.
- **FPA-P04-S03** implements §5 (cardinality) and §6 (link authority/self-claim), including the link table's Class C tenant pattern and its audit events.
- **FPA-P04-S04** implements §7 (relationship model, including the proposed/authoritative distinction and its Member/Owner/Administrator authority split), §8 (proposal-time and approval-time validation), and §9 (Family Circles), including their audit events (§15).
- **FPA-P04-S05** implements §12 (merge, tombstone, reversal) and its structured provenance and audit events.
- §4 (proposed versus authoritative) is cross-cutting: it applies to Person details, implemented incrementally alongside S02–S03 wherever a proposable field or action is introduced, and to relationships, implemented in S04 — not as its own separate stage in either case.
- Exact schemas and columns, exact proposal/status mechanics, exact enum values and PHP/TypeScript representations, exact date-storage representation, exact transaction and locking mechanics, exact merge-provenance schema, and exact migrations, routes, queries, and test names all belong to `docs/IMPLEMENTATION_GUIDE.md`, not this ADR.

## Review triggers

- When Phase 5/6/7 are scoped: give Contributor and Guest their first concrete resource-level Person visibility, superseding §10's placeholder — mirroring ADR-0005's own review trigger for the same two roles.
- When Phase 6 is scoped: confirm the uncertain-date concept's semantics (§3) still fit photograph dates, or whether an extension is needed, and whether that extension also touches Phase 4's own storage.
- When Phase 9/10 are scoped: confirm the merge/tombstone mechanism (§12) is integrated by any new Person-referencing table those phases introduce.
- When Phase 11 is scoped: confirm search does not bypass §10's visibility defaults or resurface absorbed/tombstoned Person identities as live results.
- If real usage shows the proposed/authoritative distinction (§4, §7) is too coarse (for example, Members wanting to propose deceased-status changes, or wanting to directly confirm low-stakes relationships) or too restrictive, revisit deliberately rather than quietly loosening Owner/Administrator-only actions.
- If a future need for a second freeform grouping concept emerges, revisit whether Family Circles should generalize rather than adding a parallel construct.

## Deferred concerns

- `PhotoPerson` and any "appears in N photographs" surface.
- Face-recognition identity assignment.
- Automated duplicate suggestions beyond a Member's manual "these may be duplicates" flag.
- Search by Person.
- Family-tree visualization.
- A broad field-level privacy engine.
- Configurable (non-fixed) relationship types.
- Complete historical field-version history.
- Full Contributor/Guest resource grants.

## Resolved decisions

1. **Person identity scope** — Family-Space-local; no global identity, no cross-tenant matching.
2. **Person fields** — immutable ULID plus mutable names, uncertain birth/death information, an independent deceased-state fact, and bounded shared-archival biography/notes visible wherever the Person record is visible, with no private-notes tier; no attributes bag, no broad genealogy schema.
3. **Uncertain dates** — one reusable semantic concept for birth/death information, expressed independently of Person lifecycle state; "deceased, date unknown" is the composition of `is_deceased = true` and an unknown death-date value, not a seventh precision level; anticipated reuse (not design) for Phase 6; representation deferred to implementation.
4. **Proposed vs. authoritative** — a single architectural pattern applied uniformly to Person details and relationships: Member may propose; Owner/Administrator confirm authoritative facts; no general moderation engine.
5. **User-to-Person cardinality** — one-to-one per Family Space; independent per-space links for the same User.
6. **Link authority** — Owner/Administrator approve; Member and reachable Contributor may propose self-claim; Guest may not; all actions audited.
7. **Relationships** — one canonical edge per concept with derived inverse wording. Members may contribute or propose relationships; Owners and Administrators confirm, replace or remove authoritative relationships. Proposed relationships are structurally validated but do not become active relationship edges until approved. Relationships never grant permissions.
8. **Relationship validation** — cheap structural checks applied at both proposal and approval time, with approval re-validating against current state rather than blindly promoting the original proposal; transitive genealogy validation deferred.
9. **Family Circles** — real, minimal, People-only, flat, presentation-only; never an authorization input.
10. **Visibility baseline** — Owner/Administrator/Member see People, including biography/notes, by default; Contributor/Guest do not, pending later resource grants; whole-record only, enforced at the policy layer.
11. **Tenancy** — Person/link/relationship/circle tables are ordinary Class C tenant-owned tables under ADR-0005; no RLS SQL frozen here.
12. **Merge** — tombstone/redirect model; atomic reconciliation of Phase-4-owned references; realistic, not unlimited, reversal.
13. **Routes** — ULID-identified Person detail route; no Person slug in Phase 4.
14. **Authorization** — the table in §14, splitting relationship authority into contribute/propose (Member) versus confirm/replace/remove authoritative (Owner/Administrator), with Contributor/Guest explicitly placeholder rows for Person visibility.
15. **Audit** — ordinary `AuditEvent` coverage including distinct relationship proposal/approval/rejection/dispute and direct-authoritative-create/replace/remove events, plus separate structured merge provenance; no full field-version history.
16. **Lifecycle** — link is archival metadata, never cleared by revocation or membership removal; Person participates in Family Space teardown only; deceased is a state change, not deletion.
