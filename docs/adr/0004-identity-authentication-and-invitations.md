# ADR-0004: Identity, Authentication and Invitations

- Status: Accepted
- Date: 2026-08-02
- Decision owners: David
- Related stages: FPA-P02-S01 (accepting this ADR completes that stage),
  implemented by FPA-P02-S02, FPA-P02-S03, FPA-P02-S04

## Context

`PROJECT_ROADMAP.md` Phase 2 requires secure, invite-only access suitable for
an international, largely non-technical family: registration by invitation,
login/logout, a current-user endpoint, email verification, password reset,
session security, an optional MFA foundation, the full invitation lifecycle
(issue, resend, revoke, expire, accept) with audit records, and a basic
account profile with timezone. Its exit criteria are explicit: uninvited
users cannot create accounts; the invitation lifecycle is audited; auth flows
have feature tests; sessions and cookies meet production security
requirements.

ADR-0002 fixed the stack this must be built on: a React + Vite single-page
frontend (`apps/web`) talking to Laravel (`apps/api`) as the sole business
authority, with Redis scoped to caching and ephemeral state only (not
queueing). ADR-0003 fixed the local platform: Docker Compose, LocalStack,
Mailpit for local mail capture, and an OpenTelemetry baseline already
carrying request/trace identifiers.

This ADR decides Phase 2 only: how an account comes to exist, how it proves
who it is on each request, and how that access can be revoked. It
deliberately does not decide anything about family spaces, membership roles,
tenancy, or row-level security — `PROJECT_ROADMAP.md` sequences those into
Phase 3 (ADR-0005) precisely because the family-space boundary doesn't exist
yet at this point in the roadmap. That sequencing creates one real tension
this ADR has to resolve rather than hide: `PRODUCT_VISION.md` describes
"family-specific invitations," but nothing family-specific can be modelled
before Phase 3. Phase 2's invitation is therefore necessarily an
**account-creation invitation only** — proof that a specific email address
is allowed to create a login — not a family-membership grant. That's called
out explicitly below rather than quietly designed around.

## Decision

### 1. Authentication backend: Laravel Fortify (headless), not Breeze, not Jetstream, not fully custom

Fortify is the accepted foundation for login, logout, registration
plumbing, password reset, email verification and two-factor authentication.
It ships no views or frontend assets — it's routes and swappable action
classes — which is exactly what a separately-deployed React SPA needs, and
it gives production-hardened implementations of session regeneration on
login, throttled login attempts, time-limited, single-use password-reset
tokens, and TOTP 2FA with recovery codes, instead of hand-rolling each of
those.

Fortify assumes open self-registration by default. That assumption is
rejected outright: the standard Fortify registration endpoint is disabled
and replaced by a dedicated invitation-only account-creation endpoint.
Overriding the `CreateNewUser` action provides an additional server-side
guard, but is not relied upon as the sole mechanism preventing open
registration — the architecture must not depend only on an action-class
check if a conventional registration route remains publicly available. No
route accepts an email and password to create an account without a valid,
unexpired, unrevoked invitation token.

Breeze and Jetstream are rejected because both ship an opinionated frontend
(Blade/Livewire/Inertia) this project doesn't want — `apps/api` is not a
second frontend (per ADR-0001, restated in `CONTRIBUTING.md`). Breeze's
"API" stack is a thinner scaffold-and-customize starting point with no
built-in 2FA and less complete password-reset/email-verification coverage
than Fortify's headless actions; there's no reason to fork a lesser version
of what Fortify already provides. Fully custom (no Fortify) was considered
and rejected: Phase 2 needs correct session-fixation handling, correctly
configured throttling, and TOTP + recovery-code generation, all of which
Fortify already gets right; reimplementing them adds risk for no product
benefit given invitation-gating only requires overriding one action class,
not rebuilding the framework.

### 2. Session authentication: Laravel Sanctum, SPA (cookie) mode — not API tokens

fambam is a first-party browser SPA. Laravel Sanctum's stateful
session-cookie mode is selected because it provides the natural
CSRF-protected browser authentication model, centralised session
management and server-side revocation required by this application.
Bearer-token authentication remains unnecessary for the first-party web
client.

SPA flow: the frontend first `GET`s `/sanctum/csrf-cookie` to receive the
`XSRF-TOKEN` cookie, then `POST`s credentials to Fortify's login route; the
browser's HTTP client (expected to be axios or an equivalent that mirrors
its cookie/header handling) echoes `XSRF-TOKEN` back as the `X-XSRF-TOKEN`
header on every subsequent state-changing request. The session cookie itself
is `httpOnly`, `Secure`, and `SameSite=Lax` (or `Strict` if it proves
workable against the CSRF flow), so it is not readable by JavaScript.
`SANCTUM_STATEFUL_DOMAINS` is set to exactly the web app's own origin(s); no
other origin is trusted for cookie-based auth.

### 3. CSRF strategy

Sanctum's built-in double-submit cookie mechanism (`XSRF-TOKEN` cookie +
`X-XSRF-TOKEN` header, validated by `VerifyCsrfToken` on the `stateful`
middleware group) is the accepted CSRF defence for every state-changing
request from the SPA. No separate CSRF scheme is introduced.

### 4. Session store: database, not Redis

This is a deliberate departure from defaulting everything ephemeral to
Redis. Phase 2's exit criteria require that **revoked users cannot retain
active sessions** — a server-initiated, revoke-by-user-id operation. The
database session driver stores one row per session including `user_id`,
`ip_address`, `user_agent` and `last_activity`, so all active
database-backed sessions belonging to a user can be invalidated by user
identifier, and "list this account's active sessions" (useful for the
account-security surface in FPA-P02-S04) falls out for free. Redis sessions
have no native secondary index by user; supporting the same revoke-by-user
operation would mean hand-maintaining a `user_id → [session_ids]` index in
Redis ourselves — which is just a worse reimplementation of the row the
database driver already gives us. Database sessions still expire and get
garbage-collected like any other session store; "database-backed" is not
the same as "durable business data," and this does not reopen
ADR-0002/ADR-0003's scoping of Redis to caching — it's simply not the
better mechanical fit for this specific requirement.

Deleting session rows is necessary but not sufficient for revocation — see
§5 for the paired remember-token handling this requires.

### 5. Global session and remember-me revocation

Revoking a user's access must not stop at deleting database session rows —
remember-me tokens are a second standing credential that survives session
deletion and can silently re-authenticate the browser that holds the
cookie. Every operation that should end access to an account server-side —
a user-initiated "log out everywhere," an operator revoking an account, and
the password-reset security response (§7) — performs all of the following
as one operation:

1. Delete every database session row belonging to the user
   (`sessions.user_id = ?`).
2. Rotate or clear the user's `remember_token` (regenerate to a new random
   value, or null it, so any browser holding the old remember-me cookie can
   no longer use it to re-establish a session).
3. Where the operation is initiated from within an active session of the
   user's own (e.g. "log out everywhere" triggered from a logged-in
   device), invalidate/regenerate that request's own session appropriately
   rather than leaving it in an inconsistent state — the initiating session
   is not implicitly exempt from the revocation; if the flow deliberately
   keeps the initiating device signed in, it does so by explicitly
   re-establishing a fresh session/remember-token afterward, not by
   skipping revocation for it.
4. Write an `AuditEvent` recording the revocation, its cause and its actor,
   where the operation is security-sensitive (operator-initiated
   revocation, password-reset-triggered revocation) — a user's own routine
   "log out everywhere" is logged with the same mechanism for consistency,
   though it carries lower review priority than an operator- or
   system-initiated one.

This is implemented as a single reusable operation, not duplicated ad hoc at
each call site, so §7 and any future revocation trigger call the same,
fully-tested path.

### 6. Email verification is satisfied by invitation acceptance, not a second email

Fortify's stock flow assumes registration doesn't already prove email
ownership, so it sends a separate "verify your email" link after account
creation. Here it would be redundant: the only way to reach the
registration form at all is by following a unique, signed, single-use link
mailed to that exact address. Accepting the invitation already proves
ownership at least as strongly as a dedicated verification email would.

The account's email address is taken exclusively from the invitation
record and is not an editable field on the acceptance form — there is no
client-submitted email input at all during acceptance, only a
server-resolved value from the validated invitation. `email_verified_at` is
set at the moment an invitation is successfully accepted, and invitation
token consumption plus user creation happen as a single atomic operation
(see §8's "Accept" step for the concrete mechanism). No second verification
email is sent for invitation-created accounts.

The `MustVerifyEmail` contract and `verified` middleware remain available
so future flows — such as verified email-address changes — can use the
framework's normal verification model. Self-service email-address change
does not ship in Phase 2 and remains deferred as stated below.

### 7. Password reset

Laravel's standard time-limited, single-use password-reset token broker,
delivered by email (Mailpit locally). The response to a reset request is
identical regardless of whether the submitted email matches an account, to
avoid account enumeration. Password reset does not require MFA to complete
in Phase 2 (no MFA is mandatory yet — see §10); a successful password reset
revokes all other sessions and remember-me credentials for that user as a
standing security response, using the global revocation operation defined
in §5.

### 8. Invitation lifecycle (account-creation scope only)

An `Invitation` record: invited email, hashed token (the raw token is only
ever present in the emailed link, never stored — following the same
pattern as Laravel's password-reset tokens), the inviting user, status
(`pending` / `accepted` / `revoked` / `expired`), `expires_at`,
`accepted_at`, `revoked_at`.

- **Issue**: creates a `pending` invitation with a high-entropy (≥128-bit)
  single-use token and a 7-day expiry (configurable, not hardcoded).
- **Resend**: invalidates the previous token and issues a new one on the
  same record rather than creating a second parallel invitation — at most
  one live token per pending invitation at any time.
- **Revoke**: marks `revoked`; the token is rejected from that point,
  including if the email had already been opened but not yet submitted.
- **Expire — authority model**: `expires_at` is the sole authority for
  whether an invitation is currently *acceptable*: an invitation may be
  accepted if and only if `status = pending AND expires_at > now()` at the
  moment of the attempt. `status = expired` is a persisted *historical
  record* of that determination, written atomically the first time an
  expired invitation is touched — either by an acceptance attempt against
  it, or by a future sweep job if one is added later. Until first touched,
  an expired-but-not-yet-swept invitation may still read `status = pending`
  in storage; this is not a contradiction, because `status`'s job is the
  historical audit trail, not real-time acceptability — every code path
  that needs "is this acceptable right now" consults `expires_at`, never
  `status` alone. An acceptance attempt against an expired-but-still-`pending`
  row atomically transitions it to `status = expired` and writes an
  `AuditEvent`, then rejects the attempt. No eager scheduled sweep ships in
  Phase 2; it is a cheap later addition if a product need for it (e.g.
  surfacing "expired" promptly in an admin list before anyone attempts to
  use it) shows up.
- **Accept**: a single database transaction using row-level locking (or an
  atomic conditional update, e.g. `UPDATE invitations SET status =
  'accepted', accepted_at = now() WHERE id = ? AND status = 'pending' AND
  expires_at > now()` and checking the affected-row count) validates the
  token hash, status and expiry, and creates the `User` in the same
  transaction. This guarantees that if two acceptance attempts race (e.g.
  the link opened in two tabs, or resubmitted), only one can succeed; the
  other observes the row already transitioned and fails cleanly rather than
  creating a duplicate account. Email verification is set per §6, the
  invitation is marked `accepted`, and an `AuditEvent` is written — all
  inside the same transaction.

Every transition (issue, resend, revoke, expire-on-use, accept) writes an
`AuditEvent` (actor, action, subject, IP, user agent, timestamp), satisfying
the roadmap's explicit "invitation lifecycle is audited" exit criterion.
`AuditEvent` is the same entity named in `PRODUCT_VISION.md`'s initial
domain model; Phase 2 introduces it scoped narrowly to auth/invitation
actions, and later phases (starting with Phase 3's "family-space audit
history") extend its coverage rather than replacing it.

**Token handling and acceptance-page safety.** The invitation token is
sensitive bearer material for the duration between email delivery and
acceptance, and is handled accordingly:

- The invitation's email address is authoritative and cannot be overridden
  by any form input (see §6) — the acceptance form never accepts an email
  field.
- The raw token is never stored server-side in any form (database, logs,
  cache) — only its hash, exactly as password-reset tokens are handled.
- The acceptance page sets a restrictive `Referrer-Policy` (e.g.
  `no-referrer` or `strict-origin`) so the token is not leaked via the
  `Referer` header to any resource the page happens to load.
- No third-party analytics, fonts, trackers or other external resources are
  loaded on the acceptance page while the raw token is present in the URL —
  each such request is a potential exfiltration path for the token via its
  own logging or the `Referer` header.
- Immediately after the token is validated on first load, the browser is
  redirected to a clean URL with the raw token removed from the address
  bar and browser history, or the raw token is exchanged server-side for a
  short-lived opaque session value used for the remainder of the acceptance
  flow (password-setting step). This also keeps the raw token out of
  ordinary web-server access logs, which typically capture full request
  URLs.

### 9. Who can issue an invitation (Phase 2's deliberately minimal answer)

Phase 3 hasn't happened yet, so there are no family roles to gate this by.
Phase 2 adds a single boolean, `users.can_invite`, defaulting to `false`.
Any account with `can_invite = true` may issue/resend/revoke invitations.
The very first account (there is no one to invite the first user) is
created by an Artisan console command run by the operator, not by any HTTP
endpoint — invite-only means there is deliberately no code path that
creates an account without either that bootstrap command or a valid
invitation token. Phase 3 is expected to replace `can_invite` with a real
role check (e.g. Owner/Administrator) once family membership exists; this
flag is intentionally the simplest thing that satisfies Phase 2's own scope
without guessing at Phase 3's role model.

### 10. MFA: optional, TOTP, off by default — not mandatory

Fortify's TOTP two-factor implementation (authenticator app + recovery
codes) is the accepted mechanism, and its schema (`two_factor_secret`,
`two_factor_recovery_codes`, `two_factor_confirmed_at`) is added now so
nothing has to be retrofitted later. It ships disabled by default and is
opt-in per account. Mandatory MFA is rejected for this phase: the target
audience explicitly includes elderly and non-technical relatives, for whom
a forced TOTP enrolment step at first login is a real adoption barrier with
no family-side support desk to lean on. A security-conscious member (e.g.
the account issuing invitations) can turn it on for themselves today;
whether any role should ever be *required* to use it is a Phase 3/Phase 14
question, not this one. Because MFA is optional, most accounts will
initially rely on the password alone as their only factor — this directly
motivates the raised password-length minimum in §13.

### 11. Account locking: rejected in favour of throttling

No hard account-lockout-after-N-failed-attempts mechanism is implemented.
Locking is a self-inflicted denial-of-service vector here: anyone who knows
(or guesses) a relative's email can lock them out of their own account
indefinitely just by failing their password a few times, and there's no
support desk to call to get unlocked. Instead, failed logins are throttled
per email+IP pair (Fortify's default limiter, tuned rather than replaced),
which slows brute force to an impractical rate without ever denying the
legitimate account holder access. This is a direct simplicity-for-family
over enterprise-control trade-off, made deliberately.

### 12. Rate limiting

Named rate limiters, keyed by email+IP where the target is an account
identifier (login, password-reset request) and by IP alone where it isn't
(invitation-token acceptance attempts): login, password-reset request,
invitation acceptance, and invitation issuance (so a compromised
`can_invite` account can't be used to mass-spam invites). All
enumeration-sensitive endpoints (login, password reset, invitation accept)
return identical responses on success-shaped and failure-shaped inputs
where practical.

### 13. Password policy

Minimum length: **15 characters**. Because MFA is optional and off by
default (§10), the password is, for most accounts, the sole factor
protecting the account; the higher minimum compensates for that using
length — the single strongest lever against offline/brute-force guessing —
rather than composition complexity.

Maximum length: at least 64 characters, so users are not prevented from
using long passphrases or password-manager-generated passwords.

Accepted input: full Unicode, including spaces, is accepted verbatim — no
silent trimming, no normalisation that changes the string, no character-set
restriction. Passwords must not be truncated silently by validation, the
framework, or database column width; the target storage must accommodate
the maximum accepted input length before hashing, not after truncation.

Paste and password managers: the registration/reset/login forms must not
block paste into the password field, must not disable browser/OS
password-manager autofill (no `autocomplete="off"` on password fields), and
use the correct `autocomplete` values (`new-password` on set/reset,
`current-password` on login) so password managers behave correctly.

No forced composition rules (no mandatory uppercase/digit/symbol) — current
guidance (e.g. NIST SP 800-63B) treats forced composition as low security
value and usability-harmful; length plus breach screening is the accepted
approach instead.

**Compromised-password screening**: Laravel's `Password::defaults()
->uncompromised()` rule (the Have I Been Pwned Pwned Passwords k-anonymity
API) is enabled at registration, password reset, and any future
password-change endpoint, subject to the following operational safeguards:

- The check runs only on final form submission — the actual server-side
  create/reset/change request — never incrementally as the user types. No
  client-side or background call triggers it on keystroke.
- A short request timeout (target ~1.5 seconds) is applied to the HIBP
  call.
- **Fail-open** on timeout or any HIBP-side error: the password is treated
  as not-compromised and the request proceeds. Blocking a family member
  from creating or resetting an account because a third-party service is
  briefly unavailable is judged worse than occasionally skipping this one
  check. The outage itself may be logged as an operational event (the
  check could not be completed), never alongside any password material.
- Nothing password-derived is logged: not the plaintext password, not its
  full hash, and not the SHA-1 prefix sent in the k-anonymity range query.
  Only the fact that a check ran and its outcome (pass / fail /
  could-not-complete) may be logged.
- Response padding: if the concrete HTTP integration used for this check
  supports setting the Pwned Passwords `Add-Padding` request header, it is
  enabled, to obscure the true response size from network-level traffic
  analysis. If Laravel's built-in `uncompromised()` rule does not expose a
  way to set that header, the check is implemented via a small dedicated
  HTTP client that does, rather than assuming the stock rule provides it —
  confirmed during FPA-P02-S04 implementation, not assumed here.
- Recorded honestly: this is the one outbound network dependency in the
  authentication path — a k-anonymity range query to the public Have I Been
  Pwned Pwned Passwords API, sending only a 5-character SHA-1 prefix, never
  the password or its full hash.

### 14. Remember-me

Standard Laravel remember-token cookie, opt-in at login, independent of the
main session's lifetime. It is invalidated on password change,
operator-initiated revocation and "log out everywhere" exclusively through
the shared revocation operation in §5 — no independent remember-token
handling is implemented at the feature level.

### 15. Basic profile and timezone

`User` gains a display name and an IANA timezone string, editable by the
account holder once authenticated. No further profile fields are in scope;
`PRODUCT_VISION.md`'s richer person/relationship model is explicitly Phase
4, and a `User` is not a `Person` (see `PRODUCT_VISION.md`, "Accounts,
people and relationships") — nothing here should be read as pre-modelling
that distinction.

### 16. Accessibility principle: no CAPTCHA in the auth flow

No CAPTCHA challenge is added anywhere in login, invitation acceptance, or
password reset. CAPTCHAs are a known accessibility failure point for
elderly and visually impaired users, and the anti-abuse job they'd do here
is already covered structurally: there is no open registration surface to
attack (§1), invitation tokens are high-entropy and single-use (§8), and
every relevant endpoint is rate-limited (§12). The concrete WCAG target
standard is Phase 14's decision; this ADR only commits to not introducing a
control that phase would likely have to rip back out.

### Explicitly out of scope / deferred

- **Family-space-scoped invitations, membership roles, and any
  authorization beyond "is this an authenticated account with a valid
  session"** — Phase 3, ADR-0005. `can_invite` (§9) is a deliberately
  disposable stand-in, not a preview of the role model.
- **Row-level security / database-level tenant enforcement** — Phase 3,
  ADR-0005.
- **Person/relationship modelling, account-to-person linking** — Phase 4,
  ADR-0006.
- **Social login (Google/Facebook/etc.)** — not deferred, excluded. The
  product exists specifically to give the family an alternative to
  depending on mainstream platforms (`PRODUCT_VISION.md`); routing account
  creation and login through an external identity provider reintroduces
  exactly the kind of platform dependency the project exists to avoid,
  and adds a second identity and recovery system to reason about for
  negligible benefit at this user count — invitations already reach people
  directly by email, so there is no present product need for it. This is a
  product-independence and complexity judgement, not a claim that any
  particular identity provider would necessarily learn family-membership
  information; the objection holds even against a privacy-scrupulous
  provider.
- **Passkeys / WebAuthn** — genuinely deferred, not excluded. Well suited
  to the elderly/non-technical audience (no password to remember), but
  Laravel/Fortify has no first-party support today, and passkey recovery
  design (a relative's only enrolled device is lost) needs dedicated design
  work this ADR shouldn't improvise. Candidate for an early post-V1 ADR or
  folding into Phase 14.
- **Self-service email change is deferred.** Loss of access to the
  registered email requires a narrowly controlled operator-assisted
  recovery procedure that updates the existing account, invalidates all
  sessions and remember-me credentials, and verifies the replacement
  address. The detailed identity-proof procedure is outside Phase 2.
- **Scheduled/eager invitation-expiry sweeping** — see §8's authority
  model; a background sweep is a cheap later addition, not required for
  correctness in Phase 2.

## Alternatives considered

**Auth backend**: Breeze (rejected — ships an unwanted frontend, weaker
built-in 2FA/verification than Fortify); Jetstream (rejected — same
frontend problem, plus team/tenancy scaffolding that would pre-empt Phase
3's own role design); fully custom (rejected — reinvents session-fixation
handling, throttling and TOTP for no benefit, given invitation-gating only
requires overriding one Fortify action).

**Session transport**: Sanctum personal-access-token / bearer auth
(rejected — introduces token storage and transmission concerns that are
unnecessary for a first-party, same-origin browser client, where
cookie-based sessions already provide the CSRF-protected, centrally
revocable model this application needs); a fully custom JWT scheme
(rejected — reintroduces token-revocation and refresh problems Sanctum's
cookie model avoids entirely for this use case).

**Session store**: Redis (rejected specifically for session state, despite
being the general-purpose cache per ADR-0002/0003 — see §4's reasoning);
file-based sessions (rejected — doesn't work correctly once the API runs as
more than one container, and provides no revoke-by-user capability either).

**Account lockout vs throttling**: hard lockout after N attempts (rejected
— see §11, a self-inflicted denial-of-service risk against the exact
audience this product serves).

**Password composition rules**: forced uppercase/digit/symbol composition
(rejected in favour of a 15-character minimum plus breached-password
screening — current guidance treats forced composition as low-value and
usability-harmful compared to length and known-breach screening).

**Compromised-password screening, fail behaviour**: fail-closed on HIBP
timeout/outage (rejected — would block registration and password changes
entirely during a brief third-party outage, worse for this audience than
the rare, low-value miss of not screening one password during that
window).

## Consequences

### Positive

- Fortify + Sanctum SPA cookies gives production-hardened session handling
  (fixation-safe, throttled, 2FA-ready) without adopting a frontend this
  project doesn't want.
- Database sessions paired with the shared revocation operation (§5) make
  "revoke this user's access right now" — an explicit roadmap exit
  criterion — a fully testable, single code path covering both sessions
  and remember-me, instead of a bespoke Redis indexing scheme or two
  independently-maintained revocation mechanisms.
- Treating invitation acceptance as email verification removes a redundant
  email hop for every new family member, with the email address locked to
  the invitation record so this cannot be abused to register an
  attacker-chosen address.
- No mandatory MFA and no account lockout keep the first login experience
  achievable for elderly relatives, while a 15-character minimum and
  breach screening keep the password-only path meaningfully resistant to
  guessing and credential stuffing.
- Excluding social login and CAPTCHA keeps the auth surface consistent with
  `PRODUCT_VISION.md`'s privacy-first, non-mainstream-platform positioning
  instead of quietly reintroducing a third-party dependency.
- The HIBP dependency is scoped, safeguarded (short timeout, fail-open, no
  password material logged, response padding where supported) and recorded
  explicitly rather than left as an unexamined external call.

### Negative

- Database sessions add read/write load to Postgres (ADR-0001's single
  business-data store) for every authenticated request, where Redis would
  have been cheaper — an accepted cost for the revocation guarantee it
  buys.
- `can_invite` is a throwaway concept that Phase 3 will delete or replace;
  a small amount of Phase 2 work (whatever UI/endpoint exposes it) is
  disposable by design.
- No account lockout means a sufficiently patient attacker who only needs
  to avoid the throttle window can still make slow-drip guesses against a
  single known email indefinitely; mitigated but not eliminated by
  throttling and breached-password screening.
- Fail-open HIBP screening means a password could, during a genuine HIBP
  outage, be accepted despite being in a known breach corpus — a
  deliberately accepted, low-probability-window trade-off (see
  Alternatives).
- A 15-character minimum with no composition rules may feel unfamiliar to
  users expecting the traditional "8 characters, one number, one symbol"
  pattern; this is a conscious break from that convention, not an
  oversight.

### Risks

- If `can_invite` bootstrap tooling (§9) is ever exposed over HTTP by a
  future change rather than kept as an operator-run console command,
  invite-only registration is silently defeated. Should be covered by a
  feature test asserting no HTTP route can create the first account.
- If Phase 3's role model doesn't cleanly subsume `can_invite`, invitation
  issuance could end up gated by two inconsistent permission checks for a
  period; Phase 3's ADR should explicitly address the migration off this
  flag rather than leaving both live indefinitely.
- Treating invitation acceptance as sufficient proof of email ownership
  (§6) assumes the invitation email itself was delivered securely to the
  right inbox; if invitation delivery is ever changed to a channel with
  weaker delivery guarantees than email (e.g. a shareable link sent over a
  less trusted channel), this assumption should be revisited.
- If an implementer adds a new revocation trigger later without routing it
  through §5's shared operation, remember-me tokens could be left valid
  after sessions are deleted, silently reopening the exact gap §5 exists to
  close. Guard this with a feature test that asserts `remember_token`
  actually changes on every revocation path, not just that session rows
  are gone.
- If the acceptance-page token-safety measures in §8 (Referrer-Policy,
  no third-party resources, clean-URL redirect) are skipped during
  implementation, the invitation token can leak via browser history,
  server access logs, or a loaded third-party resource, defeating the
  single-use design even though the token itself remains cryptographically
  strong.

## Implementation notes

- **FPA-P02-S02** implements §§1–4, §6–§7 and §15: core Fortify/Sanctum
  authentication, CSRF, database-session configuration, password reset,
  initial email verification behaviour and profile/timezone.
- **FPA-P02-S03** implements §§8–§9: invitation lifecycle, invitation token
  and acceptance-page safety, bootstrap invitation authority and audit
  records.
- **FPA-P02-S04** implements §5 and §§10–§14 plus §16: the shared global
  session/remember-me revocation operation, MFA foundation, throttling,
  password policy and HIBP safeguards, remember-me hardening and
  accessibility/security hardening.

  Normal logout/session behaviour may exist in S02, but the reusable
  "revoke access everywhere" security operation and every security-sensitive
  trigger that uses it belong to S04.

- `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, `SESSION_DRIVER=database`
  and CORS `supports_credentials` must be set correctly for both the local
  Compose topology (ADR-0003) and later production domains; get this wrong
  locally and cookies silently fail to attach, which is worth an explicit
  feature test rather than relying on manual discovery.
- Specific tests to require, beyond ordinary happy-path coverage: every
  revocation trigger (§5) leaves `remember_token` changed, not just
  sessions deleted; concurrent invitation-acceptance attempts against the
  same token result in exactly one created user; the HIBP check's fail-open
  behaviour under a simulated timeout/error does not block submission and
  does not appear in logs alongside password material; the acceptance page
  response includes the intended `Referrer-Policy` header and loads no
  third-party resources while the raw token is present in the URL.
- Every negative/security-sensitive path (unauthenticated, expired token,
  revoked invitation, reused token, wrong password, rate-limited) needs a
  feature test per `CONTRIBUTING.md`'s security-testing requirement, not
  just the happy path.

## Review triggers

- If Phase 3 introduces family roles, revisit and retire `can_invite` (§9)
  explicitly rather than leaving it alongside the new role model.
- If Phase 14's accessibility/usability testing finds password-based login
  itself (not just composition rules) is a barrier for elderly relatives,
  revisit passkeys/WebAuthn as a priority rather than social login.
- If fail-open HIBP behaviour (§13) is found unacceptable after real-world
  review (e.g. during Phase 14 security hardening), revisit fail-closed
  with a clear, user-facing "try again shortly" message rather than a
  silent block.
- If database-session load on Postgres becomes measurable under real
  usage, revisit whether a hybrid (Redis session data + a lightweight
  Postgres `user_id → session_id` revocation index) is worth the added
  complexity — not assumed necessary now.
