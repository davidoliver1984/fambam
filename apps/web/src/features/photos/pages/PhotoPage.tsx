import { useEffect, useState, type SyntheticEvent } from "react";
import {
  Link,
  useLocation,
  useNavigate,
  useParams,
  useSearchParams,
} from "react-router";

import { toAppError } from "@/api/errors";
import {
  useAlbumQuery,
  useAlbumsQuery,
  useCreateAlbumMutation,
} from "@/features/albums/hooks/useAlbumQueries";
import type { AlbumVisibility } from "@/features/albums/types/album";
import { PhotoTileMenu } from "@/features/events/components/EventPhotoTile";
import {
  CalendarGlyph,
  LocationPinGlyph,
  PenLineGlyph,
} from "@/features/events/components/EventGlyphs";
import { PersonAvatar } from "@/features/family-spaces/components/PersonAvatar";
import { useFamilySpaceQuery } from "@/features/family-spaces/hooks/useFamilySpaceQuery";
import { faceBoundsStyle } from "@/features/face-recognition/faceGeometry";
import { Button, Dialog, EntityLink } from "@/components/ui";
import { useCollectionQuery } from "@/features/collections/hooks/useCollections";

import {
  PhotoConversationPanel,
  PhotoLoveControl,
} from "../components/PhotoConversationPanel";
import { PhotoForm } from "../components/PhotoForm";
import { PhotoPresentationImage } from "../components/PhotoPresentationImage";
import { PhotoTagsForm } from "../components/PhotoTagsForm";
import {
  useReplacePhotoTagsMutation,
  useUpdatePhotoMutation,
} from "../hooks/usePhotoMutations";
import {
  usePhotoAlbumHistoryQuery,
  usePhotoQuery,
} from "../hooks/usePhotoQueries";
import type { PhotoAlbumHistoryItem } from "../types/photo";

import "./photo-detail.css";

export function PhotoPage() {
  const { familySlug = "", photoId = "" } = useParams();
  const [search] = useSearchParams();
  const location = useLocation();
  const navigate = useNavigate();
  const family = useFamilySpaceQuery(familySlug);
  const photoQuery = usePhotoQuery(familySlug, photoId);
  const history = usePhotoAlbumHistoryQuery(familySlug, photoId);
  const albums = useAlbumsQuery(familySlug);
  const requestedAlbumId = search.get("albumId") ?? "";
  const requestedCollectionId = search.get("collectionId") ?? "";
  const collection = useCollectionQuery(
    familySlug,
    requestedCollectionId,
    requestedCollectionId !== "",
  );
  const historyAlbumId =
    history.data?.find((item) => item.is_current)?.album.id ?? "";
  const membershipAlbumId =
    albums.data?.find((candidate) =>
      candidate.photos.some((candidatePhoto) => candidatePhoto.id === photoId),
    )?.id ?? "";
  const albumId = requestedAlbumId || historyAlbumId || membershipAlbumId;
  const album = useAlbumQuery(familySlug, albumId);
  const updatePhoto = useUpdatePhotoMutation(familySlug, photoId);
  const replaceTags = useReplacePhotoTagsMutation(familySlug, photoId);
  const createAlbum = useCreateAlbumMutation(familySlug);
  const [detailsOpen, setDetailsOpen] = useState(false);
  const [createAlbumOpen, setCreateAlbumOpen] = useState(false);
  const [albumName, setAlbumName] = useState("");
  const [albumVisibility, setAlbumVisibility] =
    useState<AlbumVisibility>("family_space");
  const [notice, setNotice] = useState<string | null>(null);
  const [highlightedPersonId, setHighlightedPersonId] = useState<string | null>(
    null,
  );

  const notFound =
    photoQuery.isError && toAppError(photoQuery.error).status === 404;

  if (photoQuery.isPending) return <p role="status">Loading Photo…</p>;
  if (notFound)
    return (
      <p role="alert">
        This Photo is unavailable or you no longer have access.
      </p>
    );
  if (photoQuery.isError)
    return <p role="alert">The Photo record could not be loaded.</p>;
  if (requestedCollectionId !== "" && collection.isPending)
    return <p role="status">Loading Photo…</p>;

  const photo = photoQuery.data;
  const faceCanvas = (photo.identified_faces ?? []).find(
    (face) =>
      face.image_width !== null &&
      face.image_width > 0 &&
      face.image_height !== null &&
      face.image_height > 0,
  );
  const albumPhotos = album.data?.photos ?? [];
  const albumPhotoIndex = albumPhotos.findIndex(
    (candidate) => candidate.id === photo.id,
  );
  const canCycleAlbum = albumPhotoIndex >= 0 && albumPhotos.length > 1;
  const previousPhoto = canCycleAlbum
    ? albumPhotos[
        (albumPhotoIndex - 1 + albumPhotos.length) % albumPhotos.length
      ]
    : undefined;
  const nextPhoto = canCycleAlbum
    ? albumPhotos[(albumPhotoIndex + 1) % albumPhotos.length]
    : undefined;
  const collectionPhotos = collection.data?.photos ?? [];
  const collectionPhotoIndex = collectionPhotos.findIndex(
    (candidate) => candidate.id === photo.id,
  );
  const hasCollectionContext =
    requestedCollectionId !== "" &&
    collection.data !== undefined &&
    collectionPhotoIndex >= 0;
  const previousCollectionPhoto = hasCollectionContext
    ? collectionPhotos[collectionPhotoIndex - 1]
    : undefined;
  const nextCollectionPhoto = hasCollectionContext
    ? collectionPhotos[collectionPhotoIndex + 1]
    : undefined;
  const albumPath = album.data
    ? `/families/${encodeURIComponent(familySlug)}/albums/${encodeURIComponent(album.data.id)}`
    : `/families/${encodeURIComponent(familySlug)}/photos`;
  const collectionPath = hasCollectionContext
    ? `/families/${encodeURIComponent(familySlug)}/collections/${encodeURIComponent(collection.data.id)}`
    : undefined;
  const returnPath = collectionPath ?? albumPath;
  const position = hasCollectionContext
    ? `${String(collectionPhotoIndex + 1)} of ${String(collectionPhotos.length)}`
    : albumPhotoIndex >= 0 && album.data
      ? `${String(albumPhotoIndex + 1)} of ${String(album.data.photos.length)}`
      : photo.visibility === "private"
        ? "Private Photo"
        : "Family Photo";

  const photoPath = (id: string) => {
    const query = hasCollectionContext
      ? `collectionId=${encodeURIComponent(collection.data.id)}`
      : `albumId=${encodeURIComponent(albumId)}`;
    return `/families/${encodeURIComponent(familySlug)}/photos/${encodeURIComponent(id)}?${query}`;
  };

  const contextualPreviousPhoto = hasCollectionContext
    ? previousCollectionPhoto
    : previousPhoto;
  const contextualNextPhoto = hasCollectionContext
    ? nextCollectionPhoto
    : nextPhoto;

  const sharePhoto = async () => {
    const shareUrl = new URL(window.location.href);
    if (hasCollectionContext) shareUrl.searchParams.delete("collectionId");
    const url = shareUrl.toString();
    const shareNavigator = navigator as unknown as {
      share?: (data: ShareData) => Promise<void>;
    };
    if (shareNavigator.share !== undefined) {
      try {
        await shareNavigator.share({
          title: photo.caption ?? "Fambam Photo",
          url,
        });
        return;
      } catch (error) {
        if (error instanceof DOMException && error.name === "AbortError")
          return;
      }
    }
    await navigator.clipboard.writeText(url);
    setNotice("Photo link copied");
  };

  return (
    <main className="photo-detail-page" aria-labelledby="photo-title">
      <PhotoKeyboardNavigation
        previousPath={
          contextualPreviousPhoto === undefined
            ? undefined
            : photoPath(contextualPreviousPhoto.id)
        }
        nextPath={
          contextualNextPhoto === undefined
            ? undefined
            : photoPath(contextualNextPhoto.id)
        }
      />
      <section className="photo-detail-stage" aria-label="Photo viewer">
        <div
          className={`photo-detail-navigation${hasCollectionContext ? " photo-detail-navigation--collection" : ""}`}
        >
          <Link className="photo-detail-back" to={returnPath}>
            <ChevronLeftGlyph />
            <span>
              {hasCollectionContext
                ? `Back to “${collection.data.name}”`
                : (album.data?.name ?? "Photographs")}
            </span>
          </Link>
          {hasCollectionContext && (
            <p className="photo-detail-collection-context">
              You are viewing “{collection.data.name}” collection
            </p>
          )}
        </div>
        <figure className="photo-detail-image">
          <PhotoPresentationImage
            familySlug={familySlug}
            photoId={photo.id}
            mediaUploadId={photo.media_upload.id}
            alt={photo.caption ?? photo.media_upload.client_filename}
            className="photo-detail-image__asset"
          />
          <div
            className="photo-face-labels"
            aria-label="Identified people"
            style={
              faceCanvas?.image_width && faceCanvas.image_height
                ? {
                    aspectRatio: `${String(faceCanvas.image_width)} / ${String(faceCanvas.image_height)}`,
                  }
                : undefined
            }
          >
            {(photo.identified_faces ?? []).map((face) => {
              if (
                face.image_width === null ||
                face.image_width <= 0 ||
                face.image_height === null ||
                face.image_height <= 0
              ) {
                return null;
              }
              return (
                <EntityLink
                  key={face.id}
                  className={`photo-face-label${highlightedPersonId === face.person.id ? " is-visible" : ""}`}
                  entity="person"
                  to={`/families/${encodeURIComponent(familySlug)}/people/${encodeURIComponent(face.person.id)}`}
                  aria-label={`View ${face.person.preferred_name}`}
                  onMouseEnter={() => {
                    setHighlightedPersonId(face.person.id);
                  }}
                  onMouseLeave={() => {
                    setHighlightedPersonId(null);
                  }}
                  onFocus={() => {
                    setHighlightedPersonId(face.person.id);
                  }}
                  onBlur={() => {
                    setHighlightedPersonId(null);
                  }}
                  style={faceBoundsStyle(
                    face.bounds,
                    face.image_width,
                    face.image_height,
                  )}
                >
                  <span>{face.person.preferred_name}</span>
                </EntityLink>
              );
            })}
          </div>
        </figure>
        {contextualPreviousPhoto !== undefined && (
          <Link
            className="photo-detail-arrow photo-detail-arrow--left"
            to={photoPath(contextualPreviousPhoto.id)}
            aria-label={
              hasCollectionContext
                ? `Previous Photo in ${collection.data.name}`
                : "Previous photo"
            }
          >
            <ChevronLeftGlyph />
          </Link>
        )}
        {contextualNextPhoto !== undefined && (
          <Link
            className="photo-detail-arrow photo-detail-arrow--right"
            to={photoPath(contextualNextPhoto.id)}
            aria-label={
              hasCollectionContext
                ? `Next Photo in ${collection.data.name}`
                : "Next photo"
            }
          >
            <ChevronRightGlyph />
          </Link>
        )}
      </section>

      <aside className="photo-detail-context">
        <header className="photo-detail-head">
          <div>
            <p>{position}</p>
            <h1 className="ui-detail-title" id="photo-title">
              {photo.caption ?? photo.media_upload.client_filename}
            </h1>
          </div>
          <div className="photo-detail-actions">
            <Link
              className="photo-detail-action"
              to={`/families/${encodeURIComponent(familySlug)}/stories/new?type=photo&subjectId=${encodeURIComponent(photo.id)}`}
              title="Write a Story from this Photo"
              aria-label="Write a Story from this Photo"
            >
              <PenLineGlyph />
            </Link>
            <button
              className="photo-detail-action"
              type="button"
              aria-label="Share this Photo"
              onClick={() => void sharePhoto()}
            >
              <ShareGlyph />
            </button>
            <PhotoTileMenu
              familySlug={familySlug}
              photoId={photo.id}
              mediaUploadId={photo.media_upload.id}
              caption={photo.caption}
              album={
                album.data
                  ? {
                      id: album.data.id,
                      name: album.data.name,
                      canManage: album.data.permissions.can_manage,
                    }
                  : undefined
              }
              availableAlbums={albums.data ?? []}
              hasMoreAlbums={albums.hasNextPage}
              loadingMoreAlbums={albums.isFetchingNextPage}
              onLoadMoreAlbums={() => {
                void albums.fetchNextPage();
              }}
              onCreateAlbum={() => {
                setCreateAlbumOpen(true);
              }}
              onEditDetails={() => {
                setDetailsOpen(true);
              }}
              showReviewPeople={
                family.data !== undefined &&
                ["owner", "administrator", "member"].includes(family.data.role)
              }
              onReviewPeople={() => {
                const query = new URLSearchParams({
                  photo_id: photo.id,
                  return_to: `${location.pathname}${location.search}`,
                });
                void navigate(
                  `/families/${encodeURIComponent(familySlug)}/photos/review-people?${query.toString()}`,
                );
              }}
            />
          </div>
        </header>

        {(photo.historical_date !== null ||
          photo.location_description !== null) && (
          <p className="photo-detail-meta">
            {photo.historical_date !== null && (
              <>
                <CalendarGlyph />
                {formatHistoricalDate(photo.historical_date)}
              </>
            )}
            {photo.historical_date !== null &&
              photo.location_description !== null && <span>·</span>}
            {photo.location_description !== null && (
              <>
                <LocationPinGlyph />
                {photo.location_description}
              </>
            )}
          </p>
        )}

        <div className="photo-detail-position">
          <span>
            {album.data !== undefined ? (
              <>
                In{" "}
                <EntityLink entity="album" to={albumPath}>
                  {album.data.name}
                </EntityLink>
              </>
            ) : (
              photo.visibility.replace("_", " ")
            )}
          </span>
          <PhotoLoveControl
            familySlug={familySlug}
            photoId={photo.id}
            albumId={albumId || undefined}
          />
        </div>

        {photo.description !== null && (
          <p className="photo-detail-caption">{photo.description}</p>
        )}

        <AlbumHistory
          familySlug={familySlug}
          items={history.data ?? []}
          pending={history.isPending}
          failed={history.isError}
        />

        <PeopleInPhoto
          familySlug={familySlug}
          people={photo.people}
          highlightedPersonId={highlightedPersonId}
          onPersonHighlight={setHighlightedPersonId}
        />

        <PhotoConversationPanel
          familySlug={familySlug}
          photoId={photo.id}
          albumId={albumId || undefined}
        />

        {notice !== null && (
          <p className="photo-detail-notice" role="status">
            {notice}
          </p>
        )}
      </aside>

      <Dialog
        open={detailsOpen}
        title="Edit Photo details"
        description="Update the description and organisation of this Photo."
        className="photo-detail-dialog"
        pending={updatePhoto.isPending || replaceTags.isPending}
        onClose={() => {
          setDetailsOpen(false);
        }}
      >
        {photo.permissions.can_update ? (
          <PhotoForm
            photo={photo}
            compact
            pending={updatePhoto.isPending}
            onSubmit={(input) => updatePhoto.mutateAsync(input)}
          />
        ) : (
          <p>You do not have permission to edit this Photo.</p>
        )}
        {photo.permissions.can_manage_tags && (
          <PhotoTagsForm
            initialTags={photo.tags.map((tag) => tag.label)}
            compact
            pending={replaceTags.isPending}
            onSubmit={(tags) => replaceTags.mutateAsync(tags)}
          />
        )}
      </Dialog>

      <Dialog
        open={createAlbumOpen}
        title="Create an Album"
        description="Create a new Album, then choose it from Add to album."
        className="photo-detail-dialog"
        pending={createAlbum.isPending}
        onClose={() => {
          setCreateAlbumOpen(false);
        }}
      >
        <form
          className="photo-detail-dialog__form ui-form ui-form--compact"
          onSubmit={(event: SyntheticEvent<HTMLFormElement>) => {
            event.preventDefault();
            const name = albumName.trim();
            if (name === "") return;
            createAlbum.mutate(
              { name, description: null, visibility: albumVisibility },
              {
                onSuccess: () => {
                  setAlbumName("");
                  setCreateAlbumOpen(false);
                },
              },
            );
          }}
        >
          <label htmlFor="photo-new-album-name">Name</label>
          <input
            data-autofocus
            id="photo-new-album-name"
            value={albumName}
            onChange={(event) => {
              setAlbumName(event.target.value);
            }}
            required
          />
          <label htmlFor="photo-new-album-visibility">Audience</label>
          <select
            id="photo-new-album-visibility"
            value={albumVisibility}
            onChange={(event) => {
              setAlbumVisibility(event.target.value as AlbumVisibility);
            }}
          >
            <option value="family_space">Family Space</option>
            <option value="selected">Selected people</option>
            <option value="private">Private</option>
          </select>
          <footer>
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
              disabled={createAlbum.isPending || albumName.trim() === ""}
            >
              Create Album
            </Button>
          </footer>
        </form>
      </Dialog>
    </main>
  );
}

function PhotoKeyboardNavigation({
  previousPath,
  nextPath,
}: {
  previousPath?: string;
  nextPath?: string;
}) {
  const navigate = useNavigate();

  useEffect(() => {
    const handleKeyDown = (event: KeyboardEvent) => {
      if (
        event.defaultPrevented ||
        event.altKey ||
        event.ctrlKey ||
        event.metaKey ||
        event.shiftKey
      ) {
        return;
      }

      const target = event.target;
      if (
        target instanceof Element &&
        target.closest(
          'input, textarea, select, [contenteditable="true"], [role="textbox"]',
        ) !== null
      ) {
        return;
      }

      const destination =
        event.key === "ArrowLeft"
          ? previousPath
          : event.key === "ArrowRight"
            ? nextPath
            : undefined;
      if (destination === undefined) return;

      event.preventDefault();
      void navigate(destination);
    };

    window.addEventListener("keydown", handleKeyDown);
    return () => {
      window.removeEventListener("keydown", handleKeyDown);
    };
  }, [navigate, nextPath, previousPath]);

  return null;
}

function AlbumHistory({
  familySlug,
  items,
  pending,
  failed,
}: {
  familySlug: string;
  items: PhotoAlbumHistoryItem[];
  pending: boolean;
  failed: boolean;
}) {
  return (
    <section
      className="photo-album-history"
      aria-labelledby="photo-album-history-title"
    >
      <p className="photo-detail-eyebrow" id="photo-album-history-title">
        Album history
      </p>
      {pending && <p role="status">Loading Album history…</p>}
      {failed && <p role="alert">Album history could not be loaded.</p>}
      {!pending && !failed && items.length === 0 && (
        <p className="photo-album-history__empty">
          No Album changes recorded yet.
        </p>
      )}
      {items.map((item) => {
        const actor = item.actor.person_id ? (
          <EntityLink
            entity="person"
            to={`/families/${encodeURIComponent(familySlug)}/people/${encodeURIComponent(item.actor.person_id)}`}
          >
            {item.actor.display_name}
          </EntityLink>
        ) : (
          item.actor.display_name
        );
        return (
          <article
            key={`${item.event_type}-${item.album.id}-${item.created_at}`}
            className={
              item.event_type === "removed" || !item.is_current
                ? "historical"
                : ""
            }
          >
            <PersonAvatar
              name={item.actor.display_name}
              initials={item.actor.initials}
              portraitUrl={item.actor.portrait_thumbnail_url ?? undefined}
            />
            <span>
              <b>
                {actor}{" "}
                {item.event_type === "added"
                  ? "added this Photo to"
                  : "removed this Photo from"}{" "}
                <EntityLink
                  entity="album"
                  to={`/families/${encodeURIComponent(familySlug)}/albums/${encodeURIComponent(item.album.id)}`}
                >
                  {item.album.name}
                </EntityLink>
              </b>
              <small>
                {formatTimestamp(item.created_at)} ·{" "}
                {item.event_type === "added" && item.is_current
                  ? "Current Album"
                  : "Recorded in audit history"}
              </small>
            </span>
          </article>
        );
      })}
    </section>
  );
}

function PeopleInPhoto({
  familySlug,
  people,
  highlightedPersonId,
  onPersonHighlight,
}: {
  familySlug: string;
  people: Array<{ id: string; person: { id: string; preferred_name: string } }>;
  highlightedPersonId: string | null;
  onPersonHighlight: (personId: string | null) => void;
}) {
  const [expanded, setExpanded] = useState(false);
  if (people.length === 0) return null;
  const visible = expanded ? people : people.slice(0, 4);
  const hiddenCount = people.length - 4;
  return (
    <section className="photo-people" aria-label="People in this Photo">
      {visible.map((association) => (
        <EntityLink
          key={association.id}
          className={
            highlightedPersonId === association.person.id
              ? "is-face-active"
              : ""
          }
          entity="person"
          to={`/families/${encodeURIComponent(familySlug)}/people/${encodeURIComponent(association.person.id)}`}
          aria-label={association.person.preferred_name}
          onMouseEnter={() => {
            onPersonHighlight(association.person.id);
          }}
          onMouseLeave={() => {
            onPersonHighlight(null);
          }}
          onFocus={() => {
            onPersonHighlight(association.person.id);
          }}
          onBlur={() => {
            onPersonHighlight(null);
          }}
        >
          <PersonAvatar name={association.person.preferred_name} />
          <span>{association.person.preferred_name.split(" ")[0]}</span>
        </EntityLink>
      ))}
      {hiddenCount > 0 && (
        <button
          className="photo-people__others ui-inline-action"
          type="button"
          aria-expanded={expanded}
          onClick={() => {
            setExpanded((value) => !value);
          }}
        >
          {expanded ? "Show fewer" : `${String(hiddenCount)} more`}
        </button>
      )}
    </section>
  );
}

function formatHistoricalDate(date: {
  precision: string;
  value: string | null;
}) {
  if (date.value === null) return "Unknown date";
  if (date.precision === "exact") {
    const parsed = new Date(`${date.value}T00:00:00`);
    if (!Number.isNaN(parsed.valueOf()))
      return new Intl.DateTimeFormat("en-GB", {
        day: "numeric",
        month: "long",
        year: "numeric",
      }).format(parsed);
  }
  return date.value;
}

function formatTimestamp(value: string) {
  const parsed = new Date(value);
  if (Number.isNaN(parsed.valueOf())) return value;
  return new Intl.DateTimeFormat("en-GB", {
    day: "numeric",
    month: "long",
    year: "numeric",
  }).format(parsed);
}

function ChevronLeftGlyph() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
    >
      <path d="m15 18-6-6 6-6" />
    </svg>
  );
}

function ChevronRightGlyph() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
    >
      <path d="m9 18 6-6-6-6" />
    </svg>
  );
}

function ShareGlyph() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
    >
      <circle cx="18" cy="5" r="3" />
      <circle cx="6" cy="12" r="3" />
      <circle cx="18" cy="19" r="3" />
      <path d="m8.6 10.5 6.8-4M8.6 13.5l6.8 4" />
    </svg>
  );
}
