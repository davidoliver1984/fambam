# ADR-0013: Search and Discovery

- Status: Accepted
- Date: 2026-09-07
- Decision owners: David
- Related stages: FPA-P11-S01 (accepting this ADR completes that stage),
  implemented by FPA-P11-S02, FPA-P11-S03, FPA-P11-S04, FPA-P11-S05

## Context

Phase 10 (ADR-0012, accepted) closed with a complete, calibrated face
recognition foundation: `FaceObservation`s remain machine-derived and
immutable; only an `approved` `FaceIdentityAssignment` ever creates or
confirms a `PhotoPerson` row; a `pending`/proposed row is never fact. This
ADR inherits that boundary directly: **search for a Person's presence in
a Photo means an approved `PhotoPerson` row, full stop.**

Phase 6-9 already built the domain this ADR searches over. This
reconciliation re-verified every schema and authorization claim directly
against the live repository, and corrects several places the original
draft named a primitive imprecisely or assumed one existed where it does
not.

**Photo/domain schema** (unchanged from the original draft, reconfirmed):
`photos.location_description`/`events.location` are plain `varchar(255)`
free text — no structured place model exists. `historical_date`/
`historical_date_precision` reuse `App\People\UncertainDate`/
`App\Enums\DatePrecision` unmodified from the Person birth/death-date
concept. `tags`/`photo_tag` has no relationship to `people` at all.
`photo_stories` is a single canonical, Photo-level, versioned narrative;
`photo_comments`/`photo_reactions` are Album-scoped for every new row
(ADR-0010 §6/§7). `people.preferred_name`/`people.alternate_names` (a
JSON array) are the actual free-text Person fields — **there is no
`people.name` column.**

**`UncertainDate::fromInput()` confirms exactly how each precision is
stored**: `exact`/`approximate` store the literal date; `month` stores
the 1st of that month; `year` stores 1 January; `decade` stores 1 January
of the decade's first year; `unknown` stores `NULL`. `historical_date` is
always the *start* of its window — §6 fixes the corrected, calendar-safe
end-of-window arithmetic this reconciliation found the original draft got
wrong.

**Authorization primitives — verified precisely, not assumed**:

- `PhotoQuery::visibleTo(User $viewer): Builder` and
  `AlbumQuery::visibleTo()`/`findVisibleTo()` exist exactly as the
  original draft described, and remain this ADR's base for Photo/Album
  search.
- `FamilyEventQuery` (`apps/api/app/Queries/FamilyEventQuery.php`) has
  **no actor-aware `visibleTo()` method of any kind** — direct inspection
  shows `all()`/`find()`/`attendees()`/`forPerson()` are scoped only by
  `family_space_id`, with no row-level actor filtering. Event-level
  authorization instead lives in `FamilyEventPolicy::view()`
  (`user_id` must match the current membership, then either an ordinary
  member or a Guest with `EventAccess::hasValidAdmission()`) and, at the
  Album grain, `EventAccess::scopeAlbumsForGuest()`. **No single query
  today returns "every Event this actor may see."** §9 requires this ADR
  to introduce one, composed from what already exists, not invented from
  scratch.
- `PersonPolicy::hasDirectoryAccess()` is **`private`** — confirmed by
  direct inspection of `apps/api/app/Policies/PersonPolicy.php`. It cannot
  be called from outside the policy. The existing, already-used **public**
  boundary is `PersonPolicy::viewAny(User $user): bool`, invoked exactly
  as `PersonController::index()` already does it —
  `Gate::authorize('viewAny', Person::class)` (or `Gate::denies(...)` for
  a non-throwing check). §9 corrects the earlier draft to name this
  boundary, not the private helper.
- `PhotoStoryPolicy` (`apps/api/app/Policies/PhotoStoryPolicy.php`) exists
  but defines only `update`/`delete` — **it has no `view` authorization at
  all**, confirmed by reading the file. A Story's visibility is therefore
  necessarily derived entirely from its owning Photo's visibility; it can
  never become independently visible because its text happened to match a
  query.
- `FamilySpaceDeletionManager::teardown()` (confirmed by direct
  inspection) never physically deletes the `family_spaces` row — it flips
  `status` to `Deleted` and then explicitly deletes each Class C
  tenant-owned table by an ordinary `WHERE family_space_id = ...`
  statement (`FamilyCircle`, `EventAdmission`, `Album`, `Photo`,
  `FamilyEvent`, `Tag`, `FaceClusterGeneration`, `MediaUpload`, `Person`,
  and others), one at a time, in one transaction. **`ON DELETE CASCADE`
  from `family_spaces` never fires, because that row is never deleted.**
  Any new table this ADR introduces must be added to this same explicit
  list, exactly as ADR-0011's reconciliation already had to add
  `face-analysis` to the equivalent object-storage cleaner.

ADR-0006 §12 fixed Person merge's obligation on every future
Person-referencing domain; §18 closes it here, extending
`PersonMergeManager`'s existing `captureState()`/`restoreState()`
transaction exactly as ADR-0012 §12 already did for face identity.

`PROJECT_ROADMAP.md`'s Phase 11 exit criteria — searches remain
family-scoped; approximate historical dates behave predictably; Person
and Event combinations are supported; results are accessible and
mobile-friendly — are what this ADR must make architecturally true.
`docs/IMPLEMENTATION_GUIDE.md`'s Phase 11 section is currently headers
only; this reconciliation also aligns it with the corrected stage
ownership in §21.

This ADR decides: what is searchable and in what bounded, typed shape;
the real authorization entry point for every domain, including the new
one this ADR requires for Events; corrected date-window arithmetic;
deterministic ranking and pagination; the PostgreSQL-native persistence
strategy; the Fambam-owned search abstraction; the full saved-search
integrity/authorization/teardown/merge model; and Discovery as explicit
relationship traversal. It decides **nothing** about Phase 12's activity
feed, Phase 14's final UX, or Phase 18's semantic/visual search.

## Decision

### 1. Scope: structured and textual discovery over explicit family facts

Phase 11 answers questions about facts the family already recorded
somewhere — a name, a tag, a caption, a story, a date, a location string,
a confirmed relationship — never inferred from image content. That
boundary is self-enforcing: no embedding, model, or visual-similarity
mechanism is introduced (Phase 18's concern, untouched).

### 2. Search shape: one entry point, typed result groups

A single global search entry point returns results grouped by real
domain type — People, Photos, Albums, Events, PhotoStories — never
forced into one universal cross-type relevance ranking. Domain-specific
search (e.g. within one Album) is the same underlying per-domain query,
reachable directly.

### 3. Result contract: bounded, reusable typed search summaries

**Corrected from the original draft**, which proposed literally reusing
each controller's private `payload()` method. Those methods are private
implementation detail, and several are richer than a search result
should ever expose (full provenance, proposal history, and similar
detail no search/discovery surface needs). The product requirement —
real, navigable domain identity, never a flattened `{title, url}` — is
preserved by introducing **dedicated, deliberately bounded, per-domain
summary shapes**:

```text
SearchResponse
{
    people:  Page<PersonSearchSummary>,
    photos:  Page<PhotoSearchSummary>,
    albums:  Page<AlbumSearchSummary>,
    events:  Page<EventSearchSummary>,
    stories: Page<PhotoStorySearchSummary>
}
```

Each summary: carries the entity's real domain id; contains only the
fields needed to render and navigate a search/Discovery result (name/
title, a thumbnail reference where applicable, the minimal disambiguating
context a family member needs); is produced by a query already
constrained to the actor's authorised universe (§9) — never loaded in
full and then trimmed; and is never collapsed to a generic
`{title, url}` shape. **`PhotoStorySearchSummary` at minimum carries the
Story's own id, its owning Photo's id, and enough of the Photo's own
identity (e.g. a thumbnail reference) to navigate to the right context**
— a Story is never presented without the Photo it belongs to. These are
new, small, purpose-built types — this project has no Laravel API
Resource layer to extend, and this ADR does not introduce one merely for
this; a plain typed mapping method per domain (the same shape this
project already uses for every other JSON-shaping concern) is sufficient.

### 4. Free text vs. structured filters

**Structured filters**: Person, historical date/range/decade, Album,
Event, tag, contributor/uploader, and — where authorised — visibility.
**Free text**, matched only against explicit textual metadata: Photo
caption/description/archive-source text, PhotoStory body,
`preferred_name`/`alternate_names`, Album names, Event names, tag labels,
and `location_description`/`events.location`. No natural-language query
parser.

### 5. People-aware search: only approved `PhotoPerson` is fact

Search for a Person's presence in a Photo, and every combination built
from it, queries `photo_people` rows with `status = 'approved'` only. A
`pending` `PhotoPerson` proposal or any `FaceIdentityAssignment` status is
never consulted — this follows directly from ADR-0008 §5 and ADR-0012
§5, since approval is the only path that ever produces the row search
reads. "William + Susan" is
`photo_id IN (approved PhotoPerson for William) AND photo_id IN (approved
PhotoPerson for Susan)` — a plain relational intersection.

### 6. Date semantics: corrected, calendar-safe interval overlap

Every `(historical_date, historical_date_precision)` pair implies a
window `[start, end]`, using next-boundary arithmetic that is correct
across month/year lengths and leap years without special-casing them:

```text
exact / approximate  → start = historical_date;  end = historical_date
month                → start = historical_date;  end = (start + 1 month) - 1 day
year                 → start = historical_date;  end = (start + 1 year)  - 1 day
decade               → start = historical_date;  end = (start + 10 years) - 1 day
unknown              → no window; historical_date is NULL; never matches a date filter
```

`(start + N unit) - 1 day` is deliberately used for month/year/decade
alike, rather than manual day/month arithmetic, precisely because
Postgres date-interval arithmetic already lands on the correct calendar
boundary regardless of month length or leap year — a 29-day February, a
31-day December, and a decade spanning a leap year all fall out correctly
from the same formula, with nothing to get wrong per case.

**`approximate` intentionally behaves as the same one-day anchor as
`exact`.** This is not a new decision this ADR makes — `UncertainDate`
already stores `approximate` identically to `exact` (both parsed as
`!Y-m-d`), because the current domain model has no uncertainty-radius
concept (no "±N days/months"). This ADR does not invent one; it names the
existing behaviour honestly rather than pretending `approximate` carries
fuzziness it does not actually have today.

A requested period is itself an interval `[query_start, query_end]`. **A
Photo matches when the intervals overlap**: `photo_end >= query_start AND
photo_start <= query_end`. This is symmetric by design: an `exact`,
`year`, or `decade`-precision Photo all correctly match a broader "1980s"
query, and — just as correctly — a `decade`-precision "1980s" Photo also
matches a narrower single-year query within that decade, because a
photo genuinely dated somewhere in the 1980s cannot be ruled out as being
from that year. `historical_date_precision` is always returned alongside
a date-matched result so the UI can say "circa 1980s" rather than
implying false precision. **Upload/`created_at` is never substituted for
historical date, anywhere in this mechanism.**

The window end is not currently stored and must be — a generated
`historical_date_window_end` column (§10), computed in the same row and
transaction as `historical_date`/`historical_date_precision`.

### 7. Location and tags

**Location**: text/trigram matching against `location_description`/
`events.location` only — no geocoding, place hierarchy, coordinates, or
distance search; none of that data exists in this schema. **Tags**:
participate as a structured filter, an autocomplete source (§14), and a
distinct relational ranking signal (§12) — **never as text copied into,
or implied to be part of, any table's generated `search_vector`** (§10).
Tags remain structurally separate from Person identity, as they already
are in the schema.

### 8. Stories and comments

**PhotoStory participates in global search.** Since `PhotoStoryPolicy`
defines no independent `view` authorization (§ Context), Story search
**always begins from the actor's already-authorised Photo set
(`PhotoQuery::visibleTo($actor)`) and joins that Photo's Stories onto
it** — a Story's own text matching a query is never sufficient on its
own to disclose it; visibility is inherited entirely from its Photo, with
no independent Story-level check to bypass or forget.

**Comments and reactions do not participate in Phase 11 global search**
— ADR-0010 §6 already frames a comment as conversation about a memory,
not the memory itself; the same Photo can carry independent conversations
across different Albums, making a comment match ambiguous about which
conversation it belongs to; and legacy Photo-scoped rows have no Album
context to authorize against consistently. A future Album-contextual
"search this conversation" capability is not prohibited, but is out of
scope here and would have to be bounded to Albums the actor can already
open, using the same authorization discipline as everything else in this
ADR.

### 9. Authorization entry points — the real ones, per domain

**Every search operation begins from the domain's actual, verified
authorised query, with search predicates layered on top — never "query
everything, then filter."** This applies identically to results, counts,
facets, autocomplete, and Discovery traversal (§9 applies to §13's
pagination too: a page boundary is computed *within* the authorised set,
never by paginating an unfiltered set and filtering each page).

- **Photos**: `PhotoQuery::visibleTo($actor)` — unchanged, already
  correct.
- **Albums**: the existing actor-aware Album visibility query
  (`AlbumQuery::visibleTo()`/`findVisibleTo()`) — unchanged, already
  correct.
- **Events**: **no actor-aware visibility query exists today** (§
  Context). This ADR requires a new one — conceptually
  `FamilyEventQuery::visibleTo(User $actor): Builder` or an equivalent
  repository-consistent addition — that correctly composes what already
  exists rather than inventing new Guest rules: ordinary Family Space
  membership sees the tenant-scoped Event set (as `viewAny`/
  `ordinaryMember` already assume), and a Guest's visible Event set is
  restricted to Events they hold a currently-valid `EventAdmission` for,
  using the same predicate `EventAccess::hasValidAdmission()` already
  encodes per-Event, expressed as a set-level condition rather than a
  one-Event-at-a-time check. Search predicates are applied only after
  this actor-visible Event universe exists — this is new, required
  implementation work, not something this ADR can claim is already done.
- **People**: `Gate::authorize('viewAny', Person::class)` /
  `Gate::denies('viewAny', Person::class)` — the existing **public**
  boundary `PersonController::index()` already uses — decides, before the
  People sub-search runs at all, whether it runs for this actor. The
  private `hasDirectoryAccess()` helper is never called directly from
  search or anywhere outside `PersonPolicy` itself.
- **PhotoStories**: no independent check — always via the Photo they
  belong to (§8).
- **Tags**: a tag is only ever surfaced (in a filter list, an
  autocomplete suggestion, or a facet) via a tag actually attached to a
  Photo the actor can search under `PhotoQuery::visibleTo($actor)` —
  tenant-wide tag existence (`tags` scoped only by `family_space_id`) is
  **not** sufficient authorization for disclosure on its own, since a tag
  could exist only on Photos the actor cannot see.

### 10. PostgreSQL persistence: generated columns on live tables, no projection

Normalized live domain tables are queried directly through the
authorization entry points above. **No separate `search_documents`
projection table is introduced.** Each searchable table gains a
`search_vector tsvector GENERATED ALWAYS AS (...) STORED` column, built
**only from that table's own same-row free-text fields**:

```text
photos.search_vector        ← caption, description, archive_source_description, location_description
people.search_vector        ← preferred_name, alternate_names (flattened to text)
albums.search_vector        ← name, description
events.search_vector        ← name, description, location
photo_stories.search_vector ← body
```

**`photos.search_vector` never contains tag text.** Tags are relational
(`photos → photo_tag → tags`) and remain a separate filter/ranking
signal (§7, §12) — copying tag labels into the Photo vector would
reintroduce exactly the kind of duplicated, driftable text this ADR's
no-projection decision exists to avoid, since a tag rename/detach would
then have to also correctly update every Photo's vector rather than being
a single relational fact. `historical_date_window_end` (§6) is the same
kind of same-row generated column. Each `search_vector` gets a GIN index;
`pg_trgm` GIN indexes support bounded fuzzy matching on the short name/
label/location fields.

**Why not a denormalized projection** (unchanged from the original
draft): authorization is already solved per domain, and a projection
would have to independently re-derive and stay in sync with it; Person-
merge correctness comes for free from live `PhotoPerson` queries; the
combined-relationship queries are plain relational filters; realistic
scale (§20) does not need it. A generated `STORED` column cannot drift,
because there is no second write path — Postgres maintains it as part of
the same statement that changes the source columns.

### 11. Search abstraction: a narrow Fambam-owned interface

A narrow search service (e.g. `Fambam\Search\SearchService`) exposes
domain-shaped operations — `search(SearchQuery $query, User $actor):
SearchResults`, `suggest(string $type, string $prefix, User $actor):
array` — never `tsquery` syntax, `ts_rank`, or `pg_trgm` operators
reachable from controller/frontend code. Not a general "enterprise search
platform" — exactly the operations Phase 11 needs. A future dedicated
search engine, if ever justified (§20), is a rebuildable projection from
this same authoritative data, never a second source of family truth —
identical framing to ADR-0012 §2's pgvector/Qdrant boundary.

### 12. Ranking: a deterministic precedence contract, not one universal score

**Corrected from the original draft**, which described all ranking as
`ts_rank`/`setweight` — inaccurate, since different signals have
genuinely different mechanics that cannot share one numeric scale.
Ordering within each typed result group follows a fixed precedence
contract, evaluated in order:

1. **Match-class precedence** — an exact, case-insensitive equality match
   on a name/title field outranks a full-text match, which outranks a
   fuzzy/trigram match, which outranks a purely relational match (e.g. a
   Photo found only via tag/Person filters with no free-text term).
2. **Within-class score** — `ts_rank`/`setweight` for full-text matches;
   `similarity()` for trigram matches; a simple boolean/count for
   relational tag or Person-filter matches (these are not scored against
   each other, only used to break ties within the same precedence class
   where meaningful).
3. **A stable, domain-specific tie-breaker** — e.g. `preferred_name` for
   People, `historical_date` (most recent first) for Photos, `starts_on`
   for Events.
4. **The entity's own id**, as the final, absolute tie-breaker.

No result domain shares one numeric scale with another — grouping by
type (§2) means each group's own ordering never has to be reconciled
against a different domain's. **Stable, fully deterministic ordering is
required specifically so pagination (§13) never reshuffles equally-ranked
results between requests.**

### 13. Pagination and continuation

**New in response to the original draft never bounding result volume —
and corrected again in this reconciliation.** The original draft claimed
grouped search pagination would reuse "the same kind of cursor/page
convention already used elsewhere in this codebase's list endpoints."
Direct inspection found no such convention: no controller anywhere in
`apps/api/app` calls `paginate()`, `simplePaginate()`, or
`cursorPaginate()`, and no standardized page-size or continuation-token
contract exists in this API today. **Phase 11 therefore establishes the
repository's first explicit bounded pagination/continuation
convention** — scoped to grouped search results specifically; this ADR
does not retrofit or standardize any existing, unrelated endpoint.

The already-settled architecture is unchanged: global grouped search is
bounded per group — it never returns every matching row from every domain
in one response. Each of the five groups in `SearchResponse` (§3) is
independently paginated, with the deterministic ordering §12 fixes as the
precondition for stable continuation (an unstable order would let a row
appear twice or be skipped across pages). Requesting the next page of
Photo results never requires re-running or re-returning the People/
Album/Event/Story groups — each group's continuation is independent.

**Contract shape.** Each typed group returns a bounded typed page:

```text
SearchResultPage<T>
{
    items: T[],
    next_cursor: string | null
}
```

`next_cursor` is `null` when the group has no further results.

**Cursor-style continuation is selected over page-number/offset
pagination**, because grouped search already has a mandatory,
deterministic sort key (§12: match-class tier, within-class score, domain
tie-breaker, entity id) and because offset pagination would silently skip
or repeat rows as new content matching the same query is created between
requests — exactly what happens in a live, growing family archive. A
cursor conceptually encodes the last-seen row's sort-key values (the same
fields §12 already orders by) — never a raw database offset, a bare row
id, or SQL. The cursor is an **opaque, backend-issued token**: the public
contract is "pass back what you were given," never a documented internal
structure; clients never construct or inspect one.

**Invalid/stale cursor behaviour is deterministic and existence-safe.** A
cursor that fails to decode is rejected as a validation error — the same
`FormRequest`-validation discipline already used throughout this project
— never silently treated as page one. A cursor that decodes correctly but
whose anchor row has since been deleted, or become inaccessible to the
actor, still resumes correctly, because the cursor encodes sort-key
*values*, not a row identity that must still exist — the next page is
simply "every authorized, matching row whose sort key sorts after this
value," which requires no lookup of the original row at all. This also
means a stale cursor can never be used to infer whether the original row
still exists or is still visible — extending §9's authorization-before-
disclosure discipline to pagination itself.

**Scope of this convention**: established for grouped search results
only. Whether it becomes a reusable precedent for other list endpoints
elsewhere in the repository is an open question left to whichever future
ADR first needs bounded continuation there (§ Review triggers) — this ADR
does not refactor or standardize any existing endpoint to match it. Exact
page-size defaults and the concrete cursor token encoding remain
implementation detail (§ Deferred concerns); the contract principle —
bounded, independently paginated, stably ordered, opaque cursor,
existence-safe staleness handling — is fixed here.

### 14. Autocomplete

Narrow, authorization-scoped suggestions for People, Albums, Events, and
tags — each running through the exact entry point its full-search
counterpart uses (§9): People autocomplete is gated by
`Gate::denies('viewAny', Person::class)` exactly like People search; tag
autocomplete is derived only from tags attached to Photos the actor can
search (never tenant-wide tag existence, per §9); Album/Event
autocomplete run through their own `visibleTo()` scopes. Capped result
count, no ML completion, no natural-language parsing. An actor who cannot
see the People directory receives no Person suggestions because that
sub-suggestion is never invoked for them, not because it ran and returned
empty.

### 15. Saved searches: full integrity, authorization, and lifecycle

**Substantially expanded from the original draft**, which under-specified
integrity, execution-time behaviour, and teardown.

**Schema**: `saved_searches` (id, family_space_id, created_by, name,
`filters` — a normalized, versioned, Fambam-owned JSON shape containing
**no Person ids**, timestamps) and `saved_search_people`
(saved_search_id, family_space_id, person_id). **Person references are
persisted only in `saved_search_people`, never inside the `filters`
JSON** — the API assembles a saved search's full, typed filter state
(including its Person references) for its own response, but the two are
stored separately specifically so Person-merge reconciliation (§18) is a
plain relational `UPDATE`/`DELETE`, never a JSON-manipulation query
against an opaque blob.

**Filter schema**: Fambam-owned, versioned (a `schema_version` or
equivalent field so a future filter-shape change doesn't silently
misinterpret older saved searches), validated on write, entirely free of
SQL/`tsquery`/backend-specific syntax — the same discipline already
applied to the search abstraction itself (§11).

**Tenant and relational integrity**: both tables are ordinary Class C
tenant-owned tables — explicit `family_space_id`, `FORCE ROW LEVEL
SECURITY`, tenant isolation policy, matching every other table in this
project. `saved_searches` requires its own additive
`UNIQUE(id, family_space_id)` (the same pattern `media_uploads`,
`people`, and `face_observations` already received in prior phases,
required here because a new composite foreign key needs it).
`saved_search_people.(saved_search_id, family_space_id)` is a composite
foreign key against that constraint; `saved_search_people.(person_id,
family_space_id)` is a composite foreign key against `people`'s existing
`UNIQUE(id, family_space_id)`. `UNIQUE(saved_search_id, person_id)`
prevents the same Person being referenced twice within one saved search.

**Creator-privacy is enforced in addition to, not instead of, tenant
RLS.** RLS's `family_space_id` policy already guarantees tenant
isolation; it says nothing about "private to creator" within one tenant.
A saved search is scoped to its creator by an explicit application-layer
predicate (`created_by = current actor`) at the query/policy layer,
exactly the same two-layer shape ADR-0008 already uses for a `private`
Photo — tenant RLS establishes the outer boundary, an application-level
check establishes the narrower one within it.

**Execution-time revalidation, always.** Running a saved search never
trusts anything about access or existence as of when it was saved — it
re-derives the actor's *current* authorised universe (§9) fresh, every
time, and re-resolves every referenced Album/Event/tag/Person id against
*current* tenant state. A referenced Album/Event/tag that has since been
deleted, or a Person the actor can no longer see for any reason, is
silently treated as no longer part of the effective filter — the search
still runs with whatever remains valid, never errors in a way that
would confirm or deny the removed reference's prior existence, and never
silently re-grants access the actor no longer has.

**Family Space teardown.** `saved_searches` (with `saved_search_people`
following via its own ordinary intra-database FK cascade once the
`saved_searches` rows are deleted) must be added to
`FamilySpaceDeletionManager::teardown()`'s explicit per-table delete list
(§ Context) — `ON DELETE CASCADE` from `family_spaces` will never fire,
since that row is only ever status-flipped, never physically deleted.
This is not optional cleanup; without it, a deleted Family Space's saved
searches would remain live rows referencing a "deleted" tenant.

Functional, unpolished CRUD UI belongs to Phase 11; final UX (any future
sharing affordance) belongs to Phase 14.

### 16. Discovery: explicit relationship traversal, not recommendation

```text
Person  → Photos (approved PhotoPerson) → Albums → Events → Stories
          → other confirmed People appearing alongside them
Photo   → People (approved PhotoPerson) → Album(s) → Event → Stories
Album   → Photos → People in those Photos → Event (if linked)
Event   → Albums → Photos → confirmed People in those Photos
```

`FamilyEventQuery::forPerson()` already implements one edge of this graph
exactly this way; the rest are the same kind of query over already-
existing foreign keys and `photo_people`. No recommendation-style
traversal is introduced — every edge is a fact the family already
recorded by using the product normally, continuing this project's
standing preference for derived truth over maintained truth (ADR-0008
§7, ADR-0009 §11).

**Every traversal above composes the same authorized entry points §9
defines — a Discovery edge is never resolved by querying broadly and
filtering afterward.** A related entity, count, thumbnail, or Person
reference the actor is not independently authorized to see is omitted
from the traversal exactly as if it did not exist, at every hop, in both
directions (e.g. a Person's Discovery view of "other confirmed People
appearing alongside them" only ever surfaces a co-appearance the actor
could independently reach via an authorized Photo/Album/Event — never one
visible solely because Discovery itself skipped the check). `FPA-P11-S03`
owns implementing these traversals as functional endpoints/services,
fully authorized from that stage's first commit (§21).

### 17. Contributor and Guest search behaviour

Search never broadens an actor's existing authority — it is always the
intersection of what they can already reach and what matches the query.
Owner/Administrator/Member search the normal authorised Family-Space
universe. Contributor reaches only Photo/Album content already
independently granted (the same universe `PhotoQuery::visibleTo()`/
`AlbumQuery::visibleTo()` already compute for them). Guest reaches only
Event/Album content already admitted through existing Event admission/
Guest-participation rules, via the new Event-visibility query (§9).

**Where ADR-0008 §16 already denies Contributor/Guest Person-directory
access, People is not offered as a search axis for them**: no People
result group, no Person autocomplete, no Person filter/picker, no facet
or count disclosing the directory's existence — enforced by never
invoking the People sub-search for them (§9), not by running it and
discarding results. **This is narrower than redacting content they can
already see**: if a Contributor is independently authorised to open a
specific Photo whose own detail view already shows the People confirmed
in it, a Photo *search result* for that same Photo carries the same
field — nothing already visible on that Photo's own page is additionally
stripped from its search result. No parallel search-authorization model
is introduced anywhere in this section — every rule reuses an existing
policy/query scope.

### 18. Person-merge integration

Ordinary live search needs no new merge-integration work — it queries
`PhotoPerson.person_id`/`FamilyEventQuery` relationships directly, and
`PersonMergeManager` already atomically repoints `PhotoPerson.person_id`
during merge, so a merged Person's Photos/Events resolve correctly the
next time anyone searches.

**`saved_search_people` is the one genuine new obligation**, added to
`PersonMergeManager`'s existing `captureState()`/`restoreState()`
snapshot exactly as `face_identity_assignments`/`FaceIdentitySuppression`
already were in Phase 10:

- a row naming the absorbed Person is **repointed** to the survivor where
  doing so would not collide with `UNIQUE(saved_search_id, person_id)`;
- **where the same saved search already references both the absorbed
  Person and the survivor** (independently added before anyone knew they
  were the same person), repointing would violate that uniqueness — the
  now-redundant absorbed-side row is instead **deleted from the live
  table**, with its full pre-merge state preserved only in the merge
  snapshot, using exactly the same delete-then-reinsert-from-snapshot
  shape `PersonMergeManager::restoreState()` already implements for
  `family_circle_people`/`person_account_links` (and, in Phase 10, for
  `FaceIdentitySuppression`'s own analogous collision) — no new reversal
  shape is invented here;
- guarded reversal restores the original two references exactly as
  captured if the merge is still validly reversible, never inferred from
  the saved search's post-merge state alone.

**This integration must be introduced in the same stage that introduces
`saved_search_people` (§21)** — it is not deferred to a later stage,
exactly as ADR-0012's own reconciliation already established for face
identity. No search or autocomplete surface may present an absorbed
Person as an active, independent Person after merge — since absorbed
`people` rows are soft-deleted and every People query already excludes
soft-deleted Persons, this holds automatically once the
`saved_search_people` reconciliation above is in place.

### 19. Audit and telemetry

Ordinary searching is never audited — the same "automatic/routine
operation generates no archival record" pattern already established
repeatedly (ADR-0007 §17, ADR-0010 §11, ADR-0012 §14). Bounded
operational telemetry (latency, result counts per type, failure category)
is fine; **query text, Person names, story text, or any other family
content must never appear in logs, traces, or metric attributes.**
Saved-search create/update/delete is ordinary user data, like creating an
Album — not inherently audit-worthy; whether a future Family-Space-
sharing action for a saved search becomes audit-worthy is explicitly left
open (§ Deferred concerns), a Phase 12/14-adjacent product question this
ADR does not need to settle to be complete.

### 20. Scale and review triggers

A realistic Family Space carries tens of People/Albums/Events, thousands
of Photos, a handful of Stories per Photo at most — comfortably within
what `tsvector`+`pg_trgm`+B-tree indexes handle natively, per tenant,
with every query already tenant-scoped (§9). No dedicated search engine
is introduced without measured evidence. Review triggers: a single Family
Space's Photo corpus reaching the tens-of-thousands with measured query
latency degrading; a genuine multilingual-stemming requirement; query
complexity exceeding what composed relational filters can express;
measurable database contention from search load. No number is frozen
here without that evidence existing first.

### 21. Phase boundaries and stage ownership

Phase 11 owns structured/text search, typed grouped results, filters,
autocomplete, saved searches, wiki-style navigable Discovery, and
functional UI for all of the above. Phase 12 owns the activity/feed
experience, building on — never redesigning — §3's typed, navigable
entity contract, without this ADR assuming `AuditEvent` is that feed's
data source (audit and family activity solve different problems; Phase
12 decides). Phase 14 owns final product UI/UX integration. Phase 18 owns
semantic/visual image-content understanding — no embedding, model, or
visual-similarity mechanism is introduced here.

**Authorization-before-disclosure is not a later-stage concern — it must
exist from the first searchable endpoint, not be added after.**
Corrected stage ownership, replacing the original draft's sequencing:

- **`FPA-P11-S02`** (first searchable endpoints) already owns: the
  `PhotoQuery::visibleTo()`/`AlbumQuery::visibleTo()`-composed,
  fully-authorized Photo/Album/Story search (Story via Photo, §8); the
  generated `search_vector`/`historical_date_window_end` columns and
  `pg_trgm` provisioning; the bounded typed summaries (§3) and
  deterministic pagination/ordering (§12, §13) for these domains; and the
  narrow `SearchService` interface (§11). It does **not** ship an
  unauthorized or partially-authorized search endpoint of any kind, for
  any domain it introduces.
- **`FPA-P11-S03`** (People, Events, combined filters, and Discovery
  traversal) adds the People search axis, the new Event-visibility query
  (§9), multi-Person intersection, and Event/Person/date combinations —
  **and, because it depends on exactly these same domain relationships,
  also implements the functional Discovery endpoints/services for every
  traversal §16 defines** (Person→Photos→Albums→Events→Stories→other
  People; Photo→People→Albums→Event→Stories; Album→Photos→People→Event;
  Event→Albums→Photos→People). Every axis and every Discovery traversal
  added in this stage is fully authorized from the moment it is
  introduced, using the same discipline S02 already established, never as
  a separate later hardening pass. Discovery correctness and security are
  established here, in full — not deferred to `FPA-P11-S05`.
- **`FPA-P11-S04`** (saved searches) atomically introduces
  `saved_searches`, `saved_search_people`, their RLS/tenant-consistency,
  the versioned filter schema, execution-time revalidation (§15), **and**
  the full Person-merge capture/reconcile/guarded-reversal integration
  (§18) and Family Space teardown integration (§15) in the same stage —
  none of this is deferred to `FPA-P11-S05`.
- **`FPA-P11-S05`** (performance and regression) adds indexing/
  performance evidence, broader authorization-leak regression coverage —
  including Discovery-traversal visibility-leak testing across every edge
  §16 defines — and ranking/pagination stress testing; it is explicitly
  **not** the stage at which correctness or security is first established
  for anything introduced in an earlier stage, Discovery included.

## Alternatives considered

- **A denormalized `search_documents` projection table** — rejected;
  reasoning in §10, unchanged from the original draft.
- **Naive point-in-range date filtering, and the original draft's
  `date + 9 years, 11 months, 29 days` decade-end formula** — both
  rejected: the former silently excludes legitimate matches for a
  narrower query against an imprecisely-dated Photo; the latter is
  simply arithmetically wrong (one day short of the correct decade end)
  and unnecessarily manual compared to calendar-safe
  `(start + N unit) - 1 day` arithmetic, which Postgres already computes
  correctly across month lengths and leap years.
- **Inventing an uncertainty radius for `approximate` dates** — rejected:
  the current `UncertainDate` model has no such concept; this ADR
  describes existing behaviour honestly rather than adding fuzziness the
  domain model doesn't actually have.
- **Copying tag labels into `photos.search_vector`** — rejected (§7,
  §10): reintroduces exactly the duplicated, driftable text the
  no-projection decision exists to avoid; tags stay a relational signal.
- **Claiming `FamilyEventQuery`/`PersonPolicy::hasDirectoryAccess()` as
  already-sufficient authorization primitives** — the original draft's
  error, corrected in §9: verified by direct inspection that neither
  claim was true, and the ADR now names the real boundaries and the one
  genuinely new query this phase must build.
- **Reusing existing controller `payload()` methods verbatim for search
  results** — rejected (§3): those are private, richer than search needs,
  and not designed to be reused as a public search contract; small,
  dedicated, bounded summary types achieve the same navigability goal
  without the over-exposure risk.
- **Returning every matching row from every result group in one response**
  — rejected (§13): unbounded response size with no continuation
  semantics; each group is independently paginated instead.
- **Assuming an existing repository-wide pagination convention could be
  reused** — the original draft's error, corrected in §13: direct
  inspection found no `paginate()`/`simplePaginate()`/`cursorPaginate()`
  usage or standardized page-size/continuation contract anywhere in
  `apps/api/app`; Phase 11 establishes this convention for grouped search
  results itself, rather than reusing something that already existed.
- **Page-number/offset pagination for grouped search results** —
  rejected (§13): offset pagination silently skips or repeats rows as new
  matching content is created between requests in a live, growing family
  archive; cursor-style continuation keyed to the existing deterministic
  sort order (§12) avoids this without a second, independent sort
  mechanism.
- **Leaving Discovery without an explicit implementation-stage owner** —
  the original draft's gap, corrected in §21: Discovery traversals depend
  on exactly the same People/Event/combined-relationship work
  `FPA-P11-S03` already owns, so ownership is assigned there rather than
  left implicit or silently deferred to a later stage.
- **One universal numeric ranking score across all signals and domains**
  — rejected (§12): `ts_rank`, trigram `similarity()`, and relational tag/
  Person matches are not comparable on one scale; a precedence-class
  model is used instead.
- **Storing Person references inside the saved-search filter JSON** —
  rejected (§15): makes merge-repointing a JSON-manipulation query
  instead of a plain relational `UPDATE`/`DELETE`, breaking with every
  other Person-referencing table in this project.
- **Relying on `ON DELETE CASCADE` from `family_spaces` for saved-search
  teardown** — rejected (§15): confirmed by direct inspection that
  `family_spaces` is never physically deleted, so that cascade would
  never fire; explicit inclusion in `FamilySpaceDeletionManager`'s
  existing per-table delete list is required instead.
- **Deferring saved-search Person-merge integration to the performance/
  regression stage** — rejected (§21): would let merge-unsafe records
  exist, even briefly, exactly the ordering ADR-0012's own reconciliation
  already rejected for face identity.
- **Choosing a dedicated search engine now, in anticipation of future
  scale** — rejected (§20): no measured workload justifies it.
- **A natural-language query parser** — rejected throughout: every
  combined-query example is a plain relational filter composition.

## Consequences

### Positive

- Verifying every authorization claim against the live repository, rather
  than assuming symmetry across domains, surfaced a real, concrete gap
  (Event visibility) before any code was written against a false
  assumption.
- The corrected date-window arithmetic (§6) is both more correct and
  simpler to implement than the original — one formula
  (`(start + N) - 1 day`) covers month/year/decade uniformly, with no
  per-case leap-year/month-length logic to get wrong.
- Bounded, purpose-built summary types (§3) and independent per-group
  pagination (§13) keep response size and exposure deliberately small,
  rather than trusting an ad hoc reuse of richer endpoints to happen to
  be safe.
- Normalizing Person references out of saved-search filter JSON (§15)
  means merge integration is a plain relational operation, consistent
  with every other Person-referencing table in this project, not a
  special case.
- Fixing stage ownership so authorization is never a later add-on (§21)
  removes an entire class of "insecure-then-patched" risk before
  implementation begins.

### Negative

- A new `FamilyEventQuery::visibleTo()`-equivalent is real, unbudgeted
  work this ADR adds that the original draft assumed was already done.
- Five new/extended `search_vector` (and one `historical_date_window_end`)
  generated columns, plus `pg_trgm` indexes and two new saved-search
  tables with their own composite constraints, is a larger schema
  footprint than the original draft's description implied.
- Independent per-group pagination and a precedence-class ranking
  contract are more moving parts on the frontend than a single flat
  ranked list would be.

### Risks

- If any search surface is ever implemented as "query broadly, then
  filter/redact," that is a direct existence-disclosure leak — the same
  failure mode ADR-0010 §3 already named — worth a direct test per
  domain, including the new Event-visibility query specifically.
- If the new Event-visibility query is implemented by checking
  `EventAccess::hasValidAdmission()` per row after an unfiltered fetch,
  rather than as a genuine set-level predicate, large Event lists could
  leak existence via timing or partial-result behaviour even if final
  output looks correct — worth a direct test asserting the query itself,
  not post-hoc filtering, excludes inadmissible Events.
- If a Discovery traversal is ever implemented by resolving an anchor
  entity's full related-entity set and filtering visibility afterward,
  rather than composing each hop through its domain's authorized query
  from the start (§9), the same existence-disclosure leak applies at
  every hop, not just the first — worth a direct test asserting each
  hop's query itself excludes unauthorized rows, not post-hoc filtering.
- If a search cursor is ever implemented to encode a raw row id or
  database offset instead of the sort-key values themselves, deleting or
  de-authorizing the anchor row would either error the next page request
  or silently skip a page boundary — worth a direct test resuming
  pagination after the anchor row becomes inaccessible.
- If `photos.search_vector` is ever implemented to include tag text "for
  better ranking," the no-duplicated-text guarantee is silently broken —
  worth a direct test asserting a tag rename never changes any Photo's
  `search_vector`.
- If `saved_search_people`'s merge-collision path is implemented as
  "repoint and let the unique constraint fail" rather than
  detect-and-delete-with-snapshot, the merge transaction breaks instead
  of completing — worth the same constructed-collision test ADR-0012
  §12 already required for `FaceIdentitySuppression`, applied here.
- If `saved_searches`/`saved_search_people` are not added to
  `FamilySpaceDeletionManager::teardown()`'s explicit delete list, they
  survive a deleted Family Space indefinitely — worth a direct teardown
  test asserting zero rows remain afterward.
- If execution-time revalidation (§15) is skipped and a saved search
  instead trusts filter state resolved at save-time, a saved search could
  either leak the prior existence of now-inaccessible content or silently
  regrant access the actor has since lost — worth a direct test running
  a saved search after the actor's access to one of its referenced
  entities has been revoked.
- If stage ownership drifts back toward "ship search first, secure it
  later" during implementation despite §21, the same disclosure risk
  above applies to every endpoint shipped in that window.

## Implementation notes

- **`FPA-P11-S02`** implements §6, §7, §8, §9 (Photo/Album/Story only),
  §10, §11, §12, §13, per §21's corrected ownership — fully authorized
  from first commit, no unauthorized intermediate state.
- **`FPA-P11-S03`** implements §5, §9 (the new Event-visibility query),
  §14, §16 (Discovery endpoints/services for every traversal it defines),
  §17 — fully authorized from first commit for every axis and every
  Discovery traversal it adds.
- **`FPA-P11-S04`** implements §15 and §18 together, atomically, per
  §21 — `saved_searches`/`saved_search_people`, their integrity
  constraints and RLS, execution-time revalidation, Family Space teardown
  integration, and the full Person-merge integration in one stage.
- **`FPA-P11-S05`** implements the performance/regression breadth this
  ADR requires, never introducing new correctness/security behaviour for
  the first time.
- **Required regression tests**: (1) a search/count/facet/autocomplete
  result for content an actor cannot see must be indistinguishable from
  no match, for every result type including the new Event-visibility
  query; (2) exact/month/year/decade window boundaries must be verified
  across a leap-year February, a 31-day December, and a decade spanning a
  leap year, using the corrected `(start + N) - 1 day` formula; (3) a
  narrower query period must still match a broader-precision Photo whose
  implied window overlaps it (decade-precision Photo matching a
  single-year query); (4) `approximate`-precision Photos must behave as a
  one-day anchor identically to `exact`; (5) `unknown`-precision Photos
  must never match any date filter and must never be matched via
  `created_at`; (6) a `pending` `PhotoPerson`/any `FaceIdentityAssignment`
  status must never cause a Photo to match a Person search; (7) renaming
  or detaching a tag must never change any Photo's `search_vector`; (8) a
  Contributor/Guest search response must omit the People group entirely
  (not return it empty), and Person autocomplete must return nothing for
  them; (9) a Photo/Album/Event a Contributor/Guest can already
  independently open must show identical Person-name fields in a search
  result as on its own detail page; (10) a Guest with a currently-valid
  `EventAdmission` must see that Event in Event search, and a Guest
  without one must not, verified via the new query directly; (11) a
  PhotoStory must never appear in search results for a Photo the actor
  cannot see, even when the Story's own text exactly matches the query;
  (12) comments/reactions must never appear in global search under any
  query; (13) a saved search referencing both an absorbed and a survivor
  Person must collapse to one reference on merge, and reversing that
  merge must restore both original references from the snapshot; (14) a
  saved search referencing an Album/Event/tag/Person the actor has since
  lost access to must run safely and deterministically, never leaking
  the prior existence of the now-inaccessible reference; (15) deleting a
  Family Space must leave zero `saved_searches`/`saved_search_people`
  rows; (16) requesting the next page of one result group must not
  require or return the other four groups, and must never repeat or skip
  a row given the fixed ranking/pagination contract; (17) no search query
  text, Person name, or story/caption content may appear in any log line,
  span attribute, or metric label; (18) a Discovery traversal from a
  Person, Photo, Album, or Event must never include a related entity,
  count, thumbnail, or Person reference the actor is not independently
  authorized to see — verified by traversing from an authorized anchor
  entity toward a related entity the actor cannot see and asserting it is
  absent, not merely unlinked or empty; (19) a Discovery traversal must
  behave identically — indistinguishable from non-existence — whether the
  invisible related content is excluded by tenant boundary, by
  Contributor/Guest restriction, or because the actor cannot see a
  Person in the People directory; (20) a next-page cursor for any search
  result group must resume correctly, with no row skipped or repeated,
  even when a row between the two requests was deleted or became
  inaccessible to the actor; (21) an invalid or undecodable cursor must
  be rejected as a validation error, never silently treated as the first
  page.
- §9's authorization-composition discipline, §16's Discovery traversals,
  and §18's Person-merge reconciliation are cross-cutting: every stage
  above should be checked against all three during review.

## Review triggers

- **When Phase 12 (Memories and Family Homepage) is scoped**: consume
  §3's typed entity contract directly; decide the feed's own data
  model/event source without assuming `AuditEvent` is it.
- **When Phase 14 (Product UI/UX) is scoped**: design final search/
  Discovery visual treatment, saved-search sharing UX if introduced, and
  Contributor/Guest-appropriate empty-state wording for the omitted
  People group.
- **When Phase 18 (Semantic Image Search) is scoped**: decide whether its
  results merge into this ADR's typed result groups or are presented
  separately.
- **If a Family Space's Photo corpus, measured query latency, ranking
  complexity, or multilingual requirements exceed what Postgres
  comfortably handles (§20)**: revisit a dedicated engine, always as a
  rebuildable projection, never a second source of truth.
- **If saved searches are ever made shareable within a Family Space**:
  revisit whether that sharing action becomes audit-worthy, and whether
  `saved_search_people`'s creator-privacy predicate needs adjustment for
  a non-creator viewer.
- **If real usage shows the Event-visibility query's Guest-admission
  logic and `EventAccess`'s existing per-Event checks drift apart over
  time**: consolidate them into one shared predicate rather than letting
  two implementations of "does this Guest have valid admission" diverge.
- **If another list endpoint in the repository later needs bounded
  continuation**: evaluate reusing this ADR's opaque, sort-key-encoded
  cursor convention rather than inventing a third pattern — today the API
  has none at all, so this ADR's is the first, not necessarily the last.

## Deferred concerns

- Exact physical schema, column types, and index definitions for every
  generated column and both new saved-search tables — the shape is
  fixed, the columns are not.
- Exact text-search configuration (language/tokenizer, `setweight`
  weights, trigram similarity thresholds) and exact page-size/cursor
  encoding — implementation detail informed by real content and
  measurement, not frozen here.
- The exact form of the new `FamilyEventQuery::visibleTo()`-equivalent
  method — its required behaviour is fixed (§9), its exact signature and
  whether it lives on `FamilyEventQuery` itself or a new class is
  implementation detail.
- Whether saved searches ever become Family-Space-shareable, and the
  audit/privacy implications of that.
- Whether this ADR's cursor-based pagination convention becomes a
  reusable precedent for other list endpoints, or remains search-specific
  — left to whichever future ADR first needs bounded continuation
  elsewhere.
- Phase 12's activity-feed data model and event source — explicitly not
  decided here.

## Resolved decisions

1. **Scope** — structured/textual discovery over explicit family facts
   only; no image-content understanding.
2. **Search shape** — one global entry point, results grouped by real
   domain type, no universal cross-type ranking.
3. **Result contract** — dedicated, bounded, typed per-domain search
   summaries carrying real domain ids, never a flattened `{title, url}`
   shape and never a literal reuse of richer private endpoint payloads;
   this is Phase 11's affordance for Phase 12's future activity feed.
4. **Filters vs. free text** — structured filters for Person/date/Album/
   Event/tag/contributor/visibility; free-text matching for explicit
   textual metadata only; no natural-language parser.
5. **People-aware search** — only `approved` `PhotoPerson` rows are
   searchable fact.
6. **Date semantics** — calendar-safe `(start + N unit) - 1 day` window
   arithmetic per precision, interval-overlap matching, `approximate`
   honestly treated as a one-day anchor (no invented uncertainty radius),
   `unknown` never matches a date filter, `created_at` never substituted.
7. **Location/tags** — free-text/trigram location matching only; tags are
   a relational filter/autocomplete/ranking signal and are never copied
   into any table's generated `search_vector`.
8. **Stories/comments** — PhotoStories are searchable strictly via their
   owning Photo's authorization (no independent Story-level check
   exists); comments/reactions are excluded from Phase 11 global search.
9. **Authorization entry points** — `PhotoQuery::visibleTo()` and
   `AlbumQuery::visibleTo()` are reused as-is; a new
   `FamilyEventQuery::visibleTo()`-equivalent is required and must be
   built before Event search ships; People search/autocomplete gates on
   the public `PersonPolicy::viewAny` boundary, never the private
   `hasDirectoryAccess()` helper; tag disclosure requires actor-visible
   Photo attachment, not tenant-wide existence.
10. **Persistence model** — normalized live tables with same-row
    generated `tsvector`/date-window columns and `pg_trgm`; no
    denormalized projection.
11. **Search abstraction** — a narrow Fambam-owned interface; a future
    dedicated engine, if ever adopted, is a rebuildable projection only.
12. **Ranking** — a deterministic precedence-class contract (match class,
    within-class score, domain tie-breaker, id tie-breaker), never one
    universal numeric scale across signals or domains.
13. **Pagination** — Phase 11 establishes the repository's first explicit
    bounded pagination/continuation convention, scoped to grouped search
    results: each typed group returns an independently, deterministically
    ordered page with an opaque, existence-safe continuation cursor;
    unbounded full-corpus responses are never returned; no existing
    repository-wide convention is reused, because none exists.
14. **Autocomplete** — narrow, authorization-scoped for People/Albums/
    Events/tags, gated by the same entry points as full search.
15. **Saved searches** — creator-owned, Family-Space-scoped, private in
    Phase 11; normalized versioned filter state with Person references
    normalized into `saved_search_people`; tenant-consistent composite
    FKs and a supporting `saved_searches` `UNIQUE(id, family_space_id)`;
    creator-privacy enforced as an application-layer predicate atop RLS;
    execution-time revalidation of all authorization and referenced-
    entity existence, always; explicit inclusion in Family Space
    teardown, since `ON DELETE CASCADE` from `family_spaces` never fires.
16. **Discovery** — explicit relationship traversal only, no
    recommendation-style mechanism; implemented as functional endpoints/
    services in `FPA-P11-S03`, fully authorized from that stage's first
    commit, never deferred to `FPA-P11-S05`.
17. **Contributor/Guest search** — always the intersection of existing
    authority and the query; People is not an available search axis for
    them; already-authorised content is never additionally redacted.
18. **Person-merge integration** — live search is merge-correct for free
    via `PhotoPerson`; `saved_search_people` joins `PersonMergeManager`'s
    existing capture/reconcile/guarded-reversal transaction, including a
    detect-and-delete-with-snapshot rule for the same-search-references-
    both-sides collision, introduced in the same stage that introduces
    `saved_search_people`, never deferred.
19. **Audit/telemetry** — ordinary searching is never audited; no family
    content in logs/traces/metrics; saved-search CRUD is ordinary user
    data.
20. **Scale** — Postgres-native, tenant-scoped throughout; a dedicated
    engine is a measured-evidence review trigger, not a default.
21. **Stage ownership** — authorization-before-disclosure is established
    in the first stage that ships any searchable endpoint or Discovery
    traversal for a given domain, never added afterward; Discovery
    traversal endpoints/services are explicitly owned by `FPA-P11-S03`,
    alongside the People/Event/combined-filter work they depend on;
    saved-search Person-merge integration ships atomically with
    `saved_search_people`, never deferred to the performance/regression
    stage.
