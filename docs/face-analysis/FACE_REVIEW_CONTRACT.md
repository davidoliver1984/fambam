# Face review production contract

This document fixes the product semantics consumed by every face-review entry
point. The canonical read route is `GET /api/families/{family}/face-review`,
optionally scoped by exactly one of `photo_id` or `upload_batch_id`. Skipping or
finishing writes nothing; resumption is always derived from the durable state
below.

## Observation state and progress

Exactly one bounded `review_state` is presented for each observation:

| `review_state` | Canonical cause | Reviewed? |
|---|---|---:|
| `unreviewed` | Detection with no active claim or disposition, including only rejected, withdrawn, or superseded history | no |
| `automatic_suggestion` | Active pending non-human assignment | no |
| `human_proposal` | Active pending assignment explicitly proposed by a human | yes |
| `approved_identity` | Active approved assignment | yes |
| `left_unidentified` | Durable explicit observation disposition and no active assignment | yes |

Recognition output is never authoritative merely because it exists. The
canonical system does not create an approved machine identity: an approved row
always records a separate Owner/Administrator approval. Rejected, withdrawn,
and superseded assignments are durable history and do not count as reviewed.

The active assignment uniqueness rule remains one pending-or-approved row per
observation. A replacement never overwrites or deletes the prior row. It marks
that row `superseded`, records who and when, creates a new pending human row,
and audits both sides in one transaction.

Transitions:

- unreviewed, automatic suggestion, left unidentified, or a pending human
  proposal -> human selection: create a pending human proposal; clear
  `left_unidentified`; supersede any active proposal first;
- pending human proposal -> different or same Person: supersede it and create a
  fresh pending human proposal;
- approved identity -> correction: Owner/Administrator only; supersede the
  approved face claim and create a fresh pending human proposal. The correction
  is not auto-approved;
- pending machine suggestion -> left unidentified: withdraw the suggestion
  without suppression, then persist `left_unidentified`;
- pending human proposal -> left unidentified: supersede the proposal, then
  persist `left_unidentified`;
- approved identity -> left unidentified: rejected; an authoritative identity
  must first use the correction workflow;
- reject: Owner/Administrator only, changes a pending assignment to `rejected`
  and preserves the existing pair-specific suppression semantics;
- approve: Owner/Administrator only, changes a pending assignment to approved
  and performs the existing one-way `PhotoPerson` ensure operation.

`left_unidentified` creates neither a Person, a suppression, nor negative
recognition evidence. A later human proposal deletes that disposition in the
same transaction.

## Roles

| Action | Owner | Administrator | Member | Contributor | Guest |
|---|---:|---:|---:|---:|---:|
| Read visible face review and Person choices | yes | yes | yes | no | no |
| Create Person | yes | yes | yes (provisional) | no | no |
| Propose identity / change pending proposal | yes | yes | yes | no | no |
| Leave unidentified | yes | yes | yes | no | no |
| Approve or reject identity | yes | yes | no | no | no |
| Correct approved identity | yes | yes | no | no | no |

All operations additionally require Photo visibility, Person directory access,
and same-Family-Space tenant scope. A User is never inferred to be a Person.

## Read contract

Each observation exposes canonical-pixel `bounds`, a bounded `review_state`,
`reviewed`, `suggested_people`, `current_proposal`, `current_identity`, and
explicit `permissions`. `identity_assignment` remains the active-assignment
compatibility field and must not be used to infer progress. Person identifiers
and names are returned only after both Person-directory and Photo visibility
authorization.

`media.canonical_width` and `media.canonical_height` define the coordinate basis
for `bounds`. `presentation_width` and `presentation_height` describe the
delivered display variant. A client scales each axis from canonical pixels into
the rendered image content box; if it uses `object-fit`, it must apply that
content box's scale and offset rather than the outer element dimensions.

Per-Photo `analysis.review_state` is one of `pending`, `processing`,
`succeeded_with_zero_faces`, `succeeded_with_unresolved_faces`,
`succeeded_with_all_faces_resolved`, or `failed`. The batch CTA is shown only
when `remaining_count > 0`; pending analysis is reported separately and never
fabricated as reviewable work.

Photos are ordered by upload creation time, media-upload id, then Photo id.
Observations are ordered by face index, then observation id. The next Photo is
the next ordered Photo with `remaining_count > 0`. Photo scope returns only the
authorized requested Photo; batch scope joins only Photos whose MediaUpload has
the requested `upload_batch_id`.

## Writes, audit, and notifications

Existing typed routes own Person creation, assignment/proposal, approval,
rejection, and leaving unidentified. Creating a new Person is a normal persisted
Person write followed by the same assignment write used for an existing Person;
there is no local-only identity.

Human proposals, approvals, rejections, replacements, withdrawals caused by a
human disposition, and `left_unidentified` are audited. Proposals and
corrections do not send notifications. The existing approval path alone ensures
the Photo-level Person fact and uses its existing identity notification policy,
so replacements do not create notification noise.

The read model uses one aggregate query for progress/navigation and bounded
queries for the requested Photo page, active analysis runs plus observations,
People attached to active assignments, reviews, and display variants. It has no
per-face HTTP calls, per-Photo aggregation loop, or per-suggestion Person
request.

This is a clarification and additive transition within the identity-history
model fixed by ADR-0012; it does not introduce a second authoritative identity
model and requires no new ADR.
