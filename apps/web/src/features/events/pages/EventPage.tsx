import { useState, type SyntheticEvent } from "react";
import { Link, useNavigate, useParams } from "react-router";

import { Breadcrumbs, ButtonLink, Surface } from "@/components/ui";
import { PhotoPresentationImage } from "@/features/photos/components/PhotoPresentationImage";
import {
  useEventAdmissionMutations,
  useEventAdmissionsQuery,
  useEventExportMutations,
  useEventExportsQuery,
  useDuplicateEventCandidatesQuery,
  useCreateEventAlbumMutation,
  useDeleteEventMutation,
  useEventQuery,
  useUpdateEventMutation,
} from "../hooks/useEventQueries";
import { useIssueInvitationMutation } from "@/features/invitations/hooks/useInvitationMutations";
import { LoveButton } from "@/features/love/components/LoveButton";
import { useFamilyMembershipsQuery } from "@/features/people/hooks/useAccountLinkQueries";
import type { GuestParticipation } from "@/features/albums/types/album";
import { EventRsvpPanel } from "../components/EventRsvpPanel";
import { EventTagsEditor } from "../components/EventTagsEditor";
import "./events.css";

export function EventPage() {
  const { familySlug = "", eventId = "" } = useParams();
  const navigate = useNavigate();
  const event = useEventQuery(familySlug, eventId);
  const canReviewDuplicates =
    event.data?.permissions.can_review_duplicates === true;
  const canManageAdmissions =
    event.data?.permissions.can_manage_admissions === true;
  const canManageExports = event.data?.permissions.can_manage_exports === true;
  const duplicates = useDuplicateEventCandidatesQuery(
    familySlug,
    eventId,
    canReviewDuplicates,
  );
  const admissions = useEventAdmissionsQuery(
    familySlug,
    eventId,
    canManageAdmissions,
  );
  const memberships = useFamilyMembershipsQuery(
    familySlug,
    canManageAdmissions,
  );
  const admissionMutations = useEventAdmissionMutations(familySlug, eventId);
  const exports = useEventExportsQuery(familySlug, eventId, canManageExports);
  const exportMutations = useEventExportMutations(familySlug, eventId);
  const issueInvitation = useIssueInvitationMutation(familySlug);
  const update = useUpdateEventMutation(familySlug, eventId);
  const remove = useDeleteEventMutation(familySlug, eventId);
  const createAlbum = useCreateEventAlbumMutation(familySlug, eventId);
  const [albumName, setAlbumName] = useState("");
  const [guestParticipation, setGuestParticipation] =
    useState<GuestParticipation>("none");
  const [membershipId, setMembershipId] = useState("");
  const [guestEmail, setGuestEmail] = useState("");
  if (event.isPending) return <p role="status">Loading Event…</p>;
  if (event.isError) return <p role="alert">This Event could not be loaded.</p>;
  const item = event.data;
  const preview = item.presentation?.preview;
  const dateRange =
    item.starts_on === null
      ? "Date not recorded"
      : item.ends_on === null || item.ends_on === item.starts_on
        ? item.starts_on
        : `${item.starts_on} – ${item.ends_on}`;
  return (
    <main className="event-detail" aria-labelledby="event-title">
      <Breadcrumbs
        items={[
          {
            label: "Events",
            to: `/families/${encodeURIComponent(familySlug)}/events`,
          },
          { label: item.name },
        ]}
      />
      <section className="event-hero">
        {preview !== undefined && preview !== null && (
          <PhotoPresentationImage
            familySlug={familySlug}
            photoId={preview.photo_id}
            mediaUploadId={preview.media_upload_id}
            fallbackTransform="display"
            alt=""
          />
        )}
        <div className="event-hero__shade" />
        <div className="event-hero__content">
          <p className="ui-eyebrow">Family event</p>
          <h1 id="event-title">{item.name}</h1>
          <p className="event-hero__meta">
            {dateRange} · {item.location ?? "Location not recorded"}
          </p>
          <div className="event-hero__actions">
            <LoveButton
              familySlug={familySlug}
              targetType="event"
              targetId={eventId}
            />
            {item.permissions.can_update && (
              <ButtonLink to="#event-settings" variant="secondary">
                Event settings below
              </ButtonLink>
            )}
          </div>
        </div>
      </section>
      <div className="event-overview">
        <div className="event-overview__story">
          {item.description !== null ? (
            <p>{item.description}</p>
          ) : (
            <p className="event-overview__muted">
              No description has been recorded for this event yet.
            </p>
          )}
          <EventTagsEditor
            familySlug={familySlug}
            tags={item.tags}
            canEdit={item.permissions.can_update}
            pending={update.isPending}
            saveError={update.isError}
            onSave={(tags) => update.mutateAsync({ tags })}
          />
        </div>
        <div className="event-stats" aria-label="Event summary">
          <Surface>
            <strong>{item.presentation?.photo_count ?? 0}</strong>
            <span>photographs</span>
          </Surface>
          <Surface>
            <strong>
              {item.presentation?.album_count ?? item.albums?.length ?? 0}
            </strong>
            <span>albums</span>
          </Surface>
          <Surface>
            <strong>
              {item.presentation?.people_count ?? item.attendees?.length ?? 0}
            </strong>
            <span>people</span>
          </Surface>
        </div>
      </div>
      <EventRsvpPanel familySlug={familySlug} eventId={eventId} />
      {item.permissions.can_delete && (
        <button
          type="button"
          disabled={remove.isPending}
          onClick={() => {
            remove.mutate(undefined, {
              onSuccess: () => {
                void navigate(
                  `/families/${encodeURIComponent(familySlug)}/events`,
                );
              },
            });
          }}
        >
          Remove Event
        </button>
      )}
      {item.permissions.can_update && (
        <form
          id="event-settings"
          onSubmit={(submitEvent: SyntheticEvent<HTMLFormElement>) => {
            submitEvent.preventDefault();
            const form = new FormData(submitEvent.currentTarget);
            const status = form.get("status");
            if (typeof status === "string") {
              update.mutate({ status: status as typeof item.status });
            }
          }}
        >
          <label htmlFor="event-status">Update status</label>
          <select id="event-status" name="status" defaultValue={item.status}>
            <option value="planned">Planned</option>
            <option value="active">Active</option>
            <option value="completed">Completed</option>
            <option value="archived">Archived</option>
          </select>
          <button type="submit" disabled={update.isPending}>
            Save Event
          </button>
        </form>
      )}
      <section aria-labelledby="event-albums">
        <h2 id="event-albums">Albums</h2>
        {item.albums?.length ? (
          <ul>
            {item.albums.map((album) => (
              <li key={album.id}>
                <Link
                  to={`/families/${encodeURIComponent(familySlug)}/albums/${album.id}?eventId=${encodeURIComponent(eventId)}`}
                >
                  {album.name}
                </Link>{" "}
                ({album.guest_participation})
              </li>
            ))}
          </ul>
        ) : (
          <p>No Albums are linked yet.</p>
        )}
        {item.permissions.can_create_album && (
          <form
            onSubmit={(submitEvent) => {
              submitEvent.preventDefault();
              const name = albumName.trim();
              if (name !== "")
                createAlbum.mutate(
                  { name, guestParticipation },
                  {
                    onSuccess: () => {
                      setAlbumName("");
                    },
                  },
                );
            }}
          >
            <label htmlFor="event-album-name">New Event Album name</label>
            <input
              id="event-album-name"
              value={albumName}
              onChange={(changeEvent) => {
                setAlbumName(changeEvent.target.value);
              }}
              required
            />
            <label htmlFor="event-album-guest-participation">
              Guest access
            </label>
            <select
              id="event-album-guest-participation"
              value={guestParticipation}
              onChange={(changeEvent) => {
                setGuestParticipation(
                  changeEvent.target.value as GuestParticipation,
                );
              }}
            >
              <option value="none">No Guest access</option>
              <option value="view">View and download</option>
              <option value="contribute">View, download and upload</option>
            </select>
            <button type="submit" disabled={createAlbum.isPending}>
              Create Event Album
            </button>
          </form>
        )}
      </section>
      {item.permissions.can_manage_admissions && (
        <section aria-labelledby="event-access">
          <h2 id="event-access">Event access</h2>
          <form
            onSubmit={(submitEvent) => {
              submitEvent.preventDefault();
              const email = guestEmail.trim();
              if (email !== "")
                issueInvitation.mutate(
                  { email, event_id: eventId },
                  {
                    onSuccess: () => {
                      setGuestEmail("");
                    },
                  },
                );
            }}
          >
            <label htmlFor="event-guest-email">Invite a Guest by email</label>
            <input
              id="event-guest-email"
              type="email"
              value={guestEmail}
              onChange={(changeEvent) => {
                setGuestEmail(changeEvent.target.value);
              }}
              required
            />
            <button type="submit" disabled={issueInvitation.isPending}>
              Send Event invitation
            </button>
          </form>
          <form
            onSubmit={(submitEvent) => {
              submitEvent.preventDefault();
              const id = membershipId.trim();
              if (id !== "")
                admissionMutations.admit.mutate(id, {
                  onSuccess: () => {
                    setMembershipId("");
                  },
                });
            }}
          >
            <label htmlFor="event-membership-id">Admit a family member</label>
            <select
              id="event-membership-id"
              value={membershipId}
              onChange={(changeEvent) => {
                setMembershipId(changeEvent.target.value);
              }}
              required
            >
              <option value="">Choose a person</option>
              {memberships.data
                ?.filter(
                  (membership) =>
                    membership.state === "active" &&
                    !admissions.data?.some(
                      (admission) =>
                        admission.membership_id === membership.id &&
                        admission.revoked_at === null,
                    ),
                )
                .map((membership) => (
                  <option key={membership.id} value={membership.id}>
                    {membership.user.name} ({membership.role})
                  </option>
                ))}
            </select>
            {memberships.isPending && (
              <p role="status">Loading family members…</p>
            )}
            {memberships.isError && (
              <p role="alert">Family members could not be loaded.</p>
            )}
            <button type="submit" disabled={admissionMutations.admit.isPending}>
              Admit to Event
            </button>
          </form>
          {admissions.isPending ? (
            <p role="status">Loading admissions…</p>
          ) : admissions.isError ? (
            <p role="alert">Event admissions could not be loaded.</p>
          ) : (
            <ul>
              {admissions.data.map((admission) => (
                <li key={admission.id}>
                  {admission.user.name} ({admission.role}) —{" "}
                  {admission.revoked_at === null ? "admitted" : "revoked"}
                  {admission.revoked_at === null && (
                    <button
                      type="button"
                      onClick={() => {
                        admissionMutations.revoke.mutate(
                          admission.membership_id,
                        );
                      }}
                    >
                      Revoke
                    </button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </section>
      )}
      {item.permissions.can_create_album && (
        <section aria-labelledby="event-attendees">
          <h2 id="event-attendees">People in confirmed photos</h2>
          {item.attendees?.length ? (
            <ul>
              {item.attendees.map((person) => (
                <li key={person.id}>
                  <Link
                    to={`/families/${encodeURIComponent(familySlug)}/people/${person.id}`}
                  >
                    {person.preferred_name}
                  </Link>
                </li>
              ))}
            </ul>
          ) : (
            <p>No confirmed attendees yet.</p>
          )}
        </section>
      )}
      {item.permissions.can_review_duplicates && (
        <section aria-labelledby="event-duplicates">
          <h2 id="event-duplicates">Possible duplicates</h2>
          {duplicates.isPending ? (
            <p role="status">Checking…</p>
          ) : duplicates.isError ? (
            <p role="alert">Duplicate suggestions could not be loaded.</p>
          ) : duplicates.data.length === 0 ? (
            <p>No likely duplicates found.</p>
          ) : (
            <ul>
              {duplicates.data.map((candidate) => (
                <li key={candidate.id}>
                  <Link
                    to={`/families/${encodeURIComponent(familySlug)}/events/${candidate.id}`}
                  >
                    {candidate.name}
                  </Link>
                </li>
              ))}
            </ul>
          )}
          <p>
            <small>
              Suggestions are advisory; fambam never merges Events
              automatically.
            </small>
          </p>
        </section>
      )}
      {item.permissions.can_manage_exports && (
        <section aria-labelledby="event-archives">
          <h2 id="event-archives">Event archives</h2>
          <p>
            Create a private, 24-hour ZIP containing preserved originals and a
            metadata manifest.
          </p>
          <button
            type="button"
            disabled={exportMutations.request.isPending}
            onClick={() => {
              exportMutations.request.mutate();
            }}
          >
            Create Event archive
          </button>
          {exportMutations.request.isError && (
            <p role="alert">The Event archive could not be requested.</p>
          )}
          {exportMutations.download.isError && (
            <p role="alert">
              The Event archive download could not be authorised.
            </p>
          )}
          {exports.isPending ? (
            <p role="status">Loading Event archives…</p>
          ) : exports.isError ? (
            <p role="alert">Event archives could not be loaded.</p>
          ) : exports.data.length === 0 ? (
            <p>No Event archives have been created.</p>
          ) : (
            <ul>
              {exports.data.map((archive) => (
                <li key={archive.id}>
                  Archive requested by {archive.requester.name}: {archive.state}
                  {archive.state === "ready" && (
                    <button
                      type="button"
                      disabled={exportMutations.download.isPending}
                      onClick={() => {
                        exportMutations.download.mutate(archive.id, {
                          onSuccess: (authorization) => {
                            window.location.assign(authorization.url);
                          },
                        });
                      }}
                    >
                      Download archive
                    </button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </section>
      )}
      {item.permissions.can_create_album && (
        <Link to={`/families/${encodeURIComponent(familySlug)}/events`}>
          Back to Events
        </Link>
      )}
    </main>
  );
}
