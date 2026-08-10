# ADR-0007: Media Storage and Upload Pipeline

- Status: Accepted
- Date: 2026-08-09
- Decision owners: David
- Related stages: FPA-P05-S01 (accepting this ADR completes that stage),
  implemented by FPA-P05-S02, FPA-P05-S03, FPA-P05-S04, FPA-P05-S05,
  FPA-P05-S06, FPA-P05-S07

## Context

Phase 4 modelled family history independently of login accounts. Phase 5
now has to model the other thing the whole product exists to protect:
the photographs themselves, from the moment a byte leaves a browser to
the moment it becomes a trustworthy, family-scoped, reproducible asset —
without yet building the Photo domain that gives those bytes meaning.
`PROJECT_ROADMAP.md`'s Phase 5 objective is "reliable photo upload,
original preservation and secure media delivery"; its exit criteria fix
five concrete guarantees this ADR must make architecturally true rather
than aspirational: originals are never silently overwritten; derivatives
are reproducible; presentation changes do not invalidate AI analysis
unnecessarily; upload retries are idempotent; access to media is
family-scoped and tested.

Three prior ADRs constrain this one directly. ADR-0001 fixed Laravel as
the sole Postgres writer and Python as a stateless, message-only
inference boundary with no database access of any kind — Phase 5's media
plumbing (validation, checksums, EXIF, orientation, resizing) is
deterministic work with no ML content, so it inherits ADR-0001's boundary
rather than reopening it. ADR-0002 selected an application-owned,
S3-compatible object-storage abstraction but deliberately deferred
*building* it; that deferred work becomes due now. ADR-0005 fixed the
`families/{family_space_id}/...` storage-key partition, the
`TenantOperationContext` async envelope (`family_space_id`,
`actor_user_id`, `correlation_id`, `traceparent`), the `AuditEvent`
mechanism, and the Class C tenant-owned-table RLS pattern (`ENABLE` +
`FORCE ROW LEVEL SECURITY`, one `USING`/`WITH CHECK
(family_space_id = app_current_family_space_id())` policy) — Phase 5
inherits all four unchanged. ADR-0006 named Phase 5 as one of the phases
that must give Contributor and Guest their first concrete resource-level
meaning, and separately fixed the human-confirmed uncertain-date model
that Phase 6 (not Phase 5) will apply to a photograph's historical date.

This ADR deliberately does not decide anything about the Photo domain
itself: uploader/photographer/scanner provenance, historical dates,
albums, dynamic collections, stories, comments, or Photo-level deletion
and restoration are Phase 6 (ADR-0008). Where this ADR's scope brushes
against Phase 6 — the asset Photo will reference, the technical metadata
Phase 6 will read — it fixes the boundary those later decisions must
inherit, not their implementation.

## Decision

### 1. Preservation and presentation are separate concerns — the governing principle

**Preservation rights and presentation rights are separate concerns.
Fambam preserves the exact original media and metadata as the archival
record, while deliberately controlling which derived representations and
metadata are exposed through ordinary application surfaces.**

Every mechanism in this ADR is one instance of this same principle: the
original is preserved completely and is never the thing an ordinary
viewing surface serves; the canonical asset and variants are
presentation-safe derivatives generated *from* the original, never a
replacement *for* it; GPS and other privacy-sensitive metadata are
captured in full alongside the original but are not exposed by default
through ordinary API responses, UI responses, canonical assets or
presentation variants; an authorised preserved-original download
deliberately receives the untouched original and may therefore contain
its original EXIF/GPS metadata; TIFF is preserved exactly as an archival
format even though no browser renders it directly. Treating this as one
named principle, rather than five unrelated implementation choices,
matters because a future contributor extending Phase 5 in one place (say,
adding a new metadata field, or a new delivery surface) should be able to
ask "does this preserve the original faithfully, and does it control what
gets presented?" and get a consistent answer, rather than re-deriving the
policy from scratch per feature.

### 2. The Phase 5/6 boundary: `MediaUpload` and `MediaVariant`, not `Photo`

Phase 5 introduces two deliberately infrastructure-named, tenant-owned
entities: **`MediaUpload`** (one row per uploaded object, an immutable
ULID identity carrying the object's lifecycle from authorisation through
to a usable processed asset) and **`MediaVariant`** (one row per
generated derivative, keyed to its owning `MediaUpload`). Neither is
named `Photo`, `PhotoAsset`, or `PhotoVariant` — those names, and the
uploader/photographer/scanner provenance, historical dates and album
membership `PRODUCT_VISION.md`'s initial domain model associates with
them, belong to Phase 6.

`MediaUpload` carries: its ULID identity; `family_space_id`; the
uploading `user_id`; upload state (§4); the staging object key while the
upload is untrusted; the final original object key, checksum and byte size
(§6); client-declared filename and MIME type, retained as non-authoritative
metadata, never as path or decision input; server-detected MIME type; the
canonical object key once generated (§9); an optional `upload_batch_id`
(§14); and a rejection reason where applicable.

**An upload may permanently exist without ever becoming a Photo.** A
Member may upload the wrong file; an Owner may decline to promote an
upload during Phase 6 review; a `MediaUpload` never requires a Photo to
be valid or complete. Phase 6's `Photo` gets a nullable, unique
`media_upload_id` foreign key pointing *at* this table — never the
reverse. This mirrors ADR-0006's own merge-tombstone precedent: the
identity a later domain depends on is referenced forward by that later
domain, not forward-referenced by the phase that owns the identity. It
also means Phase 5 is fully implementable, testable and reviewable in
isolation, against its own roadmap exit criteria, with no stub `Photo`
table required.

Rejected or quarantined uploads retain their `MediaUpload` row (state,
reason, checksum where computed) for audit purposes even though the
object itself is not preserved indefinitely (§18) — a rejected upload
never reached "irreplaceable archival material," so the preservation
guarantee in §1 and §6 does not attach to it, only the fact that the
attempt happened does.

### 3. Direct-to-object-storage upload authority

The browser never proxies file bytes through Laravel, and Laravel never
holds unrestricted storage credentials on the browser's behalf. The
architectural requirement this ADR fixes is:

**The browser receives authority to upload exactly one object, to
exactly one server-generated key, for a limited period, and nothing
more.**

Concretely: the client requests upload initiation; Laravel resolves
tenant context, creates the `MediaUpload` row in `initiated` state,
derives a tenant-scoped staging key server-side (§5), and returns a
time-limited, key-scoped upload authorisation; the browser uploads bytes
directly to that staging key; the browser (or a client-side completion
signal) tells Laravel the upload finished; **Laravel verifies the object
independently rather than trusting that signal** — at minimum confirming
the object exists and has a plausible size before transitioning state,
with full checksum and content verification happening asynchronously
(§4, §7). After server-side format detection and validation, the verified
bytes are finalised under the archival media hierarchy with an extension
derived only from the detected format. Quarantined material may likewise
be finalised under the quarantine hierarchy with that detected extension
where available. Processing begins only once the object is verified.

**Upload authority must not permit accepted archival bytes to be replaced
after completion or finalisation.** Database-state idempotency is necessary
but is not, by itself, proof of object-byte immutability. The implementation
must use a verified write-once-equivalent mechanism — such as a conditional
write, staging-to-final finalisation, revocation-by-key design, or an
equivalent object-storage guarantee — and must test that reuse or racing of
upload authority cannot replace a preserved original. The exact storage API
used to copy, move or finalise bytes remains an implementation-guide
decision.

Whether that bounded authority is implemented as a presigned PUT URL or
a presigned POST policy is an implementation detail with no durable
architectural consequence either way, and is left to
`docs/IMPLEMENTATION_GUIDE.md` rather than fixed here.

**Bulk upload and multipart upload are different concerns and must not
be conflated:**

- **Bulk upload** (§14) means many independent files, each with its own
  `MediaUpload` row, lifecycle, retry and failure, optionally grouped by
  a shared batch identifier for presentation purposes only.
- **Multipart upload** means splitting *one* very large file's bytes
  across multiple upload requests to object storage. This ADR
  establishes the state machine and key scheme so multipart can be added
  later without redesign (via a `MediaUpload.upload_method` distinction),
  but does not implement it — V1 family photographs do not require it,
  and no stated product requirement currently forces the added
  complexity of tracking in-progress multipart state and abandoned-part
  cleanup. Revisit when video or another large-file requirement arrives
  (see Review triggers).

### 4. Upload state machine

```
initiated → uploaded → verifying → preserved → processing → ready
                ↓            ↓                       ↓
            abandoned    quarantined              degraded
```

- **`initiated`** — Laravel has created the row and issued upload
  authority for a specific key. No object exists yet.
- **`uploaded`** — the completion signal arrived and Laravel confirmed
  *something* exists at that key with a plausible size. **Not yet
  trusted.** This state exists specifically to separate "an object
  arrived" from "an object has been inspected."
- **`verifying`** — an asynchronous job is running the validation
  pipeline (§7) against the object.
- **`preserved`** — validation passed. This is the immutable-original
  checkpoint (§6): from this point on, the original object key and
  checksum are frozen and never rewritten by any later process.
- **`processing`** — canonical generation, metadata extraction and
  variant generation are running.
- **`ready`** — a canonical asset and the required variant set both
  exist. Only a `ready` (or later-defined equivalent) `MediaUpload` may
  be referenced by a Phase 6 `Photo`.
- **`quarantined`** — validation failed for any pipeline reason (§7).
  Terminal; never silently promoted to `preserved`. A corrected file is
  a new `MediaUpload`, not a resurrection of this one.
- **`abandoned`** — `initiated` with no completion signal before a
  bounded expiry (§18). Terminal.
- **`degraded`** — `preserved`, but one or more processing jobs failed
  after retries. The original remains safe and untouched; the record is
  visibly incomplete rather than silently stuck, and processing may be
  safely re-dispatched against the still-`preserved` original.

**Idempotency is a first-class requirement, not an implementation
afterthought.** The `initiated → uploaded` transition is a single
conditional update guarded by current state, exactly as ADR-0004 §8's
invitation-acceptance pattern already establishes for this codebase; a
repeated completion call against an already-transitioned row is a
no-op success, not an error — this is what makes "uploading the same
completion event twice is safe" (an explicit roadmap verification
bullet) true by construction. Every processing job checks current state
before acting, matching the idempotent-teardown precedent already
proven by `DeleteFamilySpace`.

### 5. Object-key design

```
families/{family_space_id}/media-staging/{media_upload_id}/original
families/{family_space_id}/media/{media_upload_id}/original.{ext}
families/{family_space_id}/media/{media_upload_id}/canonical.{ext}
families/{family_space_id}/media/{media_upload_id}/variants/{name}.{ext}
families/{family_space_id}/quarantine/{media_upload_id}/original.{ext}
```

All identity below the existing `families/{family_space_id}/...`
partition (ADR-0005) is the Phase-5-owned `MediaUpload` ULID. Upload
authority targets the extension-independent staging key because no client
claim is trusted enough to select the final extension. After validation,
`{ext}` is derived from the server-detected format (§7), never from the
client's filename, claimed extension or declared MIME type. No client
filename, no future Photo identifier, and no mutable Family Space slug
ever appears in an object key — matching `FamilyStorageKey`'s existing
traversal/NUL-rejecting behaviour and extending it rather than replacing
it. A Family Space rename therefore has zero effect on any object key, by
construction. Quarantined objects live under a visibly distinct
`quarantine/` prefix so retention (§18) can target them independently of
preserved media. The exact object-storage copy, move and finalisation API
is an implementation-guide decision subject to §3's write-once invariant.

### 6. Original preservation and integrity

The preserved original is byte-for-byte immutable from the moment
validation succeeds: never reoriented, never stripped of metadata, never
recompressed, never overwritten by any later processing step. Its
integrity is verified and re-verifiable via a server-computed SHA-256
checksum of the exact uploaded bytes, computed during `verifying` and
frozen at `preserved` — never a client-supplied checksum, and never
recomputed afterward. This is the concrete mechanism that keeps
"original checksums remain stable" (a roadmap verification bullet) true,
and gives Phase 8's future duplicate detection an integrity hash to build
on without Phase 5 designing Phase 8's own perceptual-hashing or
duplicate-index concerns.

This guarantee applies to the object bytes as well as to the database
state. Finalisation must fail safely rather than replace an object already
present at the archival key, and a stale or concurrently reused upload
authorisation must never be able to mutate the preserved original.

Per §1's governing principle, preservation extends to **all** original
metadata, not only pixel bytes: the complete raw EXIF block (including
GPS), any embedded ICC colour profile, the original file's exact
encoding — all preserved untouched alongside the original object.
Client-declared filename and MIME type are retained as metadata for
provenance but are never authoritative for any decision.

### 7. Validation and quarantine pipeline

Untrusted input — client MIME type, filename extension,
browser-supplied dimensions, EXIF, and any claimed checksum — is never
trusted for any decision. Validation is a single ordered pipeline, run
asynchronously during `verifying`:

1. **Object exists** — confirmed at `uploaded` via the storage layer,
   re-confirmed at the start of validation.
2. **Magic-byte / content-type inspection** — determines the *detected*
   format from actual file content, never from filename or client
   header.
3. **Decoder validation** — a bounded decode proves the file is a valid,
   well-formed instance of an accepted format (§8) and is not malformed
   or corrupt.
4. **Dimension / pixel-count validation** — a hard maximum byte size and
   a hard maximum pixel count (checked before or during a bounded decode,
   not after a full unbounded decode) reject decompression-bomb-shaped
   input.
5. **Checksum verification** — the SHA-256 in §6 is computed here.
6. **Malware scan** — a distinct pipeline stage, run against a
   **replaceable, configurable scanner implementation** behind an
   abstraction this ADR names but does not select (mirroring
   `PRODUCT_VISION.md`'s own `FaceRecognitionProvider`-style
   replaceable-provider pattern, applied here to malware scanning). The
   accepted risk profile for choosing *which* scanner, and how
   aggressively it runs, is genuinely narrower than a general
   file-upload product: accepted input is restricted to a small,
   image-only format allowlist (§8), decoded by a library that already
   rejects non-image content, submitted by an invite-only, known family
   membership — but the pipeline stage itself is architecturally
   required, not optional, and its presence (not its specific vendor) is
   what this ADR fixes. The concrete scanner and its failure posture
   (fail-open vs fail-closed, timeout behaviour) are implementation-guide
   decisions, made explicitly rather than defaulted silently.
7. **Preserve or quarantine** — success transitions to `preserved`
   (§4/§6); any pipeline failure transitions to `quarantined` with a
   recorded reason, and the object is retained only under the bounded
   quarantine window in §18.

### 8. Supported formats

Accepted original formats: **JPEG, PNG, HEIC/HEIF, WebP, and TIFF.**
JPEG/PNG/HEIC directly satisfy the roadmap's explicit mobile-format
requirement (HEIC specifically because it is the default capture format
for a large share of this product's international-family, phone-first
audience). WebP covers modern browser-native uploads. TIFF is included
deliberately, not by default expansion: `PRODUCT_VISION.md` names scanned
physical photo albums as a core use case, and flatbed scanners commonly
produce TIFF — excluding it would foreclose uploading the single most
archivally significant source (old physical photographs) in its native
scanned form. No other format is accepted in V1 — no BMP, no camera RAW,
no arbitrary `image/*`.

The original is preserved exactly regardless of whether a browser can
render it directly. TIFF specifically is never displayed to a user
as-is; Phase 5 always generates a browser-presentable canonical asset
and variants from it, per §1's preservation/presentation split.

### 9. Canonical asset

The canonical asset is a generated, presentation-safe working master:
correctly oriented (EXIF orientation applied, then discarded from the
canonical itself), colour-normalized to sRGB, and — per §1 and §10 —
stripped of privacy-sensitive metadata. Format: JPEG by default, PNG
where the source has meaningful alpha transparency that a flattened JPEG
would destroy. The canonical is fully regenerable from the preserved
original at any time by rerunning the same deterministic transform; it
is never itself treated as an archival record.

The canonical — not the original, not a variant — is the asset any
future image analysis (Phase 9+) is permitted to consume, satisfying
`PRODUCT_VISION.md`'s own requirement that "a stable canonical image
should be used for analysis" and that presentation-thumbnail changes must
not retrigger recognition. This ADR fixes that boundary now so Phase 9
inherits it rather than renegotiating it; it decides nothing else about
face recognition or any other analysis workflow.

### 10. Metadata extraction and privacy

Consistent with §1 and §6: **all** original metadata — full raw EXIF
(including GPS), ICC profile, everything the file contained — is
preserved untouched alongside the original object and is never
discarded. Separately, Phase 5 extracts a normalized technical subset
into queryable database columns on `MediaUpload`: width, height,
orientation, camera make/model, and EXIF capture timestamp where present.

**GPS is extracted into the database alongside the other normalized
fields, but is not exposed through ordinary API responses, UI responses,
canonical assets or presentation variants by default.** This is the
concrete application of §1's principle to location data specifically: the
coordinates are captured once (expensive to retrofit onto
already-processed originals later) and access is deliberately withheld by
default (cheap to loosen later once a real product decision exists about
who should see it and how) — neither silently discarding a real future
feature (`PRODUCT_VISION.md` names location search explicitly) nor
exposing precise home/travel coordinates by default to everyone with
ordinary Person-directory-equivalent visibility. An authorised download
of the preserved original is deliberately different: it returns the
byte-for-byte original and may therefore contain the original EXIF/GPS
metadata.

**The EXIF capture timestamp is technical metadata only and is never
silently promoted to an authoritative historical photo date.** A photo
scanned in 2026 of a 1987 print carries a 2026 EXIF timestamp — the
scanner's clock, not history. Phase 5 hands Phase 6 a technical fact
("the file's EXIF states X"); whether and how a human confirms an actual
historical date is entirely Phase 6's concern, governed by ADR-0006 §3's
already-accepted uncertain-date model. This boundary is stated explicitly
here because it is the single most likely shortcut a future
implementation could quietly take under time pressure, and doing so would
directly violate ADR-0006's human-confirmation principle.

Privacy-sensitive metadata (GPS foremost) is stripped from the canonical
asset and every variant — the presentation-safe derivatives §1 exists to
distinguish from the archival original never carry it forward.

### 11. Variants

A deliberately small, fixed, centrally-defined V1 variant set —
**thumbnail**, **card**, and **display** — generated from the canonical
asset, mirroring the same fixed-vocabulary discipline ADR-0006 §7 already
applies to relationship types. No responsive `srcset` explosion and no
speculative next-generation format (AVIF, etc.) in V1.

Variant identity is `(media_upload_id, transform_name,
processing_version)`; dimensions and format are implied by
`transform_name` for the fixed set. `processing_version` is what lets a
future change to processing code regenerate an entire variant set
unambiguously, without mutating the canonical or original and without
leaving an old cached reference ambiguous about which code produced it.
Variants carry no archival authority whatsoever and are freely,
automatically disposable and regenerable at any time.

### 12. Processing ownership: Laravel, not Python

Type validation, checksum calculation, EXIF extraction, orientation
correction, canonical generation and variant/thumbnail generation are all
deterministic media plumbing with no inference content, and belong to
Laravel jobs on the existing `fambam-jobs` queue — directly per ADR-0001,
not a reopening of it. Python remains reserved exclusively for genuine
AI/inference work introduced from Phase 9 onward.

The chosen image-processing implementation must support this ADR's
accepted format set in full, including HEIC/HEIF decoding specifically
— a real constraint some common PHP image-processing setups do not
satisfy out of the box. The exact library and any required system
dependencies are an implementation-guide and deployment-environment
decision, not fixed here.

### 13. Queue and idempotency model

Every Phase 5 asynchronous job carries the existing
`TenantOperationContext` envelope (`family_space_id`, `actor_user_id`,
`correlation_id`, `traceparent`) unchanged, extended only with
job-specific identifiers (`media_upload_id`, and the source checksum or
processing version where relevant) — no competing or parallel async
envelope is introduced. Job uniqueness is enforced the same way
`DeleteFamilySpace` already does (`ShouldBeUnique` keyed by
`media_upload_id` and pipeline stage), and every state transition is
additionally guarded by a conditional database update, so uniqueness and
state-guarding both protect against duplicate or replayed queue messages
rather than relying on either alone.

### 14. Bulk upload

Bulk upload is **N independent `MediaUpload` rows, optionally sharing a
nullable `upload_batch_id` for presentation grouping only.** There is no
batch-level transaction and no all-or-nothing semantics: every upload in
a batch has its own state (§4), its own retries, and its own independent
success or failure, exactly as §3 already requires. `upload_batch_id`
exists purely so a client can present "12 of 40 uploaded, 2 failed,
retry?" — it confers no additional coupling between the uploads it
groups.

### 15. Secure delivery

Media is served only through short-lived, key-scoped, signed URLs issued
by Laravel after an explicit authorisation check — never a public bucket,
never a public object ACL, never a stable unauthenticated object URL. A
guessed or enumerated object key confers no access without a valid
signature. Access is checked per request against the requester's current
Family Space membership and role, matching the fail-closed pattern
already established throughout ADR-0004 and ADR-0005.

**Viewing canonical and variant assets** follows the same
Owner/Administrator/Member-by-default, Contributor/Guest-no-default
visibility tier already established for the Person directory in
ADR-0006 §10, for consistency — Phase 5 introduces no new information
that would justify diverging from an already-accepted baseline.

**Downloading the preserved original** is available to **Owner,
Administrator, and Member** alike. Members are trusted family
participants under this product's own tenancy model — `PRODUCT_VISION.md`
lists "downloadable originals" as an ordinary international-family-sharing
feature with no stated role restriction, and gating something a Member
can already *view* behind a stricter download-only permission would add a
distinction the product itself doesn't ask for. The privacy boundary this
ADR relies on is not "prevent Members from downloading their own family's
photographs"; it is §10's presentation-layer metadata-exposure control.
GPS and other privacy-sensitive data are withheld from ordinary APIs, UI
responses, canonical assets and presentation variants. The authorised
original download deliberately returns the untouched archival file and
may therefore expose metadata embedded in that original, including EXIF
GPS. This is the same §1 principle applied to delivery: the *file* is
preserved and available to legitimate family participants; what gets
*exposed about* that file through ordinary application surfaces is the
separately controlled thing. Contributor and Guest receive no default
access to either variants or originals, per §16.

Signed URLs are scoped to one object key, short-lived, and carry no
bucket- or prefix-wide grant. Range requests are permitted for both
variant and original delivery, supporting progressive loading and simple
resumable downloads at no additional design cost.

### 16. Contributor and Guest authorization baseline

Phase 5 has no Album, Event, or other resource to scope a Contributor
grant against — those arrive in Phase 6 and Phase 7 respectively.
Adopting anything resembling "Contributor may upload through an
authorised contribution surface" in this ADR would name a mechanism
Phase 5 implementation has no resource to build against. Stated plainly
instead:

**Contributor upload authority is deferred until a concrete contribution
surface exists (an Album, an Event, or an equivalent Phase 6/7
resource).** Phase 5 intentionally introduces no Contributor upload path
because it has no resource against which to scope that permission. This is
a deliberate architectural placeholder, matching the same pattern ADR-0005
and ADR-0006 already used for Contributor and Guest — not a permanent
prohibition. It must be revisited when Phase 6 Albums and Phase 7 Events
introduce concrete resource-level permissions.

**Guest remains unchanged**: no default access to upload, view, or
download media in Phase 5, consistent with Guest's baseline throughout
ADR-0005 and ADR-0006.

Owner, Administrator, and Member may upload to the Family Space directly
— the one concrete, buildable grant Phase 5 actually has a resource for
(the Family Space itself), checked the same way `PersonPolicy`'s
directory-access check already works.

| Action | Owner | Administrator | Member | Contributor | Guest |
|---|---:|---:|---:|---:|---:|
| Upload to Family Space | yes | yes | yes | deferred — Album/Event resource grant (Phase 6/7) | no |
| View variants/canonical | yes | yes | yes | no default | no |
| Download preserved original | yes | yes | yes | no | no |
| View own upload status/failures | yes | yes | yes | n/a | n/a |
| View quarantine/rejection reason | yes | yes | no | no | no |

### 17. Audit and observability

`AuditEvent` (the same mechanism ADR-0005 §11 and ADR-0006 §15 already
extend) records meaningful archival actions: upload initiated, original
accepted (`→ preserved`), original rejected/quarantined, and original
download authorised. `original_download_authorised` is written only after
Laravel successfully authorises access and issues the short-lived signed
URL. It records the authority Laravel actually granted, not a claim that
the object-storage GET subsequently occurred. An `original_downloaded`
event must not be recorded unless a later design introduces object-storage
access-event or access-log ingestion that can truthfully observe delivery.
Such delivery logging is a later observability/security enhancement, not a
Phase 5 requirement. `AuditEvent` does not record every completion-signal
receipt, every variant/thumbnail view, or per-job processing outcome —
those are high-frequency, non-trust-bearing events whose durable recording
would turn `AuditEvent` into a high-volume access log with no corresponding
archival value, exactly the outcome ADR-0005/0006's own audit scoping
already avoids elsewhere. Processing durations, decoder failures, retry
counts, and variant-generation latency are OpenTelemetry spans and
metrics, per the existing ADR-0003 observability baseline — operational
telemetry, not archival provenance.

### 18. Cleanup and lifecycle

- **`initiated`** rows past a bounded expiry (default **24 hours**)
  transition to `abandoned` via a scheduled sweep, using the same
  scheduler-discovers-due-work shape `FamilySpaceDeletionManager` already
  established. Any outstanding upload authorisation is naturally expired
  by the storage layer regardless.
- **`quarantined`** objects are retained under `quarantine/` for a bounded
  window (default **7 days**) — long enough for deliberate human review
  of a rejection pattern, short enough not to accumulate rejected content
  indefinitely — then purged. The `MediaUpload` row may outlive the
  object; the fact of the rejection retains audit value after the bytes
  are gone.
- **No ordinary Phase 5 media operation may delete a preserved
  original.** Phase 5 has no standalone delete path for a
  `preserved`-or-later `MediaUpload`. ADR-0005's already-authorised Family
  Space deletion lifecycle is the explicit exception: tenant teardown
  must eventually remove that Family Space's media objects and
  Phase-5-owned rows idempotently as part of deleting the tenant. This
  exception creates no standalone media-delete operation and does not
  alter Phase 6's future Photo deletion/restoration domain. Export, backup
  and long-term retention remain Phase 13 concerns.
- **Variants are the one exception**: freely, automatically disposable
  and regenerable at any time, carrying no archival authority, per §11.

## Alternatives considered

- **Fixing the upload-authority mechanism (presigned PUT vs. POST
  policy) at the ADR level** — rejected: no durable architectural
  consequence follows from either choice once the actual requirement
  (§3: one object, one key, bounded time, nothing more) is fixed; this is
  implementation-guide detail.
- **Treating "an upload can exist without a Photo" as unnecessary and
  requiring immediate Photo creation on upload** — rejected: would force
  Phase 5 to forward-reference a Phase 6 entity that doesn't exist yet,
  and would prevent an Owner from ever rejecting an upload without also
  deleting Phase 6 state that shouldn't exist.
- **Deferring malware scanning entirely, as a future review item** —
  rejected: the pipeline stage is architecturally required regardless of
  which scanner implementation is selected later; naming it as a
  replaceable stage now (rather than a gap to notice later) is a small
  cost and keeps the pipeline honestly complete.
- **Excluding TIFF from V1** — rejected: would foreclose uploading the
  most archivally significant source this product exists to serve
  (scanned physical photo albums) in its native format.
- **Discarding GPS entirely rather than extracting-but-withholding** —
  rejected: forecloses a real, already-named future feature (location
  search) and cannot be retrofitted onto already-processed originals
  without reprocessing every prior upload.
- **Restricting original download to Owner/Administrator only** —
  considered and rejected: Members are trusted family participants under
  this product's own tenancy model, and `PRODUCT_VISION.md` names
  original downloads as an ordinary sharing feature; the privacy boundary
  belongs at the metadata-exposure layer (§10), not by restricting
  legitimate Members from their own family's photographs.
- **Hard-coding a specific PHP image-processing library in this ADR** —
  rejected: the durable requirement is functional (support the accepted
  format set, including HEIC/HEIF); the specific library and its system
  dependencies are an implementation-guide and deployment concern that
  may reasonably change without reopening this ADR.
- **Implementing S3 multipart upload now, since bulk upload is in this
  phase** — rejected: bulk (many files) and multipart (one very large
  file split across requests) are different concerns; V1 photograph sizes
  do not need multipart, and the design accommodates adding it later
  without redesign.
- **Giving Contributor a Family-Space-wide upload grant in Phase 5,
  matching Owner/Administrator/Member** — rejected: Contributor's whole
  premise, per ADR-0005/0006, is resource-scoped contribution; granting
  unrestricted Family Space upload would exceed that premise and
  pre-empt Phase 6/7's actual resource-grant design. This rejects only an
  unscoped Phase 5 grant, not the later Album- or Event-scoped upload
  permissions those phases must decide.
- **A single `MediaAsset` table collapsing original/canonical/variant
  identity into one row** — rejected: original, canonical and variants
  have materially different lifecycle, mutability and preservation
  guarantees (§1); collapsing them would make "originals are never
  silently overwritten" harder to enforce structurally rather than
  easier.

## Consequences

### Positive

- The `MediaUpload`/`Photo` reference direction means Phase 5 is fully
  buildable, testable, and verifiable against its own roadmap exit
  criteria without any Phase 6 stub, and Phase 6 inherits a stable,
  already-verified identity to build on.
- The `uploaded`/`preserved` split, combined with conditional
  state-guarded transitions, makes "uploading the same completion event
  twice is safe" a structural property rather than a hoped-for outcome.
- Naming preservation-vs-presentation as one explicit principle (§1) gives
  every future Phase 5 extension (a new metadata field, a new delivery
  surface, a new format) one consistent question to answer, instead of
  five independently-derived policies.
- Fixing "canonical, not original, not variants" as Phase 9's permitted
  analysis input now means Phase 9 inherits a settled boundary instead of
  renegotiating one under real inference-provider pressure later.
- Reusing `TenantOperationContext`, `AuditEvent`, the Class C RLS pattern,
  and the `families/{ulid}/...` partition means Phase 5 introduces no new
  tenancy, audit, or async infrastructure — only new tables, jobs and
  policies following an already-proven shape.

### Negative

- The malware-scanning pipeline stage (§7) is real ongoing infrastructure
  surface — a scanner must be selected, configured, and kept
  operational — even though its risk profile is narrower than a general
  file-upload product.
- GPS extraction-but-withholding (§10) means Phase 5 carries real
  location data in the database from V1 onward, with the actual UI/access
  decision about who may ever see it deferred rather than resolved now —
  a genuine privacy-relevant maintenance obligation for whichever later
  phase revisits it.
- Every future Person/Photo-referencing table that touches media must
  remember the original/canonical/variant distinction and the
  metadata-exposure boundary (§1, §10); getting this wrong in a later
  phase (e.g. surfacing GPS through a new endpoint without deliberate
  review) would quietly violate this ADR's central principle without
  necessarily being caught by any existing test.
- Deferring Contributor's Phase 5 upload path (§16) means Phase 6 and/or
  Phase 7 each carry an explicit obligation to revisit this placeholder
  with a real Album or Event resource in view, rather than inheriting a
  permanent prohibition or a finished design.

### Risks

- If the selected PHP image-processing implementation does not actually
  support HEIC/HEIF decoding (a real, common gap for default PHP image
  toolchains), the roadmap's own HEIC verification requirement cannot be
  satisfied regardless of how correctly everything else in this ADR is
  implemented — this should be confirmed directly against the chosen
  library during FPA-P05-S04, not assumed.
- If a future implementation shortcut treats the EXIF capture timestamp
  as an authoritative historical date instead of Phase 6 human-confirmed
  input, §10's boundary is silently violated — worth a specific test
  asserting the two remain distinct once Phase 6 exists.
- If the malware-scanning stage (§7) is implemented as a rubber-stamp
  pass-through rather than a real check, the pipeline would appear
  complete while providing no actual protection — the scanner's presence
  should be verified by a test that a known-bad signature is actually
  rejected, not merely that the stage exists.
- If GPS access controls (§10) are loosened later without a deliberate
  product decision — for example, a new endpoint incidentally including
  raw EXIF — the withhold-by-default guarantee this ADR establishes could
  erode silently rather than through an explicit, reviewed change.

## Implementation notes

- **FPA-P05-S02** implements §2 (`MediaUpload` schema), §3 (upload
  authorisation and the application-owned storage abstraction service
  ADR-0002 deferred), §4 (`initiated`→`uploaded` transitions and
  idempotent completion), §5 (extension-independent staging-key
  generation), §13 (`TenantOperationContext` reuse), and the
  upload-initiated audit event (§17). The concrete bounded upload authority
  and object-storage API remain implementation decisions, but S02 must
  establish the write-once boundary that later finalisation relies on.
- **FPA-P05-S03** implements §4 (`verifying`→`preserved`/`quarantined`),
  §6 (SHA-256 and original immutability), §7 (the full validation and
  quarantine pipeline, including the malware-scan stage), §8 (accepted
  format allowlist), detected-format staging-to-final/quarantine
  finalisation (§5), the accepted/rejected audit events (§17), and
  quarantine retention (§18).
- **FPA-P05-S04** implements §9 (canonical generation, orientation,
  sRGB, metadata stripping), §10 (raw metadata preservation, normalized
  technical columns, GPS extraction-and-withholding), and §12 (Laravel
  image-processing ownership and its HEIC/HEIF requirement).
- **FPA-P05-S05** implements §11 (variant set and identity), §12 (job
  ownership), and §4 (`processing`→`ready`/`degraded`).
- **FPA-P05-S06** implements §15 (signed-URL delivery, the
  Owner/Administrator/Member original-download baseline), §16
  (Contributor/Guest baseline), and the
  `original_download_authorised` audit event (§17).
- **FPA-P05-S07** implements §14 (batch grouping, independent per-item
  success/failure), the `degraded`/`abandoned` retry paths (§4), and the
  abandoned-upload sweep (§18). Phase 5 must also extend ADR-0005's
  idempotent tenant teardown to remove the Family Space's media-object
  prefix and Phase-5-owned rows; this remains tenant deletion, not a
  standalone media-delete feature.
- **Required immutability regression** — reuse or racing of upload
  authority must be tested directly and must not replace an already
  preserved original. The test must prove object-byte immutability rather
  than only asserting an idempotent database state.
- §1's governing principle is cross-cutting: it is not implemented by any
  single stage, and every stage above should be checked against it during
  review rather than treated as satisfied once written.

## Review triggers

- **When Phase 6 (Photo domain) is scoped**: confirm `Photo.media_upload_id`
  is the only reference direction, that Phase 6 never re-derives or
  duplicates Phase 5's technical metadata, and that the EXIF-timestamp/
  historical-date boundary (§10) is actually enforced, not merely
  documented.
- **When Phase 6 or Phase 7 first needs Contributor upload semantics**:
  revisit §16's deferred Contributor baseline with a real Album or Event
  resource in view, per its own explicit placeholder status.
- **When Phase 7 (Events) introduces guest upload links**: revisit
  Guest's baseline (§16) and the malware-scanning risk profile (§7),
  since a guest-link contribution surface is a meaningfully different
  trust level than today's invite-only membership.
- **When Phase 8 (duplicate detection) is scoped**: confirm the SHA-256
  checksum (§6) is reused directly rather than a second checksum
  mechanism being introduced, and that perceptual-hash/duplicate-index
  design stays Phase 8's own concern.
- **When Phase 9 (local face analysis) is scoped**: confirm the canonical
  asset (§9) remains the sole permitted analysis input, and that
  Python's access to it (however implemented) does not reopen ADR-0001's
  no-database, message-only boundary.
- **When any phase introduces a large-file or video requirement**:
  revisit the deferred multipart-upload decision (§3) with a real size
  requirement in view.
- **Before Phase 15 (production deployment)**: confirm the malware
  scanner selected in implementation is actually deployed and functional
  in production, not only locally; confirm GPS access controls (§10)
  match the intended production privacy posture.

## Deferred concerns

- Multipart upload for very large files (§3) — architecturally
  accommodated, not built.
- The specific malware-scanner implementation and its fail-open/
  fail-closed posture (§7) — the pipeline stage is fixed, the vendor is
  not.
- The specific PHP image-processing library and its system dependencies
  (§12) — a functional requirement is fixed, not an implementation.
- CDN integration for signed-URL delivery (§15) — not precluded, not
  decided.
- Responsive `srcset` variants and next-generation image formats (AVIF,
  etc.) beyond the fixed V1 variant set (§11).
- Any UI/product decision about if and how GPS data is ever surfaced to
  users (§10) — extraction and withholding are fixed; exposure is not.
- Contributor's concrete resource-scoped upload path (§16) — Phase 6/7.
- Exact retention windows (§18: 24-hour abandoned expiry, 7-day
  quarantine retention) — defaults, not considered product decisions.

## Resolved decisions

1. **Phase 5/6 boundary** — `MediaUpload`/`MediaVariant` are Phase-5-owned,
   infrastructure-named entities; Phase 6's `Photo` references
   `MediaUpload`, never the reverse; an upload may permanently exist
   without becoming a Photo.
2. **Upload authority** — the browser receives bounded authority to
   upload exactly one object to exactly one server-generated key for a
   limited time; the specific mechanism (presigned PUT vs. POST) is
   implementation detail. Bulk upload (many files) and multipart upload
   (one large file) are distinct concerns; only bulk is built in Phase 5.
3. **State machine** — `initiated / uploaded / verifying / preserved /
   processing / ready / quarantined / abandoned / degraded`, with
   `uploaded` (arrived, untrusted) and `preserved` (verified, immutable)
   as the load-bearing distinction; every transition idempotent and
   state-guarded.
4. **Object keys** — upload authority targets an extension-independent,
   tenant-scoped staging key; verified and quarantined material is
   finalised under its corresponding hierarchy using only the
   server-detected format, with no filenames, Photo identifiers, claimed
   extensions, declared MIME types, or mutable slugs in any key.
5. **Integrity** — SHA-256 of the exact original bytes, computed
   server-side, frozen once `preserved`; the final object is protected by
   a verified write-once-equivalent mechanism, not database state alone.
6. **Validation pipeline** — object-exists, magic-byte, decoder,
   dimension/pixel, checksum, and malware-scan stages, in order, ending
   in preserve-or-quarantine; the scanner implementation is replaceable
   and configurable.
7. **Supported formats** — JPEG, PNG, HEIC/HEIF, WebP, TIFF as accepted
   originals; a generated canonical asset makes every accepted format
   browser-presentable without altering the original.
8. **Canonical asset** — a regenerable, oriented, sRGB, metadata-stripped
   presentation master; the sole permitted future AI-analysis input.
9. **Metadata** — all original metadata (EXIF, GPS, ICC profile)
   preserved untouched; a normalized technical subset extracted to the
   database; GPS extracted but withheld from ordinary surfaces by
   default; EXIF capture timestamp never treated as an authoritative
   historical date.
10. **Variants** — a fixed V1 set (thumbnail/card/display), identified by
    upload, transform and processing version; freely disposable and
    regenerable.
11. **Processing ownership** — deterministic media plumbing is Laravel's;
    Python remains reserved for genuine inference from Phase 9 onward;
    the chosen image library must support the full accepted format set
    including HEIC/HEIF.
12. **Async model** — `TenantOperationContext` reused unchanged; job
    uniqueness plus state-guarded transitions together prevent duplicate
    processing.
13. **Bulk upload** — many independent `MediaUpload` rows, optionally
    grouped by a presentation-only batch identifier; no all-or-nothing
    semantics.
14. **Secure delivery** — short-lived signed URLs only, no public ACLs;
    variant/canonical viewing follows the existing Person-directory
    visibility tier; preserved-original download is available to Owner,
    Administrator, **and Member** alike.
15. **Contributor/Guest baseline** — Contributor upload authority is
    explicitly deferred until a concrete contribution surface (Album,
    Event, or equivalent) exists in Phase 6/7; Phase 5 introduces no
    Contributor upload path because no resource exists to scope it to.
    This is an architectural placeholder, not a permanent prohibition,
    and Phase 6/7 must revisit it. Guest remains unchanged (no default
    access).
16. **Audit** — `AuditEvent` covers upload-initiated, original-accepted,
    original-rejected/quarantined, and
    `original_download_authorised`; no actual-download event exists unless
    later object-storage access ingestion can observe delivery truthfully;
    everything else is OpenTelemetry operational telemetry.
17. **Cleanup** — 24-hour abandoned-upload expiry, 7-day quarantine
    retention, both defaults; no ordinary Phase 5 operation deletes a
    preserved original, while ADR-0005 tenant teardown is the explicit,
    idempotent exception for the Family Space's media objects and
    Phase-5-owned rows; variants remain freely disposable.
18. **Governing principle** — preservation rights and presentation rights
    are separate concerns; the archival original is preserved completely
    and exactly, including all embedded metadata; ordinary APIs, UI
    responses, canonical assets and variants deliberately withhold
    privacy-sensitive metadata, while an authorised original download
    deliberately receives the untouched original and may expose its
    original EXIF/GPS.
