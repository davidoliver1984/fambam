# Frontend Engineering Standards

> **Purpose:** Durable, repeatable conventions for `apps/web` — how the
> frontend is structured, how it talks to the API, and how new code should
> look regardless of who (or what) writes it.
>
> This document does not redecide architecture. ADR-0002 fixed the stack
> (React, TypeScript, Vite); ADR-0004 fixed the authentication model
> (Fortify, Sanctum SPA cookie sessions, invitation-only account creation)
> the frontend must implement. This document is downstream of both: it says
> *how* to build within those decisions, not *what* to build. Where a rule
> exists purely to satisfy ADR-0004 (invitation-token handling,
> password-manager compatibility, no CAPTCHA), that's noted explicitly
> rather than presented as an independent style preference.

## 0. Purpose and scope

Applies to `apps/web` today and any future frontend surface this project
adds. It sits alongside `CONTRIBUTING.md` (repo-wide conventions) and
`docs/ENGINEERING_METHODOLOGY.md` (process) without duplicating either.

This is a plain engineering-standards document, not an ADR. Per
`CONTRIBUTING.md`'s own criteria for when an ADR is warranted, what follows
(folder layout, which library owns which state, naming) is a set of
implementation conventions with low reversal cost, not durable system
boundaries — those already went through ADR-0002 and ADR-0004. Nothing here
overrides either.

### Adopting new dependencies

**Rule:** a library named in this document (React Router, React Hook Form,
Zod, MSW, `eslint-plugin-jsx-a11y`) is installed in the same bounded change
that first exercises and tests it — never speculatively, ahead of a
concrete consumer.

**Rationale:** this document selects which tool to reach for when the need
arises; it does not license installing the full toolset up front. An
installed-but-unused dependency is the same problem as an empty scaffold
folder (§1, §12), just at the package level.

**Good example:** React Router is added in the change that first introduces
a real protected route beyond a single page's own access check.

**Avoid:** adding all five packages above in one preparatory commit before
any of them has a real consumer.

**Exceptions:** none — this includes `eslint-plugin-jsx-a11y`; when it's
added, it's wired into `eslint.config.js` and actually run in that same
change, not installed and left dangling.

---

## 1. Source directory structure

### Rule: feature-oriented layout, `src/api/` for shared transport

```text
apps/web/src/
├── api/
│   └── client.ts          # shared transport only: base config, credentials, CSRF bootstrap
├── features/
│   └── <feature>/
│       ├── api/            # endpoint functions + this feature's query-key factory
│       │   ├── <feature>Api.ts
│       │   └── <feature>Keys.ts
│       ├── hooks/           # query/mutation hooks (useXQuery, useXMutation)
│       ├── components/      # feature-specific presentational components
│       ├── pages/            # route-level orchestration components
│       ├── types/            # domain/DTO types for this feature
│       └── validation/       # Zod schemas, once the feature has one
├── components/                # app-wide, non-feature-specific only
├── hooks/                      # app-wide hooks only
├── test/                        # shared test setup / MSW handlers, once introduced
└── assets/
```

This tree is a **responsibility model, not a checklist**: it says what a
subfolder is *for* if it exists, not that every feature must have every
subfolder immediately. `features/auth/` having only `api/` today because
its pages haven't moved out of a shared file yet, or `features/invitations/`
having `api/`, `hooks/`, `components/`, `pages/` and `types/` because it
needs all of them, are both correct — the shape follows what each feature
actually contains.

**Rationale:** feature ownership over generic dumping-ground directories
means a contributor working on invitations only needs to look inside
`features/invitations/` — API calls, hooks, pages, types and validation for
that feature live together.

**Good example:** `features/invitations/api/invitationApi.ts`,
`features/invitations/hooks/useAcceptInvitationMutation` (in
`useInvitationMutations.ts`), `features/invitations/pages/InvitationAcceptancePage.tsx`
— one feature, one place.

**Avoid:** a top-level `src/components/InvitationList.tsx` sitting next to
unrelated components from other features.

**Exceptions:** genuinely cross-cutting UI or hooks (used by two or more
unrelated features) belong at the top level, in `components/` or `hooks/`.

### Rule: a subfolder exists only when it holds a real file

**Rationale:** pre-scaffolding a full folder set for every feature "for
consistency" produces directories nobody fills in, which read as
unfinished work and give no signal about what's actually in a feature.

**Good example:** `features/auth/` has no `validation/` folder until the
first Zod schema actually exists for it.

**Avoid:** creating `features/x/{api,components,hooks,pages,types,validation}/`
up front for a feature that currently only needs two of them.

**Exceptions:** none.

---

## 2. HTTP and API boundaries

### Rule: pages and components never call the HTTP client directly

Only feature API modules (`features/x/api/*Api.ts`) import `apiClient`.
Pages and components call query/mutation hooks.

**Rationale:** this is the rule that most determines whether API logic
stays in one place or gets duplicated across screens.

**Good example:**
```ts
// features/invitations/api/invitationApi.ts
export async function acceptInvitation(input: AcceptInvitationInput): Promise<AcceptedAccount> {
  await ensureCsrfCookie();
  return unwrap(await apiClient.post<ApiEnvelope<AcceptedAccount>>("/api/invitations/accept", input));
}
```
```tsx
// features/invitations/components/InvitationAcceptanceForm.tsx
const acceptInvitation = useAcceptInvitationMutation();
```

**Avoid:** `apiClient.post(...)` called directly inside a page or component.

**Exceptions:** none.

### Rule: `src/api/client.ts` owns transport configuration only

Base URL, `withCredentials`, `withXSRFToken`, and the Sanctum CSRF-cookie
bootstrap live here. No endpoint-specific logic, no business types.

**Good example:**
```ts
// src/api/client.ts
export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? "http://localhost:8082",
  headers: { Accept: "application/json" },
  withCredentials: true,
  withXSRFToken: true,
});

export async function ensureCsrfCookie(): Promise<void> {
  await apiClient.get("/sanctum/csrf-cookie");
}
```

**Target refinement:** every mutating API-module function currently calls
`ensureCsrfCookie()` itself before its request. That's correct in spirit —
it keeps the requirement close to the code that needs it — but as more
mutations are added, consider whether the client should guarantee a fresh
CSRF cookie once per session (e.g. via a request interceptor) rather than
relying on each function to remember the call. Not required until the
per-function pattern is shown to actually cause a bug or a real duplication
cost.

**Exceptions:** none for business data.

### Rule: endpoint functions and that feature's query-key factory both live in `features/x/api/`

An endpoint path exists as a string literal in exactly one place. Each
feature owns its own key factory (`accountKeys.ts`, `invitationKeys.ts`) —
there is no single central factory file, since a shared file would become a
cross-feature bottleneck with no corresponding benefit; a feature's keys
are only ever consumed by that feature's own hooks.

**Good example:**
```ts
// features/invitations/api/invitationKeys.ts
export const invitationKeys = {
  all: ["invitations"] as const,
  list: () => [...invitationKeys.all, "list"] as const,
  claim: () => [...invitationKeys.all, "claim"] as const,
};
```

**Avoid:** `/login`, `/api/user` appearing as string literals inside a page
component; a query key constructed inline instead of via the feature's key
factory.

**Exceptions:** none.

### Rule: request/response types are explicit; Laravel envelopes are unwrapped in the API layer

Every API-module function has a typed return value. Laravel's `{ data:
... }` envelope is unwrapped inside the module, never in a page.

**Target refinement:** `ApiEnvelope<T>` and the `unwrap<T>` helper that
reads `response.data.data` are currently defined separately (and
identically) in more than one feature's API module. Once a second feature
needs the same envelope shape, extract this into one shared helper in
`src/api/` (e.g. `src/api/envelope.ts`) rather than continuing to
copy-paste it — this is exactly the kind of duplication §12 exists to
prevent, and is a small, low-risk extraction once it's worth doing.

**Avoid:** a page reading `response.data.data` directly; an inline
`apiClient.get<{ data: User }>(...)` generic at the call site instead of a
named return type on a dedicated function.

### Rule: transport errors are normalized once, not per call site

A shared error-normalization helper (e.g. `toAppError`) and a Laravel
field-error mapper (`toLaravelFieldErrors`) belong in `src/api/`, imported
by every API-module function that needs to turn a caught error into
something a form or a page can render — not reimplemented per call site.

**Rationale:** an opt-in-per-call-site helper is the kind of thing that
gets forgotten somewhere eventually. Making it structural — reached for by
convention because it's the obvious shared thing to import — removes that
risk.

**Good example (target shape):**
```ts
// src/api/errors.ts
export function toAppError(err: unknown): AppError { /* narrow + normalize */ }
export function toLaravelFieldErrors(err: unknown): Record<string, string> { /* extract 422 fields */ }
```

**Exceptions:** none.

### Rule: generated API types / shared contracts — deferred, not decided against

**Rationale:** the Laravel API surface is still Phase 2-sized. Generated
types (from an OpenAPI schema, or a shared package sourced from
`contracts/`) now would be tooling built ahead of a real multi-consumer
contract problem — a speculative abstraction per §12. Hand-written DTOs
colocated per feature API module are the standard until this is revisited.

**Review trigger:** once `contracts/http` holds a real, evolving surface
(post-Phase 3/4), revisit whether generated types are worth the cost.

---

## 3. Server state with TanStack Query

TanStack Query owns server state. React local state (`useState`/
`useReducer`) owns transient UI state (form input before submit, a tab
selection, whether a menu is open). Context is reserved for genuinely
cross-cutting client state — **the current user is server state** and is
read through a query hook, not reimplemented as a hand-managed state
machine. A global client-state library (Redux/Zustand/Jotai) is not
introduced without a demonstrated requirement.

### Rule: TanStack Query is the only mechanism for reading or writing server state

**Good example:**
```ts
// features/account/hooks/useCurrentUserQuery.ts
export function useCurrentUserQuery() {
  return useQuery({ queryKey: accountKeys.current, queryFn: getCurrentUser, retry: false, staleTime: 30_000 });
}
```

**Avoid:**
```tsx
useEffect(() => {
  apiClient.get("/api/user").then(setUser);
}, []);
```

**Exceptions/clarification:** reacting to *already-fetched* query state
with a side effect — for example, redirecting when `useCurrentUserQuery`
reports an error — is not the same thing as fetching data through
`useEffect`, and remains acceptable. The prohibition is specifically on
`useEffect` performing the fetch itself.

### Rule: query keys come only from the owning feature's key factory

**Avoid:** `useQuery({ queryKey: ["invitations"], ... })` written inline
instead of `invitationKeys.list()`.

### Rule: query/mutation hook naming and placement

Query hooks: `useXQuery`. Mutation hooks: `useXMutation`. Both live in the
owning feature's `hooks/` folder. A file may hold one hook or a small group
of closely related mutations (e.g. issuing and transitioning an invitation
sharing the same invalidation target) — prefer one hook per file as the
default, but grouping tightly related hooks in one file is acceptable
rather than something to split apart for its own sake.

### Rule: every mutation that changes server state is a `useMutation`, not a bare async function called from a page

**Rationale:** this is the one place mutation ownership is currently
inconsistent — some feature mutations are correctly wrapped in
`useMutation` (getting `isPending`/`isError` state and cache invalidation
for free); others are called as plain `async` functions from inside a
page's own `try`/`catch`. The rule is the same regardless of which feature:
if it changes server state, it's a mutation hook.

**Good example:**
```ts
export function useUpdateProfileMutation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: UpdateProfileInput) => updateProfile(input),
    onSuccess: (user) => { queryClient.setQueryData(accountKeys.current, user); },
  });
}
```

**Avoid:**
```tsx
try {
  await login({ email, password, remember });
  window.location.assign("/account");
} catch {
  setMessage("We could not sign you in.");
}
```
called directly inside a page component's submit handler.

**Exceptions:** none.

### Rule: cache invalidation and seeding happen in `onSuccess`

**Good example:**
```ts
onSuccess: async () => { await queryClient.invalidateQueries({ queryKey: invitationKeys.list() }); }
```

### Rule: optimistic updates only for low-risk, easily-revertible changes

Security-sensitive mutations (login, invitation accept/revoke, account
revocation) wait for the server response before the UI reacts — the server
is authoritative for these, per ADR-0004's own model of what "accepted"
means (a row-locked, atomic transition).

### Rule: query functions accept and forward cancellation

`queryFn: ({ signal }) => fetchX(signal)`, forwarded into the underlying
`apiClient` call's own `{ signal }` option. Required on every query.

### Rule: loading, error, empty and success states are handled consistently and accessibly — not necessarily through one universal component

**Rule:** every query consumer distinguishes and accessibly announces its
loading, error, empty and success states. A shared presentational
primitive may be used where its shape genuinely fits; a feature-specific
skeleton or empty-state message is equally valid where the shared one
doesn't. The requirement is consistent, accessible handling — not routing
every consumer through one mandatory component.

**Rationale:** forcing every screen through a single status component
tends to produce either a component stretched to cover cases it wasn't
designed for, or pages that quietly stop using it because it doesn't fit —
neither outcome is actually more consistent than clear per-feature handling
that follows the same underlying rules (a loading state is announced, not
just visually implied; an error state uses `role="alert"`; an empty state
has explicit copy, not a silently empty list).

**Good example (either is acceptable):**
```tsx
{invitationsQuery.isPending && <p>Loading invitations…</p>}
{invitationsQuery.isError && <p role="alert">Invitations could not be loaded.</p>}
```
or a shared `<QueryStatus>` primitive, where introduced, wrapping the same
three states for a screen whose shape fits it.

**Avoid:** a query result rendered with no loading indicator and no error
handling at all — a silently blank screen while pending, or a swallowed
error with no user-visible feedback.

**Exceptions:** none — every query consumer needs *a* correct, accessible
answer for each state; it just doesn't have to be the same component.

### Rule: pagination is an explicit param object inside the query key

**Good example:** `queryKey: [...invitationKeys.list(), { page, perPage }]`.

---

## 4. Pages and components

### Rule: pages are orchestration/composition boundaries

A page calls its feature's query/mutation hooks, assembles components, and
renders. It contains no direct HTTP calls, no inline validation logic, and
minimal branching in JSX.

### Rule: feature components are typically presentational, driven by props

**Good example:** an invitation list component receiving
`invitations: Invitation[]` and an `onRevoke` callback, with no knowledge
that a mutation hook exists behind it.

### Rule: shared/app-wide components carry no feature knowledge

`components/*` never imports from `features/*` and never references an
endpoint, a query key, or a domain type specific to one feature.

### Rule: export convention — named exports throughout, including pages

**Rationale:** matches this codebase's actual, consistent current practice.
Default-exporting page components is sometimes recommended purely for
`React.lazy()` ergonomics, but that only matters once route-level code
splitting is actually introduced — until then, named exports everywhere
are simpler to grep for and rename safely.

**Good example:** `export function InvitationAcceptancePage() { ... }`.

**Exceptions:** if `React.lazy()`-based code splitting is introduced later,
default-exporting the specific page components that are lazy-loaded is a
reasonable, narrow exception at that point — not a reason to switch the
whole codebase's export convention pre-emptively now.

### Rule: extract a hook when logic is reused or grows past a few lines; extract a component when JSX is repeated or has its own clear identity

**Avoid:** a single page component mixing data-fetching orchestration, list
rendering, and a full form implementation with no extraction at all once
any of those three grows past a screenful.

### Rule: no business logic or transport/endpoint knowledge in JSX

Derived values and conditionals live above the `return`, in a hook, or in a
typed helper — not as inline expressions computing business meaning deep
inside markup.

**Avoid:** re-deriving "is this invitation actually expired" inline in JSX
from `expires_at` and `status` — ADR-0004 §8 makes `expires_at` the
authority for that determination; that logic belongs in one named,
testable helper, not reimplemented wherever it's displayed.

---

## 5. Forms and validation

### Rule: React Hook Form + Zod for forms with real validation complexity or that are security-sensitive; plain native-HTML validation remains acceptable for simple forms

**Rationale:** a form warrants React Hook Form + Zod when it has cross-field
validation (a password/confirmation match), a policy that needs to be
shared and reused across more than one form (ADR-0004's password-length
rule), or meaningfully complex/conditional fields. A form with a single
required field and no cross-field logic doesn't need a schema library just
to have one. This is a judgment call per form, not a blanket requirement —
apply the criteria above rather than defaulting either way automatically.

**Good example — currently a native form, and reasonably so:**
`ForgotPasswordPage`'s single required email field has no cross-field
validation and no shared policy to encode; native HTML validation is
sufficient.

**Good example — a form that should move to RHF + Zod:** invitation
acceptance collects a password and a confirmation with no client-side check
that they match, and shares a password-length policy (15 characters, per
ADR-0004) that should live in one place rather than being re-expressed as a
`minLength` attribute wherever a password field appears. This is the
concrete case that should drive introducing React Hook Form + Zod, per the
dependency-timing rule in §0 — in the bounded change that adds that
cross-field check, not before.

### Rule: one schema per form, colocated in the feature's `validation/` folder

Shared policy fragments (like a password-length rule) live in one reusable
schema fragment and are composed into every schema that needs them, never
duplicated per form.

### Rule: server validation errors map onto the form via a shared helper

Once React Hook Form is introduced for a given form, Laravel 422 responses
map onto it via `setError`, using the shared `toLaravelFieldErrors` helper
from `src/api/` (§2) — never re-parsed ad hoc per form.

### Rule: submit state and duplicate-submission prevention come from the relevant hook's pending state

Where a mutation hook already exists (`useIssueInvitationMutation`,
`useUpdateProfileMutation`), its `isPending` disables the submit control —
this already happens correctly in some places today and should be applied
consistently everywhere a mutation hook exists, rather than a page
tracking its own separate `submitting` flag. Once React Hook Form is
introduced for a form, `formState.isSubmitting` serves the same purpose.

### Rule: password-manager compatibility is a hard requirement, not a nice-to-have

Directly required by ADR-0004. Never `autocomplete="off"`; use the correct
token (`new-password` on set/reset, `current-password` on login, `email` on
the email field); never block paste.

**Good example (already correctly in place today, and the reference to
build on):** the login form's `autocomplete="current-password"` and the
invitation-acceptance form's `autocomplete="new-password"` are already
covered by an automated test asserting exactly these attributes — extend
this pattern to every new auth-adjacent form rather than treating it as
one-off coverage.

### Rule: accessible labeling and error association

Every field has an associated `<label htmlFor>`; status/error text uses
`role="status"` or `role="alert"` as appropriate. Already the pattern in
use today — keep applying it as new forms are added. See also §11.

---

## 6. TypeScript standards

### Rule: `strict: true`, explicitly, in every app `tsconfig`

**Rationale:** the project currently relies solely on ESLint's
`strictTypeChecked` config for type-safety enforcement; `tsc` itself is not
running in strict mode. ESLint's type-aware rules are valuable but aren't a
substitute for `tsc --strict`'s own null-safety checking, and don't apply
to files ESLint doesn't lint.

**Exceptions:** none.

### Rule: `any` is forbidden; use `unknown` and narrow

**Good example:**
```ts
function toAppError(err: unknown): AppError {
  if (axios.isAxiosError(err)) { /* narrowed */ }
  if (err instanceof Error) return { message: err.message };
  return { message: "Something went wrong. Please try again." };
}
```

**Avoid:** `catch (err: any)`, `as any`, or a function typed to accept or
return `any` to make a type error go away.

### Rule: `type` over `interface`, unless declaration merging or another concrete requirement justifies `interface`

**Rationale:** one convention, applied consistently, for ordinary object
shapes is more valuable than the specific choice between the two — and
matches what's already used throughout the current codebase.

**Exceptions:** `interface` where declaration merging is genuinely required
(should be rare in ordinary feature code).

### Rule: domain types vs. API DTOs — mapped in one place, or identical where there's no reason for them to differ

**Rationale:** where the wire shape and the UI-facing shape are the same
(as with the current `User` type), one type serving both jobs is fine —
introducing a separate mapping layer before there's an actual divergence
would be premature. Once a form needs a shape that differs from the API's
(different field names, a derived value), the mapping happens in exactly
one place, typically at the API-module boundary.

**Good example:** `UpdateProfileInput = Pick<User, "name" | "timezone">` —
a request payload derived directly from the domain type via `Pick`, rather
than a hand-duplicated parallel type.

### Rule: literal unions over TS `enum`

**Good example:** `type InvitationTransition = "resend" | "revoke";`.

### Rule: `null` for "explicitly absent," `undefined` for "not yet loaded"

Matches Laravel's JSON `null` for nullable fields (e.g.
`email_verified_at: string | null`); `undefined` is reserved for genuinely
optional/not-yet-fetched values.

### Rule: exhaustiveness checks on discriminated unions

**Good example:**
```ts
function assertNever(x: never): never {
  throw new Error(`Unhandled case: ${JSON.stringify(x)}`);
}
```
used in the default branch of any switch over a closed union (e.g.
invitation status) so an unhandled case is a compile error, not a silent
fallthrough.

### Rule: `as` type assertions are discouraged outside defined trust boundaries

**Avoid:** `as` used to silence a type error whose cause isn't understood.

### Rule: type placement — colocate first, promote only on second use

A type lives in the feature that defines it until a second feature
actually needs it.

---

## 7. Error handling

### Rule: four categories, normalized identically

Transport errors (network/timeout), expected domain errors (401/403/404/
409/422/429), validation errors (422 field-level), and unexpected errors
(5xx, unknown shape) are all routed through the shared `toAppError`/
`toLaravelFieldErrors` helpers described in §2 once they exist — not
handled ad hoc per call site. Until then, a `catch` that sets a fixed,
generic user-facing message (as today's forms do) is an acceptable interim
step, but discards detail (a specific field error, a rate-limit message)
that the shared helpers are meant to preserve — extracting them becomes
worthwhile as soon as a second form needs the same field-error handling.

### Rule: a root-level error boundary wraps the app

**Rationale:** a render-time exception without a boundary shows a blank or
broken page with no explanation — a real problem for the non-technical and
elderly relatives this product is built for, per `PRODUCT_VISION.md`.

**Good example:** an `ErrorBoundary` wired around `<App />` in `main.tsx`,
rendering a plain, actionable "Something went wrong" screen; covered by a
test that deliberately throws inside a child component.

**Exceptions:** none — this is required, currently missing, infrastructure.

### Rule: user-safe messaging only

Raw server exception text, stack traces, or unrecognized response bodies
are never shown to the user. Known status codes map to plain-language
copy; unknown/5xx errors get a generic "Something went wrong, please try
again."

### Rule: no silently swallowed errors

Every `catch` either handles the error meaningfully (sets UI state,
retries, logs) or rethrows. ESLint's `no-floating-promises` (already part
of the project's `strictTypeChecked` config) enforces the closely related
un-awaited-promise case.

### Rule: structured logging never includes sensitive material

Log the normalized error shape (message/status/code); never raw response
bodies, and never password or token material — mirrors ADR-0004's own
logging rules for the backend.

---

## 8. Routing and authentication

Kept aligned with ADR-0004 throughout — nothing here reopens that ADR's
decisions.

### Rule: React Router owns navigation only; TanStack Query owns server state

Once introduced (per §0's dependency-timing rule — in the bounded change
that first needs real route protection, not before), the router's
data-loading APIs (`loader`/`action`) are not used; every route's data
comes from a query hook.

### Rule: current-user state is one query hook

**Already the case, and the reference to build on:**
```ts
// features/account/hooks/useCurrentUserQuery.ts
export function useCurrentUserQuery() {
  return useQuery({ queryKey: accountKeys.current, queryFn: getCurrentUser, retry: false, staleTime: 30_000 });
}
```
Every component that needs to know who's logged in reads this hook —
nothing else calls the current-user endpoint independently.

### Rule: route protection is a shared guard once a router exists

**Interim state:** before a router is introduced, a page performing its own
conditional check against `useCurrentUserQuery`'s result (redirecting on
error) is an acceptable interim pattern for a single protected page.

**Target state:** once more than one route needs protecting, that check
becomes a shared `RequireAuth` layout-route component reading the same
query hook, rather than duplicated per page:
```tsx
export function RequireAuth() {
  const { data: user, isLoading } = useCurrentUserQuery();
  const location = useLocation();
  if (isLoading) return <p>Checking your session…</p>;
  if (!user) return <Navigate to="/login" replace state={{ from: location }} />;
  return <Outlet />;
}
```

### Rule: unauthenticated and forbidden are distinct states

A 401 redirects to `/login`; a 403 renders an explicit "not allowed" state
— a 403 is never silently treated as if the user weren't logged in at all.

### Rule: invitation-token routes follow the ADR-0004-mandated pattern

The token lives in the URL only until first validated; the browser is then
redirected to a clean URL, or the token is exchanged for short-lived opaque
server-side state, before the password-setting step renders.

**Reference implementation (already correct — the canonical example, not a
gap to fix):**
```ts
// features/invitations/pages/InvitationAcceptancePage.tsx
const [invitationToken] = useState(() =>
  new URLSearchParams(window.location.hash.slice(1)).get("token"),
);
useEffect(() => { window.history.replaceState(null, "", "/accept-invitation"); }, []);
```
Hash-fragment token, one-shot exchange via `useInvitationClaimQuery`,
history stripped before the form renders, verified under React StrictMode
by its own test.

### Rule: no component re-derives auth state independently

Every component that needs the current user reads `useCurrentUserQuery()`;
nothing else re-fetches it.

---

## 9. Testing

### Rule: Vitest + React Testing Library, colocated `*.test.ts(x)`, testing behavior

No separate `__tests__/` directories. Tests assert what a user can see or
do, not internal component state or props — already the pattern in use.

### Rule: hook and component tests use a fresh `QueryClient` per test

**Already the pattern in use:**
```ts
const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
```

### Rule: mocking the API module directly is acceptable for hook/component behavior; MSW is required for API-layer and negative-path coverage

Mocking a feature's API-module functions with `vi.mock(...)` (as current
invitation tests do) is a legitimate way to test hook and component
behavior without a real network call. It does not, however, exercise the
API layer itself — CSRF sequencing, envelope unwrapping, or how a specific
422/401/429 response actually gets handled. MSW is introduced (per §0's
dependency-timing rule) in the bounded change that first needs to test one
of ADR-0004's required negative paths against real HTTP response shapes,
with handlers living in `test/msw/handlers/`, one file per feature.

### Rule: security-sensitive negative paths are mandatory, not optional, for auth/invitation-adjacent screens

**Already the pattern in use, and the model to extend:** the existing tests
already assert the invited email can't be edited, that password fields
carry the correct `autocomplete` value, and that no open-registration path
exists. Every future auth-adjacent form or page gets equivalent
negative-path coverage — expired token, revoked invitation, rate-limited,
wrong password — as those flows are built.

---

## 10. Naming and imports

### Rule: `@/` path alias, configured once, used for cross-feature imports

**Rationale:** not yet configured, and the cost is already visible — a
three-level relative import (`../../../api/client`) exists today from a
feature's `api/` module back to the shared client. This is inexpensive
tooling configuration (a `tsconfig.app.json`/`vite.config.ts` change), not
a runtime dependency, so it isn't subject to §0's dependency-timing rule
the way a library is — it's reasonable to add promptly rather than waiting
for a specific triggering change.

Configured in `tsconfig.app.json` (`paths`) and `vite.config.ts`
(`resolve.alias`). Relative imports remain appropriate between files inside
the same feature.

### Rule: file and symbol naming

- Components: PascalCase files (`LoginPage`, `InvitationAcceptanceForm`).
- Hooks/utils/API modules: camelCase (`useCurrentUserQuery.ts`, `authApi.ts`).
- Query hooks: `useXQuery`. Mutation hooks: `useXMutation`.
- API functions: verb + resource, matching the HTTP action
  (`getCurrentUser`, `issueInvitation`, `transitionInvitation`) — already
  the pattern in use.
- Tests: colocated `*.test.ts(x)`, named for observable behavior ("keeps
  the invited email read-only" / "exchanges a fragment token once under
  Strict Mode"), not "test 1" or "renders correctly."

### Rule: barrel files are complete, or don't exist

If a directory gets an `index.ts` re-export, it re-exports that directory's
full public surface, and every consumer imports through it. No barrels
exist today, which is fine — introduce one only when it can be complete
from the start.

### Rule: named exports throughout, including pages (see §4)

### Rule: import ordering

External packages, then (once configured) `@/`-aliased imports, then
relative imports, each group separated by a blank line.

---

## 11. Accessibility

Several of these are direct, frontend-visible consequences of ADR-0004 and
`PRODUCT_VISION.md`'s accessibility principle, not independent style
preferences — noted where that's the case.

### Rule: semantic HTML first

Real `<button>` for actions, landmark elements (`<main aria-labelledby>`),
real `<form>`/`<label>` elements — already the pattern in use throughout
the current auth and invitation screens.

### Rule: full keyboard operability

Every interactive control is reachable and operable via keyboard alone; no
click-only handlers on non-interactive elements.

### Rule: focus management on navigation

Moving to a new view (post-login redirect, post-invitation-acceptance)
sets focus to a sensible starting point rather than leaving it on a
now-unmounted element.

### Rule: labels and error association

**Already the pattern in use:** every input has an associated
`<label htmlFor>`; status messages use `role="status"`, errors use
`role="alert"`. Keep applying this as new forms are added; see also §5.

### Rule: async state changes are announced, not just visually shown

A loading state like "Checking your invitation…" is inside a status region
(`role="status"`, as already used), not conveyed by appearance alone.

### Rule: never convey meaning by colour alone

Error/success/required state is paired with text or an accessibly-labeled
icon, not colour alone.

### Rule: respect `prefers-reduced-motion`

Any non-essential animation/transition is disabled or reduced under that
media query, once any exists.

### Rule: accessibility linting

`eslint-plugin-jsx-a11y` is added and wired into `eslint.config.js` in the
same change (per §0) — not installed and left unconfigured.

### Rule: no CAPTCHA anywhere in the auth/invitation flow

Direct restatement of ADR-0004 — CAPTCHAs are a known accessibility failure
point for elderly and visually impaired users, and the anti-abuse job they
would do is already covered structurally (invitation-only registration,
high-entropy single-use tokens, rate limiting).

---

## 12. Prohibited patterns

- **Raw HTTP-client calls in pages/components.** Bypasses the API boundary
  in §2.
- **Fetching server state through `useEffect`.** TanStack Query exists
  specifically to replace this (§3) — reacting to already-fetched query
  state with a side effect (e.g. a redirect) is a distinct, acceptable
  pattern; performing the fetch itself in `useEffect` is not.
- **A mutation called as a bare async function from a page instead of a
  `useMutation` hook.** See §3 — the rule applies to every mutation, not
  only some.
- **Duplicated cross-cutting logic** — most concretely, an envelope-unwrap
  helper or a CSRF-bootstrap call reimplemented per feature instead of
  shared once (§2).
- **A giant global `api.ts` containing every endpoint.** Endpoint functions
  belong in feature API modules (§2).
- **Untyped API responses** — `any`, or an ad hoc inline generic at the
  call site instead of a named, shared DTO type (§6).
- **Business logic embedded in JSX** — extract to a hook or utility (§4).
- **A generic `utils/` folder collecting unrelated helpers.** Utilities
  live in `src/api/` or a feature's own folder, scoped by concern.
- **A broad Context provider used as an application state store.** Context
  is for genuinely cross-cutting client state only; server state —
  including the current user — goes through TanStack Query (§3, §8).
- **Swallowed promise rejections.** Every `catch` handles or rethrows;
  enforced by ESLint's `no-floating-promises`.
- **Using `any` to bypass modeling a type.** Use `unknown` and narrow
  instead (§6).
- **Speculative abstractions** — pre-scaffolding empty feature subfolders,
  or installing a library before it has a real consumer (§0, §1). An
  installed-but-unwired dependency and an empty directory are the same
  mistake at two different levels.
- **Inconsistent or inaccessible loading/error handling.** Every query
  consumer distinguishes its states accessibly (§3) — the rule is
  consistency and accessibility, not use of one specific component.
