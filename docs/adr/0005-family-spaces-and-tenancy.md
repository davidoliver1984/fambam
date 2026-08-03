# ADR-0005: Family Spaces and Tenancy

- Status: Accepted
- Date: 2026-08-03
- Decision owners: David
- Related stages: FPA-P03-S01 (accepting this ADR completes that stage),
  implemented by FPA-P03-S02, FPA-P03-S03, FPA-P03-S04, FPA-P03-S05

## Context

Phase 2 established who can authenticate. Phase 3 has to establish the
boundary everything else in this product is scoped to: `PROJECT_ROADMAP.md`
calls the family space "the primary collaboration, ownership and security
boundary," and `PRODUCT_VISION.md` is explicit that a user may belong to
several family spaces, that family spaces (not the looser, presentation-only
"family circles") are the security boundary, and that relationships must
never substitute for permissions.

Three things from Phase 2 are directly load-bearing here. ADR-0001 fixed
Laravel as the sole Postgres writer and the Python image-analysis service as
a stateless, message-only consumer with no database access of any kind.
ADR-0004 built the database session store specifically so a user's access
could be revoked by user id, and its own Review triggers say Phase 3 is
expected to replace `users.can_invite` with a real role check. And
`CONTRIBUTING.md`'s Laravel style section already requires that "Query
classes must apply family-space scope explicitly."

This ADR decides the tenant model, roles, the friendly-URL-to-tenant
resolution chain, the defense-in-depth stack including PostgreSQL RLS, async
propagation of tenant context, audit coverage, and the deletion lifecycle. It
does not decide anything about photos, albums, events, or people/relationships
— those are Phases 4–7 and get their own ADRs.

**Scope of this record:** ADR-0005 records the durable tenancy architecture,
security boundaries and required guarantees. Equivalent implementation
changes that preserve those guarantees — including changes to PostgreSQL
policy composition or resolver mechanics — do not require a superseding ADR
unless they alter the trust model, enforcement layers, or observable
authorization behaviour. Exact policy definitions, migration ordering,
session-context setup, and resolver implementation belong in the
implementation documentation and the migrations themselves, not here.

## Decision

### 1. Tenant tables: `family_spaces` and `family_space_memberships`

```
family_spaces
├── id (ulid, primary key)
├── slug (string, unique, mutable)
├── name (string)
├── status (enum: active | deletion_requested | deleting | deleted)
├── deletion_requested_at (nullable timestamp)
├── deletion_requested_by (nullable, FK users)
├── scheduled_deletion_at (nullable timestamp)
├── created_at / updated_at

family_space_memberships
├── id (ulid, primary key)
├── family_space_id (FK family_spaces)
├── user_id (FK users)
├── role (enum: owner | administrator | member | contributor | guest)
├── state (enum: active | removed)
├── invitation_id (nullable, FK invitations — how this membership was created or last reactivated)
├── removed_at / removed_by (nullable)
├── created_at / updated_at
└── unique (family_space_id, user_id)
```

**`family_spaces` has no `family_space_id` column — it is the tenant, not a
tenant-owned row.** `family_space_memberships` is the join between users and
tenants, and is what tenant *resolution itself* depends on. Both tables
require security treatment distinct from ordinary tenant-owned content
tables — see §9.

**Membership rows are soft-removed, not deleted**, and the `unique
(family_space_id, user_id)` constraint means a user can have at most one
membership row per space, ever — active or removed. §5 defines the rejoin
principle this implies.

### 2. Identity: ULID primary key, slug for presentation only — settled

`family_spaces.id` and `family_space_memberships.id` are ULIDs. `slug` is a
separate, unique, **mutable** column on `family_spaces` used only in URLs.

### 3. Roles: all five, with a Phase 3 baseline meaning

**Baseline Phase 3 meaning** (membership-administration authority and
default security posture only — not resource-specific permissions):

- **Owner** — ultimate family-space control: ownership administration and
  deletion authority, plus everything Administrator can do.
- **Administrator** — membership and operational administration, excluding
  the protected Owner-only actions below.
- **Member** — normal, trusted family participation; no administrative
  authority.
- **Contributor** — limited, contribution-oriented access; not granted
  ordinary administrative authority or unrestricted archive access by
  default. Concrete meaning deferred to Phases 5/6.
- **Guest** — explicitly limited or temporary access to resources
  specifically granted to that guest. Concrete meaning deferred to Phase 7.

**Membership-administration authority (Phase 3 scope):**

| Action | Owner | Administrator | Member | Contributor | Guest |
|---|---|---|---|---|---|
| View/participate at the space level | ✓ | ✓ | ✓ | ✓ | limited* |
| Invite new members | ✓ | ✓ | — | — | — |
| Remove a member (not an Owner) | ✓ | ✓ | — | — | — |
| Change a member's role (not to/from Owner) | ✓ | ✓ | — | — | — |
| Promote a member to Owner / demote an Owner | ✓ | — | — | — | — |
| Create another family space | governed separately — see §4, not this table | — | — | — | — |
| Request Family Space deletion | ✓ | — | — | — | — |
| Cancel a pending deletion request | ✓ | — | — | — | — |
| View pending-deletion status and dashboard/contact-owner prompt | ✓ | ✓ | —† | —† | —† |

\* Guest's baseline space-level access is resource-grant-driven; Phase 7
defines "limited" concretely.
† Not specified as a Phase 3 requirement — see Deferred concerns.

### 4. Family space creation: a narrow, bounded platform-level operation, never a tenancy bypass

Ownership inside Family Space A and permission to create a new Family Space
B are two different authorities and remain fully decoupled.

- **The bootstrap workflow** creates the first `User`, the first
  `FamilySpace`, and that user's `owner` membership, atomically. **It does
  not grant `can_create_family_spaces`** — that capability starts `false`
  even for the bootstrap user.
- **`users.can_create_family_spaces`** (boolean, default `false`) is the V1
  platform-level capability, unrelated to any membership role, granted and
  revoked only through audited, operator-attributed console commands
  (`fambam:grant-family-space-creation` /
  `fambam:revoke-family-space-creation`, both idempotent, both requiring
  `--operator`, both affecting only this flag), used for exactly one
  purpose: authorizing the creation of a new `FamilySpace`.
- **No broader platform-permissions model is introduced yet.**

**The creation transaction, as a bounded sequence, not a tenancy bypass:**

1. Verify the authenticated user's `can_create_family_spaces = true`
   (`403` otherwise, checked before any database work begins).
2. Generate the new Family Space's identifier in the application.
3. Begin the database transaction.
4. Establish tenant context for that identifier, for the remainder of the
   transaction only.
5. Create the `family_spaces` row using that identifier.
6. Create the initial `owner` `family_space_memberships` row.
7. Write the `family_space.created` audit event.
8. Commit atomically.

This authorises the operation through `can_create_family_spaces` alone — it
does not require an existing resolved tenant context, and it does not
relax, special-case, or bypass any enforcement layer. It is authorised the
same way §9(A)/(B) authorise any tenant-registry or membership write: by
the transaction already holding valid tenant context for the specific
identifier being written, established from a value the transaction itself
generated rather than resolved from a URL. A failure at any step rolls back
every write together; the established context does not outlive the
transaction and was never usable against a different, pre-existing tenant.

**How this retires `can_invite`:** unchanged — three separately-scoped
authorities replace one conflated boolean.

**Frontend note:** "create a family space" is rendered only when
`can_create_family_spaces = true` in the current-user payload.

### 5. One invitation domain and acceptance flow, including rejoin after removal

`invitations` gains a required `family_space_id` and a `role`. **The
invitation record itself names the target space directly — invitation
acceptance does not go through §7's slug-resolution path at all.** Redeeming
a valid, unexpired, unrevoked invitation is itself the authorization to
establish tenant context for that space — the same "already-authoritative
identifier" pattern §4's creation transaction uses, not the
request-resolution pattern §7 defines for ordinary member requests.

Within that established context, accepting an invitation performs the
following atomically, in one transaction:

1. Create the `User` if the invited email has no account yet.
2. **Locate the existing membership for this user and space, if any, and
   either reactivate it or create a new one, using a single atomic database
   operation rather than a separate check-then-act sequence:**
   - **No existing row** → a new, active membership is created with the
     invited role.
   - **An existing, previously `removed` row** → it is reactivated in
     place: state returns to active, the newly invited role is applied,
     removal metadata is cleared, and the row is associated with the new
     invitation — **no second row is ever created**, satisfying the
     `unique (family_space_id, user_id)` constraint by construction.
   - **An existing, currently `active` row** (already a member) → the
     operation fails cleanly rather than silently succeeding; this is
     additionally guarded at invitation-*issuance* time, so acceptance
     should not normally encounter it.
   - The operation must be atomic and safe under concurrent acceptance or
     re-invitation attempts — the implementation must use an atomic
     database operation appropriate to PostgreSQL for this, and concurrency
     behaviour must be verified directly with tests, not assumed. The
     specific technique belongs in the implementation documentation and
     migrations, not this ADR.
3. Write an audit event distinguishing a new membership from a reactivated
   one.

**Invitation-issuance-time guard:** issuing an invitation to an email
already an *active* member of the target space is rejected at issuance.

**Existing-account joining an additional space** reuses this exact flow —
only account creation (step 1) is skipped.

**Migration note (unchanged):** any Phase-2-style invitation still pending
at migration time is expired and must be reissued as family-scoped.

### 6. Ownership: at least one active Owner, enforced at two independent layers

A family space may have more than one active Owner; the invariant is "at
least one," applying to every family space whose `status` is not `deleted`.

- **Promotion** — any active Owner may promote any other active member to
  `owner`, without anyone stepping down.
- **Self-demotion** — an Owner may demote themselves to `administrator`.
- **Owner-on-Owner action** — only another active Owner may demote or
  remove an Owner other than themselves.
- **The invariant**: no transaction may leave a non-deleted family space
  with zero active Owners.

**Both enforcement layers are required, documented and tested
independently:**
1. **Application-level** — every action touching an Owner's role or state
   refuses any operation that would leave zero active Owners.
2. **Database-level** — a deferred constraint trigger on
   `family_space_memberships`, checked at transaction commit rather than
   per statement (so a promote-then-demote pair within one transaction
   doesn't trip on its own momentary intermediate state), raising if
   committing would leave a **non-deleted** family space with zero active
   Owners — exempting a space whose `status` is already `deleted`.

**Ordering constraint for the deletion teardown job:** update
`family_spaces.status = 'deleted'` *before* removing the space's final
owner membership row, within the same transaction, so the trigger
correctly recognises that final cleanup as exempt.

### 7. Friendly-URL resolution: 404 for unknown/inaccessible tenants, 403 only once a member is acting outside their role

Resolution for a request to `/families/{slug}/...`, with the requester's
identity already established (§9) and no Family Space context yet:

1. **Resolve `{slug}` against `family_spaces`.** Because `family_spaces`'
   pre-context read access is limited to spaces the requester has proven
   active membership in (§9.A), this single lookup naturally returns
   nothing for *both* an unknown slug and a real-but-inaccessible one —
   the two cases are structurally indistinguishable at the data layer, not
   merely mapped to the same status code by application logic. **Either
   way: `404`.**
2. Resolve the same user's active membership role for the now-confirmed
   space (§9.B's self-visibility guarantee).
3. **Only now** establish tenant context (bind `TenantContext`) for the
   remainder of the request.
4. Ordinary tenant-scoped authorization and data access proceed — an active
   member attempting an out-of-role action gets `403`.

**Forward-looking constraint (unchanged):** a future access-recovery flow
must not require publicly confirming a private family space exists.

### 8. Defense in depth: why each layer exists and none replaces another

| Layer | Catches |
|---|---|
| Slug/route resolution, backed by `family_spaces`' registry-specific access rules | A request for a nonexistent or inaccessible tenant — the two are structurally indistinguishable at the data layer, not just the HTTP layer (§7, §9.A). |
| Middleware (`TenantContext` binding) | Establishes tenant context once, early, only after resolution has already proven membership. |
| Policies | Who may do what, per action, tied to role — and, for space creation, tied to `can_create_family_spaces` rather than any role. |
| Explicit query scoping | Makes the tenant boundary visible and testable at the call site. |
| Database constraints | Invariants that must hold regardless of code path. |
| PostgreSQL RLS — three distinct treatments | The tenant registry, the access-control table, and ordinary tenant-owned content tables each get security rules shaped for their actual role, not one rule copy-pasted onto all three (§9). |

Storage-key partitioning extends the same philosophy to object storage,
applied only to content, never to the registry or membership tables
themselves.

### 9. PostgreSQL Row-Level Security: three distinct policy classes

Tenant isolation depends on two things being known at different points in a
request: **who is asking**, which can be established as soon as
authentication succeeds, and **which Family Space the request is scoped
to**, which — for ordinary requests — cannot be known until resolution
(§7) has already proven active membership. `app.current_user_id` may
therefore be established before tenant resolution; Family Space context is
established only after valid membership has been proven, except for
bounded operations that already possess authoritative tenant identity
(creation, §4; invitation acceptance, §5).

This single fact — that "who" and "which tenant" become known at different
times — is why the three tables below cannot share one identical RLS
treatment, and is the entire reason this ADR distinguishes them.

**A. Tenant registry: `family_spaces`.**
- Participates in tenant discovery, and therefore cannot require an
  already-established Family Space context for the read needed to
  establish that context in the first place — that would be circular.
- Pre-context read access is instead limited by authenticated-user identity
  together with proven active membership: a row is visible only to a user
  who already has an active relationship to it.
- The design must prevent arbitrary tenant enumeration — no request can
  learn that a Family Space exists without already having a provable,
  active relationship to it.
- Creation and mutation remain restricted to explicitly authorised flows —
  creation only via §4's bounded operation; mutation only once ordinary
  tenant context has been established for that specific space.
- Hard deletion is unavailable to the normal runtime application path; only
  the soft state machine (§12) applies.
- The exact policy implementation — composition, whether a correlated
  lookup, a resolver function, or another verified mechanism is used —
  belongs in the Phase 3 implementation documentation and migrations, not
  in this ADR.

**B. Access-control table: `family_space_memberships`.**
- Must support a user resolving and listing their own legitimate
  memberships before tenant context exists.
- Once tenant context exists, authorised membership administration may
  operate within that Family Space.
- Pre-context self-visibility must never imply self-service role mutation,
  restoration, promotion, or cross-space write authority — being able to
  see your own membership row is not the same as being allowed to write to
  it outside an established administrative context.
- Unrelated users must not enumerate arbitrary memberships.
- Invitation acceptance and initial-owner creation may establish context
  from an independently authoritative Family Space identifier (the
  invitation's own target, or the space just created) rather than through
  slug resolution.
- Exact policy composition belongs to the implementation guide and
  migrations.

**C. Ordinary tenant-owned domain tables** — future photos, albums, events,
people, stories, metadata, vectors, and similar rows.
- Require established Family Space context.
- Reads, updates, and deletes are restricted to rows in that context.
- Inserts, and the resulting state of updates, must remain within that
  context — both what's targeted and what's produced.
- Missing tenant context fails closed.
- `FORCE ROW LEVEL SECURITY` and a non-superuser, non-`BYPASSRLS` runtime
  role are mandatory.
- The standard policy pattern should be centrally documented and reused
  rather than re-invented per table.

**Circularity constraint.** Registry visibility (A) depends on membership
data (B); membership data is itself protected. This relationship must not
be allowed to produce recursive or circular evaluation, and the chosen
implementation must be verified directly against PostgreSQL's actual
behaviour rather than assumed correct from documentation. Acceptable
solutions may include carefully separated policies, a bounded resolver
function, or another reviewed mechanism — this ADR does not freeze which,
and a later change between such mechanisms, provided the guarantees above
still hold, does not require a superseding ADR.

### 10. Asynchronous propagation

Unchanged: every queued job, storage operation, audit write, and message
to/from the Python image-analysis service carries `family_space_id`,
`actor_user_id`, `correlation_id`, and W3C trace context.

### 11. Audit

Unchanged: space creation, `can_create_family_spaces` granted/revoked,
invitation issued/accepted, member joined/reactivated/removed, role
changed, ownership promotion/demotion, deletion requested/cancelled.

### 12. Deletion lifecycle

Unchanged: `active → deletion_requested → deleting → deleted`, Owner-only
request/cancel, Administrator visibility only, configuration-driven grace
period (default 14 days) persisted as an absolute timestamp, idempotent
teardown updating `family_spaces.status = 'deleted'` before removing the
final owner membership row.

### 13. Frontend

Unchanged: tenant-aware query keys, URL-derived active family space,
protected routes mirroring the backend's fail-closed 404,
`can_create_family_spaces`-gated creation UI, Owner-only deletion actions
with Administrator-only visibility, no inline HTTP calls, graceful
membership-loss handling.

## Alternatives considered

**Tenant column on `users` instead of a membership table** — rejected.

**Auto-incrementing integer or random UUIDv4 for identifiers** — rejected
in favor of ULID.

**Three roles, deferring Contributor and Guest** — rejected.

**Unrestricted family-space creation, or Owner-anywhere creation
authority** — rejected in favor of `can_create_family_spaces`.

**Bootstrap auto-grants `can_create_family_spaces`** — rejected.

**A richer platform-permissions model now** — rejected for V1.

**Exactly one Owner** — rejected in favor of "at least one, multiple
allowed."

**Application-level-only, or trigger-only, enforcement of the owner
invariant** — rejected; both required together.

**Administrators able to cancel a pending deletion request** — rejected.

**Hardcoding the deletion grace period** — rejected.

**A second membership row on rejoin, or a separate check-then-act sequence
instead of one atomic operation** — rejected: the unique constraint already
forbids a second row, and a separate check-then-act sequence reopens
exactly the race window an atomic operation closes by construction.

**Relying on read-path rules alone to cover write-path protection** —
rejected: read and write isolation are both required for ordinary
tenant-owned tables, and for updates specifically, both the row being
targeted and the row state produced must be protected.

**Applying the same tenant-scoped read/write treatment used for ordinary
content tables directly to `family_spaces` and `family_space_memberships`**
— this was an earlier draft's position; **rejected**. It creates a genuine
circular dependency: resolving a slug requires reading `family_spaces`
before tenant context can exist, and checking membership requires reading
`family_space_memberships` before tenant context can exist, yet the
ordinary content-table treatment would require that same context to
already be set. `family_spaces` and `family_space_memberships` instead
receive access rules shaped for their actual role — registry resolution
and access control, respectively — evaluated in part against the
requester's identity, which is available earlier than any resolved tenant
context.

**A special-cased bypass, alternate role, or elevated database privilege
for tenant resolution** — rejected: unnecessary once `family_spaces` and
`family_space_memberships` have rules actually shaped for pre-tenant-context
resolution; would otherwise be exactly the kind of general bypass this ADR
is required not to introduce.

**Recording the exact PostgreSQL policy implementation (statements,
policy names, internal detection tricks) in this ADR** — considered and
rejected. Freezing one specific SQL implementation would make a later
equivalent refactor — an equivalent helper function, a security-definer
resolver, a different policy composition, or a safer PostgreSQL idiom that
preserves the same guarantees — look like an architectural change when it
isn't one. The trust boundaries and required guarantees are the durable
decision; the SQL that satisfies them belongs in the implementation
documentation and migrations.

**403 for inaccessible-but-existing tenants** — rejected; now reinforced
structurally, not just as an application-layer choice (§7, §9.A).

**Transaction-scoped context that could leak across pooled or reused
connections** — rejected in favor of context that does not outlive its
transaction.

**Building the full deletion cascade now** — rejected.

## Consequences

### Positive

- The unknown-slug and inaccessible-slug cases are structurally the same
  outcome, not just the same HTTP status chosen by application code.
- `family_spaces` and `family_space_memberships` each have access rules
  shaped for what they actually are — a registry and an access-control
  table — rather than a rule that looked uniform but was unusable for the
  one job (tenant resolution) the whole system depends on.
- Ordinary tenant-owned content tables inherit a genuinely simple, uniform,
  already-proven pattern, since the hard part (bootstrapping tenant context
  in the first place) is fully solved here.
- Family-space creation and invitation acceptance read as two instances of
  one coherent principle — an operation that already possesses
  authoritative tenant identity may establish context directly.
- The ADR states guarantees and boundaries without freezing one
  implementation, so a later equivalent PostgreSQL idiom doesn't force a
  superseding ADR merely for using a different technique to the same end.

### Negative

- Three distinct security treatments (registry, access-control, content)
  to understand and maintain instead of one — more conceptual surface for
  a future contributor to internalize before adding a new table correctly.
- Because this ADR deliberately does not prescribe the exact
  implementation, the Phase 3 implementation guide and migrations carry
  more of the burden of getting the registry and access-control tables'
  rules right than they would if the ADR had specified them directly — a
  real transfer of responsibility, made deliberately.
- The bootstrap user has no ongoing Family Space creation capability until
  an explicit audited grant is performed.

### Risks

- If a future contributor adds a new access pattern to
  `family_space_memberships` without recognizing it needs rules shaped for
  its purpose rather than reuse of an existing rule, they risk either
  reintroducing a circular dependency or accidentally over-widening
  visibility.
- The registry-visibility-depends-on-membership-data relationship (§9)
  could produce recursive or circular evaluation if implemented carelessly
  — the implementation must be verified directly against PostgreSQL's
  actual behaviour, not assumed correct.
- If the deletion-teardown job's status-update-before-membership-removal
  ordering (§6) is implemented backwards, the constraint trigger will
  incorrectly block the space's own final cleanup.
- If a future migration adds an ordinary tenant-owned table with read
  protection but incomplete write protection, that table's writes are
  vulnerable to cross-tenant reassignment even though its reads are safe.
- If the non-superuser, non-`BYPASSRLS` runtime role isn't correctly
  provisioned in production, every isolation guarantee here is silently
  void while everything still appears to work locally.

## Implementation notes

**This ADR is the durable statement of trust boundaries and required
guarantees, not the implementation.** The exact PostgreSQL policy
definitions, migration order, session-context setup, resolver
implementation, and verification SQL belong in:
- `docs/IMPLEMENTATION_GUIDE.md`;
- the Phase 3 implementation journal, where appropriate;
- the migrations themselves and their comments;
- focused database tests.

- **FPA-P03-S02** implements §1, §3, §4 (creation transaction sequence),
  §5 (atomic reactivation, and the known-identifier context-setting
  pattern for invitation acceptance), §6.
- **FPA-P03-S03** implements §7 (resolution backed by §9.A/§9.B's
  guarantees) and §8's route/middleware/policy/query layers.
- **FPA-P03-S04** implements §9 in full, including the concrete policy
  implementation for all three classes, `FORCE ROW LEVEL SECURITY`, and
  the non-`BYPASSRLS` runtime role — the specific PostgreSQL mechanism used
  for each class is an implementation decision verified against this ADR's
  stated guarantees, not dictated by it. **The chosen design must be
  checked for recursive or circular policy evaluation directly against
  PostgreSQL's actual behaviour before being accepted, not assumed correct
  from documentation** — see §9's circularity constraint.
- **FPA-P03-S05** implements §10, §11, §12.
- **Testing** — required behaviour, not prescribed SQL:
  - Unknown and inaccessible slugs are indistinguishable.
  - Users can see only their own legitimate memberships.
  - Pre-context resolution cannot be reused to access ordinary tenant-owned
    content data.
  - Cross-tenant reads and writes fail.
  - Inserts cannot target another Family Space.
  - Updates cannot move rows between Family Spaces.
  - Missing tenant context fails closed for both reads and writes.
  - Family-space creation and invitation acceptance remain atomic — a
    failure at any step leaves no partial state.
  - Membership reactivation is race-safe under concurrent acceptance
    attempts.
  - Runtime roles cannot bypass row-level security.
  - Tenant context does not leak across commit, rollback, or a reused
    connection.
  - The owner invariant holds at both the application and database layers,
    tested independently.

## Review triggers

- Before Phase 15: re-verify the non-`BYPASSRLS`, non-superuser runtime
  role in production.
- If a second platform-level capability is ever needed: replace the single
  `can_create_family_spaces` boolean with a proper platform-capability
  model.
- When Phase 5/6/7 are scoped: give Contributor and Guest their first
  concrete resource-level meaning; confirm every new content table follows
  §9.C's standard pattern — if a future table seems to need bespoke
  treatment the way the registry/access-control tables did, treat that as
  a signal worth escalating, not a routine choice.
- When Phase 9 is scoped: confirm vector/embedding tables use §9.C's
  standard pattern.
- Before any future access-recovery feature ships: confirm no public
  space-existence check.
- If real usage shows the operator-only creation-grant workflow is too
  restrictive, or that broader deletion-status visibility is wanted:
  revisit deliberately.
- Consider a CI check enforcing that every ordinary tenant-owned table's
  migration includes both read and write isolation.

## Deferred concerns

- Concrete resource-level permissions for Contributor and Guest.
- Deletion-status visibility for Members, Contributors, and Guests.
- Owner-disagreement/tie-breaking and Owner-succession in a multi-Owner
  space.
- A CI check enforcing isolation completeness per new tenant-owned table.
- Full content-table deletion cascades.
- Any future relaxation of family-space-creation authority.
- A richer platform-permissions model.

## Resolved decisions

1. **Identifier type** — ULID.
2. **Bootstrap and creation capability** — bootstrap never grants
   `can_create_family_spaces`; always acquired via the separate audited
   workflow.
3. **Creation-capability mechanism** — a single boolean for V1, with a
   named review trigger for replacement.
4. **Owner-invariant enforcement** — both application-level and database
   trigger, required together, tested independently.
5. **Deletion authority** — Owner-only request/cancel; Administrator
   visibility only; no authority for other roles.
6. **Deletion grace period** — 14 days by default, configuration-driven.
7. **Rejoin after removal** — a single atomic operation against the
   existing unique constraint; no second row, no separate check-then-act
   sequence; exact technique left to implementation.
8. **Read/write isolation** — both required for ordinary tenant-owned
   tables; updates protect both the targeted row and the resulting row
   state.
9. **Family-space creation and tenancy** — a narrow, bounded transaction
   authorised by `can_create_family_spaces` alone, establishing its own
   tenant context from a self-generated identifier; explicitly not a
   general bypass; exact policy mechanics left to implementation.
10. **Tenant registry and access-control tables are architecturally
    distinct from ordinary tenant-owned content tables** (§9.A/§9.B/§9.C).
    The registry's pre-context visibility depends on proven active
    membership; the membership table supports both self-visibility (for
    resolution) and tenant-scoped administration; ordinary content tables
    use one standard, uniform pattern. The specific PostgreSQL
    implementation of each is deliberately left to the implementation
    documentation and migrations, not fixed by this ADR.
