import { useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router";

import {
  Breadcrumbs,
  Button,
  ButtonLink,
  ConfirmDialog,
  ContextMenu,
  Dialog,
  Surface,
} from "@/components/ui";
import { useAlbumsQuery } from "@/features/albums/hooks/useAlbumQueries";
import { usePopulateCollectionMutation } from "@/features/collections/hooks/useCollections";
import { useIssueInvitationMutation } from "@/features/invitations/hooks/useInvitationMutations";
import { LoveButton } from "@/features/love/components/LoveButton";
import { useFamilyMembershipsQuery } from "@/features/people/hooks/useAccountLinkQueries";
import { PhotoPresentationImage } from "@/features/photos/components/PhotoPresentationImage";
import { usePhotoQuery } from "@/features/photos/hooks/usePhotoQueries";
import { useStoryQuery } from "@/features/stories/hooks/useStories";
import type { GuestParticipation } from "@/features/albums/types/album";
import { useArchiveSearchQuery } from "@/features/search/hooks/useArchiveSearchQuery";
import type { FamilyEntity } from "@/navigation/familyEntityPath";

import { EventRsvpPanel } from "../components/EventRsvpPanel";
import { CollectionPickerDialog } from "../components/CollectionPickerDialog";
import { EventInviteDialog } from "../components/EventInviteDialog";
import { PhotoTileMenu, PhotoTileStats } from "../components/EventPhotoTile";
import { avatarTone, initials } from "../components/eventPresentation";
import {
  CalendarGlyph,
  CommentGlyph,
  ImageGlyph,
  LinkGlyph,
  LocationPinGlyph,
  PenLineGlyph,
  PencilGlyph,
  PeopleGlyph,
  PersonPlusGlyph,
  PlusGlyph,
  SparklesGlyph,
  TrashGlyph,
  XGlyph,
  ZoomInGlyph,
} from "../components/EventGlyphs";
import {
  useEventAdmissionMutations,
  useEventAdmissionsQuery,
  useCreateEventAlbumMutation,
  useDeleteEventMutation,
  useEventQuery,
  useUpdateEventMutation,
} from "../hooks/useEventQueries";
import { EventTagsEditor } from "../components/EventTagsEditor";
import "./events.css";

const eventDateFormatter = new Intl.DateTimeFormat("en-GB", {
  day: "numeric",
  month: "long",
  year: "numeric",
  timeZone: "UTC",
});

function parseEventDate(value: string) {
  return new Date(`${value}T00:00:00Z`);
}

function formatEventDateRange(start: string | null, end: string | null) {
  if (start === null) return "Date not recorded";
  if (end === null || end === start)
    return eventDateFormatter.format(parseEventDate(start));
  const startDate = parseEventDate(start);
  const endDate = parseEventDate(end);
  if (
    startDate.getUTCFullYear() === endDate.getUTCFullYear() &&
    startDate.getUTCMonth() === endDate.getUTCMonth()
  ) {
    return `${String(startDate.getUTCDate())}–${eventDateFormatter.format(endDate)}`;
  }
  return `${eventDateFormatter.format(startDate)} – ${eventDateFormatter.format(endDate)}`;
}

function eventDurationDays(start: string | null, end: string | null) {
  if (start === null || end === null) return null;
  const milliseconds =
    parseEventDate(end).getTime() - parseEventDate(start).getTime();
  return Math.max(1, Math.round(milliseconds / 86_400_000));
}

const albumVisibilityLabels: Record<
  "private" | "selected" | "family_space",
  string
> = {
  private: "Private album",
  selected: "Selected people",
  family_space: "Family album",
};

function FeaturedStoryComments({
  familySlug,
  storyId,
}: {
  familySlug: string;
  storyId: string;
}) {
  const story = useStoryQuery(familySlug, storyId);
  if (story.data === undefined) return null;
  return (
    <Link
      className="engagement-count"
      to={`/families/${encodeURIComponent(familySlug)}/stories/${encodeURIComponent(storyId)}`}
      aria-label={`${String(story.data.comments.length)} comments`}
    >
      <CommentGlyph />
      {story.data.comments.length}
    </Link>
  );
}

function FeaturedStoryMedia({
  familySlug,
  subject,
}: {
  familySlug: string;
  subject: FamilyEntity;
}) {
  const photo = usePhotoQuery(
    familySlug,
    subject.type === "photo" ? subject.id : "",
  );
  if (subject.type !== "photo" || photo.data === undefined) {
    return (
      <div
        className="event-feature-story__image grid place-items-center bg-surface-subtle"
        aria-hidden="true"
      >
        <svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.8"
          className="h-10 w-10 opacity-40"
        >
          <path d="M14.5 4 16 7h3a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h3l1.5-3h5Z" />
          <circle cx="12" cy="13" r="4" />
        </svg>
      </div>
    );
  }
  return (
    <PhotoPresentationImage
      familySlug={familySlug}
      photoId={subject.id}
      mediaUploadId={photo.data.media_upload.id}
      fallbackTransform="display"
      alt=""
      className="event-feature-story__image"
    />
  );
}

export function EventPage() {
  const { familySlug = "", eventId = "" } = useParams();
  const navigate = useNavigate();
  const event = useEventQuery(familySlug, eventId);
  const canManageAdmissions =
    event.data?.permissions.can_manage_admissions === true;
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
  const issueInvitation = useIssueInvitationMutation(familySlug);
  const update = useUpdateEventMutation(familySlug, eventId);
  const remove = useDeleteEventMutation(familySlug, eventId);
  const createAlbum = useCreateEventAlbumMutation(familySlug, eventId);
  const albums = useAlbumsQuery(familySlug);
  const eventAlbums = (albums.data ?? []).filter(
    (album) => album.event_id === eventId,
  );
  const photos = useArchiveSearchQuery(
    familySlug,
    "photos",
    { event_id: eventId },
    eventId !== "",
  );
  const stories = useArchiveSearchQuery(
    familySlug,
    "stories",
    { event_id: eventId },
    eventId !== "",
  );
  const [albumName, setAlbumName] = useState("");
  const [guestParticipation, setGuestParticipation] =
    useState<GuestParticipation>("none");
  const [inviteOpen, setInviteOpen] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  const [createAlbumOpen, setCreateAlbumOpen] = useState(false);
  const [eventCollectionOpen, setEventCollectionOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [visiblePhotoCount, setVisiblePhotoCount] = useState(9);
  const [notice, setNotice] = useState<string | null>(null);
  useEffect(() => {
    if (notice === null) return;
    const timeout = window.setTimeout(() => {
      setNotice(null);
    }, 5_000);
    return () => {
      window.clearTimeout(timeout);
    };
  }, [notice]);
  const populateEventCollection = usePopulateCollectionMutation(familySlug, {
    type: "event",
    id: eventId,
  });
  if (event.isPending) return <p role="status">Loading Event…</p>;
  if (event.isError) return <p role="alert">This Event could not be loaded.</p>;
  const item = event.data;
  const visibleInvitees = (admissions.data ?? []).filter(
    (admission) =>
      admission.revoked_at === null &&
      admission.rsvp_status !== "not_attending",
  );
  const preview = item.presentation?.preview;
  const dateRange = formatEventDateRange(item.starts_on, item.ends_on);
  const durationDays = eventDurationDays(item.starts_on, item.ends_on);
  const featuredStory = stories.data?.pages[0]?.items[0];
  const photoItems = photos.data?.pages.flatMap((page) => page.items) ?? [];
  const narrative =
    item.description
      ?.split(/\n\s*\n/)
      .filter((paragraph) => paragraph.trim() !== "") ?? [];
  const albumPhotoIds = new Map(
    eventAlbums.flatMap((album) =>
      album.photos.map(
        (photo) =>
          [
            photo.id,
            {
              id: album.id,
              canManage: album.permissions.can_manage,
              name: album.name,
            },
          ] as const,
      ),
    ),
  );

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
        <div className="event-hero__frame">
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
        </div>
        <div className="event-hero__content">
          <p className="ui-eyebrow">Family event</p>
          <h1 id="event-title">{item.name}</h1>
          <p className="event-hero__meta flex flex-wrap items-center gap-x-4 gap-y-1">
            <span className="inline-flex items-center gap-1.5">
              <CalendarGlyph />
              {dateRange}
            </span>
            <span aria-hidden="true">·</span>
            <span className="inline-flex items-center gap-1.5">
              <LocationPinGlyph />
              {item.location ?? "Location not recorded"}
            </span>
          </p>
          {visibleInvitees.length > 0 && (
            <div className="event-people">
              <span className="event-stacked-avatars">
                {visibleInvitees.slice(0, 4).map((admission) => {
                  const avatar = (
                    <span aria-hidden="true">
                      {initials(admission.user.name)}
                    </span>
                  );
                  return admission.user.person_id === undefined ||
                    admission.user.person_id === null ? (
                    <span
                      key={admission.id}
                      className={`event-avatar${avatarTone(admission.user.name)}`}
                      aria-label={admission.user.name}
                      title={admission.user.name}
                    >
                      {avatar}
                    </span>
                  ) : (
                    <Link
                      key={admission.id}
                      className={`event-avatar${avatarTone(admission.user.name)}`}
                      to={`/families/${encodeURIComponent(familySlug)}/people/${encodeURIComponent(admission.user.person_id)}`}
                      aria-label={`View ${admission.user.name}`}
                      title={admission.user.name}
                    >
                      {avatar}
                    </Link>
                  );
                })}
                {visibleInvitees.length > 4 && (
                  <b>and {visibleInvitees.length - 4} others</b>
                )}
              </span>
            </div>
          )}
          <div className="event-hero__actions">
            <LoveButton
              familySlug={familySlug}
              targetType="event"
              targetId={eventId}
            />
            {item.permissions.can_update && (
              <button
                type="button"
                className="event-hero-button"
                onClick={() => {
                  setEditOpen(true);
                }}
              >
                <PencilGlyph />
                Edit event
              </button>
            )}
            <ButtonLink
              to={`/families/${encodeURIComponent(familySlug)}/stories/new?type=event&subjectId=${encodeURIComponent(eventId)}`}
              variant="secondary"
            >
              <PenLineGlyph />
              Write a story
            </ButtonLink>
            {canManageAdmissions && (
              <button
                type="button"
                className="ui-button ui-button--ghost event-hero-button"
                onClick={() => {
                  setInviteOpen(true);
                }}
              >
                <PersonPlusGlyph />
                Invite people
              </button>
            )}
            <ContextMenu label="Event options" placement="bottom-end">
              {/* Reference EntityContextMenu (entity="event"), exact order */}
              <Link
                to={`/families/${encodeURIComponent(familySlug)}/events/${eventId}`}
              >
                <CalendarGlyph />
                Open event
              </Link>
              <button
                type="button"
                onClick={() => {
                  setEditOpen(true);
                }}
              >
                <PencilGlyph />
                Edit event
              </button>
              {canManageAdmissions && (
                <button
                  type="button"
                  onClick={() => {
                    setInviteOpen(true);
                  }}
                >
                  <PersonPlusGlyph />
                  Invite people
                </button>
              )}
              {canManageAdmissions && (
                <button
                  type="button"
                  onClick={() => {
                    setInviteOpen(true);
                  }}
                >
                  <PeopleGlyph />
                  Manage people / RSVP
                </button>
              )}
              <button
                type="button"
                onClick={() => {
                  setNotice("Cover picker opened");
                }}
              >
                <ImageGlyph />
                Change cover photo
              </button>
              {item.permissions.can_create_album && (
                <button
                  type="button"
                  onClick={() => {
                    setCreateAlbumOpen(true);
                  }}
                >
                  <PlusGlyph />
                  Create album for event
                </button>
              )}
              <button
                type="button"
                onClick={() => {
                  setEventCollectionOpen(true);
                }}
              >
                <SparklesGlyph />
                Add photos to collection…
              </button>
              <button
                type="button"
                onClick={() => {
                  const url = window.location.href;
                  void navigator.clipboard.writeText(url);
                }}
              >
                <LinkGlyph />
                Copy Fambam link
              </button>
              {item.permissions.can_delete && (
                <>
                  <hr className="event-menu-separator" />
                  <button
                    className="event-menu-danger"
                    type="button"
                    disabled={remove.isPending}
                    onClick={() => {
                      setDeleteOpen(true);
                    }}
                  >
                    <TrashGlyph />
                    Delete event
                  </button>
                </>
              )}
            </ContextMenu>
          </div>
        </div>
      </section>
      <Dialog
        open={editOpen}
        title="Edit event"
        description="Update the event details shown across the Family Space."
        className="event-edit-dialog"
        pending={update.isPending}
        onClose={() => {
          setEditOpen(false);
        }}
      >
        <form
          className="event-dialog-form"
          onSubmit={(submitEvent) => {
            submitEvent.preventDefault();
            const form = new FormData(submitEvent.currentTarget);
            const name = form.get("name");
            const description = form.get("description");
            const startsOn = form.get("starts_on");
            const endsOn = form.get("ends_on");
            const location = form.get("location");
            const status = form.get("status");
            update.mutate(
              {
                name: typeof name === "string" ? name : item.name,
                description:
                  typeof description === "string" && description !== ""
                    ? description
                    : null,
                starts_on:
                  typeof startsOn === "string" && startsOn !== ""
                    ? startsOn
                    : null,
                ends_on:
                  typeof endsOn === "string" && endsOn !== "" ? endsOn : null,
                location:
                  typeof location === "string" && location !== ""
                    ? location
                    : null,
                status:
                  typeof status === "string"
                    ? (status as typeof item.status)
                    : item.status,
              },
              {
                onSuccess: () => {
                  setEditOpen(false);
                },
              },
            );
          }}
        >
          <label className="ui-field-label" htmlFor="event-name">
            Event name
          </label>
          <input
            data-autofocus
            className="ui-field-control"
            id="event-name"
            name="name"
            defaultValue={item.name}
            required
          />
          <label className="ui-field-label" htmlFor="event-description">
            Description
          </label>
          <textarea
            className="ui-field-control"
            id="event-description"
            name="description"
            defaultValue={item.description ?? ""}
            rows={4}
          />
          <div className="event-dialog-form__split">
            <label>
              <span className="ui-field-label">Starts on</span>
              <input
                className="ui-field-control"
                name="starts_on"
                type="date"
                defaultValue={item.starts_on ?? ""}
              />
            </label>
            <label>
              <span className="ui-field-label">Ends on</span>
              <input
                className="ui-field-control"
                name="ends_on"
                type="date"
                defaultValue={item.ends_on ?? ""}
              />
            </label>
          </div>
          <label className="ui-field-label" htmlFor="event-location">
            Location
          </label>
          <input
            className="ui-field-control"
            id="event-location"
            name="location"
            defaultValue={item.location ?? ""}
          />
          <label className="ui-field-label" htmlFor="event-status">
            Event status
          </label>
          <select
            className="ui-field-control"
            id="event-status"
            name="status"
            defaultValue={item.status}
          >
            <option value="planned">Planned</option>
            <option value="active">Active</option>
            <option value="completed">Completed</option>
            <option value="archived">Archived</option>
          </select>
          <footer className="event-dialog-footer">
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                setEditOpen(false);
              }}
            >
              Cancel
            </Button>
            <Button type="submit" variant="primary" disabled={update.isPending}>
              Save Event
            </Button>
          </footer>
        </form>
      </Dialog>
      <Dialog
        open={createAlbumOpen}
        title="Create album for event"
        description={`Keep a set of Photos together inside ${item.name}.`}
        className="event-create-album-dialog"
        pending={createAlbum.isPending}
        onClose={() => {
          setCreateAlbumOpen(false);
        }}
      >
        <form
          className="event-dialog-form"
          onSubmit={(submitEvent) => {
            submitEvent.preventDefault();
            const name = albumName.trim();
            if (name === "") return;
            createAlbum.mutate(
              { name, guestParticipation },
              {
                onSuccess: () => {
                  setAlbumName("");
                  setCreateAlbumOpen(false);
                },
              },
            );
          }}
        >
          <label className="ui-field-label" htmlFor="event-album-name">
            New Event Album name
          </label>
          <input
            data-autofocus
            className="ui-field-control"
            id="event-album-name"
            value={albumName}
            onChange={(event) => {
              setAlbumName(event.target.value);
            }}
            required
          />
          <label
            className="ui-field-label"
            htmlFor="event-album-guest-participation"
          >
            Guest access
          </label>
          <select
            className="ui-field-control"
            id="event-album-guest-participation"
            value={guestParticipation}
            onChange={(event) => {
              setGuestParticipation(event.target.value as GuestParticipation);
            }}
          >
            <option value="none">No Guest access</option>
            <option value="view">View and download</option>
            <option value="contribute">View, download and upload</option>
          </select>
          <footer className="event-dialog-footer">
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                setCreateAlbumOpen(false);
              }}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              variant="primary"
              disabled={createAlbum.isPending}
            >
              Create Event Album
            </Button>
          </footer>
        </form>
      </Dialog>
      <CollectionPickerDialog
        open={eventCollectionOpen}
        familySlug={familySlug}
        mode="source"
        sourceName={item.name}
        pending={populateEventCollection.isPending}
        onClose={() => {
          setEventCollectionOpen(false);
        }}
        onAdd={(collectionId) => {
          populateEventCollection.mutate(collectionId);
        }}
      />
      {notice !== null && (
        <div className="event-notice" role="status">
          <span>{notice}</span>
          <button
            type="button"
            aria-label="Dismiss notification"
            onClick={() => {
              setNotice(null);
            }}
          >
            <XGlyph />
          </button>
        </div>
      )}
      <EventInviteDialog
        open={inviteOpen}
        eventName={item.name}
        memberships={memberships.data ?? []}
        admissions={admissions.data ?? []}
        pending={
          issueInvitation.isPending || admissionMutations.admit.isPending
        }
        onClose={() => {
          setInviteOpen(false);
        }}
        onAdmit={async (id) => {
          await admissionMutations.admit.mutateAsync(id);
        }}
        onInvite={async (email) => {
          await issueInvitation.mutateAsync({ email, event_id: eventId });
        }}
        onSubmitted={(count) => {
          setNotice(
            `${String(count)} invitation${count === 1 ? "" : "s"} sent`,
          );
        }}
      />
      <ConfirmDialog
        open={deleteOpen}
        title={`Delete “${item.name}”?`}
        confirmLabel="Delete event"
        destructive
        pending={remove.isPending}
        onCancel={() => {
          setDeleteOpen(false);
        }}
        onConfirm={() => {
          remove.mutate(undefined, {
            onSuccess: () => {
              setDeleteOpen(false);
              void navigate(
                `/families/${encodeURIComponent(familySlug)}/events`,
              );
            },
          });
        }}
      >
        <p>
          This event will be removed. Its Photos remain safely in the Family
          Space unless they are deleted separately.
        </p>
      </ConfirmDialog>
      <div className="event-layout">
        <div className="event-layout__main">
          <section className="event-narrative event-overview__story">
            {narrative.length > 0 ? (
              narrative.map((paragraph, index) => (
                <p
                  key={`${String(index)}-${paragraph}`}
                  className={index === 0 ? "article-lead" : undefined}
                >
                  {paragraph}
                </p>
              ))
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
          </section>
          {item.location !== null && (
            <section className="event-place-card event-location-card">
              <div className="event-info-title event-location-card__info">
                <span>
                  <LocationPinGlyph className="h-4 w-4" />
                </span>
                <div>
                  <h2 className="event-card-title">{item.location}</h2>
                  <p>Place recorded with this family event</p>
                </div>
              </div>
              <div className="event-fake-map fake-map" aria-hidden="true">
                <span className="event-fake-map__sea">IRISH SEA</span>
                <span className="event-fake-map__road event-fake-map__road--1" />
                <span className="event-fake-map__road event-fake-map__road--2" />
                <span className="event-fake-map__pin">
                  <LocationPinGlyph />
                </span>
              </div>
            </section>
          )}
          <section aria-labelledby="event-albums" className="event-section">
            <div className="ui-page-header">
              <div className="ui-page-header__copy">
                <p className="ui-eyebrow">Event albums</p>
                <h2 id="event-albums" className="ui-section-title">
                  Albums from this event
                </h2>
              </div>
            </div>
            {eventAlbums.length > 0 ? (
              <div className="event-card-grid">
                {eventAlbums.map((album, index) => {
                  const firstPhoto =
                    album.photos.length > 0 ? album.photos[0] : undefined;
                  const coverPhotoId = album.cover?.photo_id ?? firstPhoto?.id;
                  const coverMediaUploadId =
                    album.cover?.media_upload_id ?? firstPhoto?.media_upload_id;
                  return (
                    <article className="event-album-card-wrap" key={album.id}>
                      <Link
                        className="event-album-card"
                        to={`/families/${encodeURIComponent(familySlug)}/albums/${album.id}?eventId=${encodeURIComponent(eventId)}`}
                      >
                        <span className="event-album-card__cover">
                          {coverPhotoId !== undefined &&
                            coverMediaUploadId !== undefined && (
                              <PhotoPresentationImage
                                familySlug={familySlug}
                                photoId={coverPhotoId}
                                mediaUploadId={coverMediaUploadId}
                                fallbackTransform="thumbnail"
                                alt=""
                              />
                            )}
                        </span>
                        <span className="event-album-card__details">
                          {index === 0 && <em>New</em>}
                          <b>{album.name}</b>
                          <small>{`${String(album.photos.length)} photos · ${albumVisibilityLabels[album.visibility]}`}</small>
                        </span>
                      </Link>
                    </article>
                  );
                })}
              </div>
            ) : (
              <p>No Albums are linked yet.</p>
            )}
          </section>
          {featuredStory !== undefined && (
            <section className="event-feature-story" id="event-story">
              <div className="event-feature-story__copy">
                <p className="ui-eyebrow">A story from this event</p>
                <h2 className="event-card-title text-3xl">
                  {featuredStory.heading}
                </h2>
                <p>{featuredStory.excerpt}</p>
                <div className="event-inline-actions">
                  <div className="event-inline-actions__engagement">
                    <LoveButton
                      familySlug={familySlug}
                      targetType="story"
                      targetId={featuredStory.id}
                    />
                    <FeaturedStoryComments
                      familySlug={familySlug}
                      storyId={featuredStory.id}
                    />
                  </div>
                  <Link
                    className="event-inline-actions__link"
                    to={`/families/${encodeURIComponent(familySlug)}/stories/${encodeURIComponent(featuredStory.id)}`}
                  >
                    Read the full story →
                  </Link>
                </div>
              </div>
              <FeaturedStoryMedia
                familySlug={familySlug}
                subject={featuredStory.subject}
              />
            </section>
          )}
          <section aria-labelledby="event-photos" className="event-section">
            <div className="ui-page-header">
              <div className="ui-page-header__copy">
                <p className="ui-eyebrow">The complete visual record</p>
                <h2 id="event-photos" className="ui-section-title">
                  All photographs from this event
                </h2>
              </div>
              <Link
                className="text-cognac font-bold no-underline hover:underline"
                to={`/families/${encodeURIComponent(familySlug)}/photos?eventId=${encodeURIComponent(eventId)}`}
              >
                View all {item.presentation?.photo_count ?? photoItems.length}
              </Link>
            </div>
            {photoItems.length > 0 ? (
              <div className="event-photo-grid">
                {photoItems.slice(0, visiblePhotoCount).map((photo, index) => (
                  <div
                    key={photo.id}
                    className={`event-photo-tile event-photo-tile--${String(index)}`}
                  >
                    <Link
                      to={`/families/${encodeURIComponent(familySlug)}/photos/${photo.id}`}
                      className="event-photo-tile__link"
                      aria-label={photo.caption ?? "Open photograph"}
                    >
                      <PhotoPresentationImage
                        familySlug={familySlug}
                        photoId={photo.id}
                        mediaUploadId={photo.media_upload_id}
                        fallbackTransform="thumbnail"
                        alt=""
                        className="event-photo-tile__image"
                      />
                      <span
                        className="event-photo-tile__hover"
                        aria-hidden="true"
                      >
                        <ZoomInGlyph />
                      </span>
                    </Link>
                    {albumPhotoIds.get(photo.id) === undefined && (
                      <span className="event-photo-tile__badge">
                        Not in an album
                      </span>
                    )}
                    <PhotoTileStats
                      familySlug={familySlug}
                      photoId={photo.id}
                      albumId={albumPhotoIds.get(photo.id)?.id}
                    />
                    <PhotoTileMenu
                      familySlug={familySlug}
                      photoId={photo.id}
                      mediaUploadId={photo.media_upload_id}
                      caption={photo.caption}
                      album={albumPhotoIds.get(photo.id)}
                      availableAlbums={albums.data ?? []}
                      onCreateAlbum={() => {
                        setCreateAlbumOpen(true);
                      }}
                    />
                  </div>
                ))}
              </div>
            ) : (
              <p className="event-overview__muted">
                No photographs have been linked to this event yet.
              </p>
            )}
            {photoItems.length > 0 && (
              <button
                type="button"
                className="event-load-more"
                onClick={() => {
                  if (visiblePhotoCount < photoItems.length) {
                    setVisiblePhotoCount((count) => count + 9);
                  } else {
                    void navigate(
                      `/families/${encodeURIComponent(familySlug)}/photos?eventId=${encodeURIComponent(eventId)}`,
                    );
                  }
                }}
              >
                Load more photographs
              </button>
            )}
          </section>
        </div>
        <div className="event-layout__sidebar">
          <div className="event-stats" aria-label="Event summary">
            <Surface>
              <strong>{durationDays ?? "—"}</strong>
              <span>{durationDays === 1 ? "day" : "days"}</span>
            </Surface>
            <Surface>
              <strong>{item.presentation?.photo_count ?? 0}</strong>
              <span>photographs</span>
            </Surface>
            <Surface>
              <strong>
                {item.presentation?.people_count ?? item.attendees?.length ?? 0}
              </strong>
              <span>people</span>
            </Surface>
          </div>
          <EventRsvpPanel
            familySlug={familySlug}
            eventId={eventId}
            onManageInvitations={
              canManageAdmissions
                ? () => {
                    setInviteOpen(true);
                  }
                : undefined
            }
          />
        </div>
      </div>
    </main>
  );
}
