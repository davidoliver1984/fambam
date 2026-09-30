import {
  useEffect,
  useMemo,
  useRef,
  useState,
  type CSSProperties,
  type SyntheticEvent,
} from "react";
import { Link, useNavigate, useParams } from "react-router";

import {
  Breadcrumbs,
  ActionNotice,
  type ActionNoticeMessage,
  Button,
  ButtonLink,
  ConfirmDialog,
  ContextMenu,
  Dialog,
  EntityLink,
} from "@/components/ui";
import { CollectionPickerDialog } from "@/features/events/components/CollectionPickerDialog";
import { EventTagsEditor } from "@/features/events/components/EventTagsEditor";
import {
  CalendarGlyph,
  CommentGlyph,
  DownloadGlyph,
  EllipsisGlyph,
  ImageGlyph,
  LinkGlyph,
  LocationPinGlyph,
  PencilGlyph,
  PenLineGlyph,
  PeopleGlyph,
  PlusGlyph,
  SearchGlyph,
  SparklesGlyph,
  TrashGlyph,
  XGlyph,
} from "@/features/events/components/EventGlyphs";
import {
  avatarTone,
  initials,
} from "@/features/events/components/eventPresentation";
import { usePopulateCollectionMutation } from "@/features/collections/hooks/useCollections";
import { LoveButton } from "@/features/love/components/LoveButton";
import { usePeopleQuery } from "@/features/people/hooks/usePeopleQuery";
import { PhotoPresentationImage } from "@/features/photos/components/PhotoPresentationImage";
import { useArchiveSearchQuery } from "@/features/search/hooks/useArchiveSearchQuery";
import { useStoryQuery } from "@/features/stories/hooks/useStories";
import type { StorySearchSummary } from "@/features/search/types/search";

import { AlbumDetailPhotoGrid } from "../components/AlbumDetailPhotoGrid";
import {
  useAlbumCoverMutation,
  useAlbumExportMutation,
  useAlbumQuery,
  useAlbumsQuery,
  useAlbumUploadMutation,
  useDeleteAlbumMutation,
  useUpdateAlbumMutation,
} from "../hooks/useAlbumQueries";
import type { Album } from "../types/album";
import "./album-detail.css";

function CropGlyph() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
    >
      <path d="M6 2v14a2 2 0 0 0 2 2h14M2 6h14a2 2 0 0 1 2 2v14" />
    </svg>
  );
}

function ListGlyph() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
    >
      <path d="M4 6h16M4 12h16M4 18h16" />
    </svg>
  );
}

function ChevronGlyph() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
    >
      <path d="m7 10 5 5 5-5" />
    </svg>
  );
}

const albumDate = new Intl.DateTimeFormat("en-GB", {
  day: "numeric",
  month: "long",
  year: "numeric",
  timeZone: "UTC",
});

function parseDate(value: string) {
  return new Date(`${value}T00:00:00Z`);
}

function formatDateRange(start?: string | null, end?: string | null) {
  if (!start) return "Date not recorded";
  if (!end || end === start) return albumDate.format(parseDate(start));
  const first = parseDate(start);
  const last = parseDate(end);
  if (
    first.getUTCFullYear() === last.getUTCFullYear() &&
    first.getUTCMonth() === last.getUTCMonth()
  )
    return `${String(first.getUTCDate())}–${albumDate.format(last)}`;
  return `${albumDate.format(first)} – ${albumDate.format(last)}`;
}

function AlbumEditDialog({
  album,
  familySlug,
  open,
  onClose,
}: {
  album: Album;
  familySlug: string;
  open: boolean;
  onClose: () => void;
}) {
  const people = usePeopleQuery(familySlug, open);
  const update = useUpdateAlbumMutation(familySlug, album.id);
  const [name, setName] = useState(album.name);
  const [description, setDescription] = useState(album.description ?? "");
  const [startsOn, setStartsOn] = useState(album.starts_on ?? "");
  const [endsOn, setEndsOn] = useState(album.ends_on ?? "");
  const [location, setLocation] = useState(album.location ?? "");
  const [tags, setTags] = useState(
    (album.tags ?? []).map((tag) => tag.label).join(", "),
  );
  const [personIds, setPersonIds] = useState(
    (album.people ?? []).map((person) => person.id),
  );

  const submit = (event: SyntheticEvent<HTMLFormElement>) => {
    event.preventDefault();
    update.mutate(
      {
        name: name.trim(),
        ...(description === (album.description ?? "")
          ? {}
          : { description: description.trim() || null }),
        starts_on: startsOn || null,
        ends_on: endsOn || null,
        location: location.trim() || null,
        tags: tags
          .split(",")
          .map((tag) => tag.trim())
          .filter(Boolean),
        person_ids: personIds,
      },
      { onSuccess: onClose },
    );
  };

  return (
    <Dialog
      open={open}
      eyebrow="Album details"
      title={`Edit ${album.name}`}
      description="Update the album details, people and family-space tags."
      className="album-edit-dialog"
      pending={update.isPending}
      onClose={onClose}
    >
      <form className="album-edit-form" onSubmit={submit}>
        <label>
          Name
          <input
            data-autofocus
            required
            value={name}
            onChange={(event) => {
              setName(event.target.value);
            }}
          />
        </label>
        <label>
          Description
          <textarea
            rows={4}
            value={description}
            onChange={(event) => {
              setDescription(event.target.value);
            }}
          />
        </label>
        <div className="album-edit-dates">
          <label>
            Starts
            <input
              type="date"
              value={startsOn}
              onChange={(event) => {
                setStartsOn(event.target.value);
              }}
            />
          </label>
          <label>
            Ends
            <input
              type="date"
              min={startsOn || undefined}
              value={endsOn}
              onChange={(event) => {
                setEndsOn(event.target.value);
              }}
            />
          </label>
        </div>
        <label>
          Location
          <input
            value={location}
            onChange={(event) => {
              setLocation(event.target.value);
            }}
          />
        </label>
        <label>
          Tags
          <input
            value={tags}
            onChange={(event) => {
              setTags(event.target.value);
            }}
            placeholder="Blackpool, Seaside, Family holiday"
          />
        </label>
        <fieldset className="album-people-editor">
          <legend>People in this album</legend>
          <div>
            {(people.data ?? []).map((person) => (
              <label key={person.id}>
                <input
                  type="checkbox"
                  checked={personIds.includes(person.id)}
                  onChange={() => {
                    setPersonIds((current) =>
                      current.includes(person.id)
                        ? current.filter((id) => id !== person.id)
                        : [...current, person.id],
                    );
                  }}
                />
                {person.preferred_name}
              </label>
            ))}
          </div>
        </fieldset>
        {update.isError && (
          <p role="alert">The Album details could not be saved.</p>
        )}
        <footer className="album-dialog-footer">
          <Button
            type="button"
            variant="secondary"
            disabled={update.isPending}
            onClick={onClose}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={update.isPending || name.trim() === ""}
          >
            Save changes
          </Button>
        </footer>
      </form>
    </Dialog>
  );
}

function CoverPickerDialog({
  album,
  open,
  pending,
  onClose,
  onChoose,
}: {
  album: Album;
  open: boolean;
  pending: boolean;
  onClose: () => void;
  onChoose: (photoId: string) => void;
}) {
  const [choice, setChoice] = useState(
    album.cover?.photo_id ?? album.photos.at(0)?.id ?? "",
  );
  return (
    <Dialog
      open={open}
      title="Choose album cover"
      description="Choose a Photo already in this Album."
      pending={pending}
      onClose={onClose}
      className="album-cover-dialog"
    >
      <div className="album-cover-choices">
        {album.photos.map((photo) => (
          <label
            className={choice === photo.id ? "selected" : ""}
            key={photo.id}
          >
            <input
              type="radio"
              name="album-cover"
              value={photo.id}
              checked={choice === photo.id}
              onChange={() => {
                setChoice(photo.id);
              }}
            />
            <span>{photo.caption ?? photo.client_filename}</span>
          </label>
        ))}
      </div>
      <footer className="album-dialog-footer">
        <Button variant="secondary" disabled={pending} onClick={onClose}>
          Cancel
        </Button>
        <Button
          variant="primary"
          disabled={pending || choice === ""}
          onClick={() => {
            onChoose(choice);
          }}
        >
          Use as cover
        </Button>
      </footer>
    </Dialog>
  );
}

function RepositionCoverDialog({
  album,
  familySlug,
  open,
  pending,
  onClose,
  onSave,
}: {
  album: Album;
  familySlug: string;
  open: boolean;
  pending: boolean;
  onClose: () => void;
  onSave: (x: number, y: number) => void;
}) {
  const [x, setX] = useState(album.cover?.focal_x ?? 0.5);
  const [y, setY] = useState(album.cover?.focal_y ?? 0.5);
  return (
    <Dialog
      open={open}
      title="Reposition cover"
      description="Choose the focal point that should remain visible at every screen size."
      pending={pending}
      onClose={onClose}
      className="album-reposition-dialog"
    >
      {album.cover && (
        <div
          className="album-reposition-preview"
          style={
            {
              "--album-cover-position": `${String(x * 100)}% ${String(y * 100)}%`,
            } as CSSProperties
          }
        >
          <PhotoPresentationImage
            familySlug={familySlug}
            photoId={album.cover.photo_id}
            mediaUploadId={album.cover.media_upload_id}
            fallbackTransform="display"
            alt=""
          />
        </div>
      )}
      <label>
        Horizontal focal position
        <input
          type="range"
          min="0"
          max="1"
          step="0.01"
          value={x}
          onChange={(event) => {
            setX(Number(event.target.value));
          }}
        />
      </label>
      <label>
        Vertical focal position
        <input
          type="range"
          min="0"
          max="1"
          step="0.01"
          value={y}
          onChange={(event) => {
            setY(Number(event.target.value));
          }}
        />
      </label>
      <footer className="album-dialog-footer">
        <Button variant="secondary" disabled={pending} onClick={onClose}>
          Cancel
        </Button>
        <Button
          variant="primary"
          disabled={pending}
          onClick={() => {
            onSave(x, y);
          }}
        >
          Save position
        </Button>
      </footer>
    </Dialog>
  );
}

function FeaturedAlbumStory({
  familySlug,
  album,
  summary,
}: {
  familySlug: string;
  album: Album;
  summary: StorySearchSummary;
}) {
  const story = useStoryQuery(familySlug, summary.id);
  const item = story.data;
  return (
    <section className="album-story">
      <div className="album-story__copy">
        <p className="ui-eyebrow">A story from this album</p>
        <h2>{summary.heading}</h2>
        <p>{summary.excerpt}</p>
        <div className="album-story__actions">
          <LoveButton
            familySlug={familySlug}
            targetType="story"
            targetId={summary.id}
          />
          <Link
            to={`/families/${encodeURIComponent(familySlug)}/stories/${encodeURIComponent(summary.id)}`}
            aria-label={`${String(item?.comments.length ?? 0)} comments`}
          >
            <CommentGlyph />
            {item?.comments.length ?? 0}
          </Link>
          <Link
            className="album-story__read"
            to={`/families/${encodeURIComponent(familySlug)}/stories/${encodeURIComponent(summary.id)}`}
          >
            Read the full story →
          </Link>
        </div>
      </div>
      <div className="album-story__photo">
        {album.cover ? (
          <PhotoPresentationImage
            familySlug={familySlug}
            photoId={album.cover.photo_id}
            mediaUploadId={album.cover.media_upload_id}
            fallbackTransform="display"
            alt=""
          />
        ) : (
          <ImageGlyph />
        )}
      </div>
    </section>
  );
}

function MenuSeparator() {
  return <hr className="album-menu-separator" />;
}

function AlbumMenu({
  album,
  familySlug,
  onEdit,
  onUpload,
  onCollection,
  onExport,
  onDelete,
}: {
  album: Album;
  familySlug: string;
  onEdit: () => void;
  onUpload: () => void;
  onCollection: () => void;
  onExport: () => void;
  onDelete: () => void;
}) {
  const [open, setOpen] = useState(false);
  return (
    <ContextMenu
      label="Album options"
      open={open}
      onOpenChange={setOpen}
      trigger={
        <>
          <EllipsisGlyph />
          <span className="sr-only">Album options</span>
        </>
      }
    >
      <Link
        to={`/families/${encodeURIComponent(familySlug)}/albums/${encodeURIComponent(album.id)}`}
      >
        <ImageGlyph />
        Open album
      </Link>
      {album.permissions.can_manage && (
        <button type="button" onClick={onEdit}>
          <PencilGlyph />
          Edit album
        </button>
      )}
      {album.permissions.can_contribute && (
        <button type="button" onClick={onUpload}>
          <PlusGlyph />
          Add photos
        </button>
      )}
      <button type="button" onClick={onCollection}>
        <SparklesGlyph />
        Add photos to collection…
      </button>
      <button
        type="button"
        onClick={() => void navigator.clipboard.writeText(window.location.href)}
      >
        <LinkGlyph />
        Copy Fambam link
      </button>
      <button type="button" onClick={onExport}>
        <DownloadGlyph />
        Download / Export album
      </button>
      {album.permissions.can_manage && (
        <button type="button" onClick={onEdit}>
          <PeopleGlyph />
          Manage people
        </button>
      )}
      {(album.permissions.can_delete ?? album.permissions.can_manage) && (
        <MenuSeparator />
      )}
      {(album.permissions.can_delete ?? album.permissions.can_manage) && (
        <button type="button" className="album-menu-danger" onClick={onDelete}>
          <TrashGlyph />
          Delete album
        </button>
      )}
    </ContextMenu>
  );
}

export function AlbumPage() {
  const { familySlug = "", albumId = "" } = useParams();
  const navigate = useNavigate();
  const query = useAlbumQuery(familySlug, albumId);
  const albums = useAlbumsQuery(familySlug);
  const stories = useArchiveSearchQuery(
    familySlug,
    "stories",
    { album_id: albumId },
    albumId !== "",
  );
  const upload = useAlbumUploadMutation(familySlug);
  const exportAlbum = useAlbumExportMutation(familySlug, albumId);
  const updateTags = useUpdateAlbumMutation(familySlug, albumId);
  const cover = useAlbumCoverMutation(familySlug, albumId);
  const populateCollection = usePopulateCollectionMutation(familySlug, {
    type: "album",
    id: albumId,
  });
  const remove = useDeleteAlbumMutation(familySlug, albumId, () => {
    void navigate(`/families/${encodeURIComponent(familySlug)}/albums`);
  });
  const fileInput = useRef<HTMLInputElement>(null);
  const [editOpen, setEditOpen] = useState(false);
  const [coverPickerOpen, setCoverPickerOpen] = useState(false);
  const [repositionOpen, setRepositionOpen] = useState(false);
  const [collectionOpen, setCollectionOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [showAllPeople, setShowAllPeople] = useState(false);
  const [queryText, setQueryText] = useState("");
  const [sort, setSort] = useState<"oldest" | "newest">("oldest");
  const [view, setView] = useState<"grid" | "list">("grid");
  const [notice, setNotice] = useState<ActionNoticeMessage | null>(null);

  useEffect(() => {
    if (!notice) return;
    const timeout = window.setTimeout(() => {
      setNotice(null);
    }, 5000);
    return () => {
      window.clearTimeout(timeout);
    };
  }, [notice]);

  const photos = useMemo(() => {
    const needle = queryText.trim().toLocaleLowerCase();
    return [...(query.data?.photos ?? [])]
      .filter(
        (photo) =>
          needle === "" ||
          `${photo.caption ?? ""} ${photo.client_filename}`
            .toLocaleLowerCase()
            .includes(needle),
      )
      .sort((left, right) => {
        const a = left.historical_date?.value ?? "";
        const b = right.historical_date?.value ?? "";
        const comparison = a.localeCompare(b) || left.position - right.position;
        return sort === "oldest" ? comparison : -comparison;
      });
  }, [query.data?.photos, queryText, sort]);

  if (query.isPending) return <p role="status">Loading Album…</p>;
  if (query.isError) return <p role="alert">This Album is unavailable.</p>;
  const album = query.data;
  const featuredStory = stories.data?.pages[0]?.items[0];
  const addPhotos = () => fileInput.current?.click();
  const setCover = (photoId: string, focalX?: number, focalY?: number) => {
    cover.mutate(
      {
        photoId,
        confirmVisibilityWidening: false,
        ...(focalX === undefined ? {} : { focalX }),
        ...(focalY === undefined ? {} : { focalY }),
      },
      {
        onSuccess: () => {
          setCoverPickerOpen(false);
          setRepositionOpen(false);
          setNotice({ title: "Album cover updated." });
        },
      },
    );
  };

  return (
    <main className="album-detail" aria-labelledby="album-title">
      <Breadcrumbs
        items={[
          {
            label: "Albums",
            to: `/families/${encodeURIComponent(familySlug)}/albums`,
          },
          { label: album.name },
        ]}
      />
      {notice && (
        <ActionNotice
          {...notice}
          onDismiss={() => {
            setNotice(null);
          }}
        />
      )}
      <section className="album-overview-card">
        <div
          className={`album-cover-large${album.cover ? "" : " no-cover"}`}
          style={
            album.cover
              ? ({
                  "--album-cover-position": `${String(album.cover.focal_x * 100)}% ${String(album.cover.focal_y * 100)}%`,
                } as CSSProperties)
              : undefined
          }
        >
          {album.cover ? (
            <PhotoPresentationImage
              familySlug={familySlug}
              photoId={album.cover.photo_id}
              mediaUploadId={album.cover.media_upload_id}
              fallbackTransform="display"
              alt={`${album.name} cover`}
            />
          ) : (
            <div className="album-no-cover">
              <ImageGlyph />
              <b>No cover photo</b>
              <span>Add one whenever this Album is ready.</span>
            </div>
          )}
          {album.permissions.can_manage && (
            <ContextMenu
              label={album.cover ? "Cover options" : "Add cover photo"}
              placement="bottom-start"
              trigger={
                <>
                  <ImageGlyph />
                  {album.cover ? "Cover options" : "Add cover photo"}
                </>
              }
            >
              {album.cover ? (
                <>
                  <button
                    type="button"
                    onClick={() => {
                      setCoverPickerOpen(true);
                    }}
                  >
                    <ImageGlyph />
                    Change cover photo
                  </button>
                  <button
                    type="button"
                    onClick={() => {
                      setRepositionOpen(true);
                    }}
                  >
                    <CropGlyph />
                    Reposition cover
                  </button>
                  <MenuSeparator />
                  <button
                    type="button"
                    className="album-menu-danger"
                    onClick={() => {
                      cover.mutate(
                        { photoId: null },
                        {
                          onSuccess: () => {
                            setNotice({ title: "Cover photo removed." });
                          },
                        },
                      );
                    }}
                  >
                    <TrashGlyph />
                    Remove cover photo
                  </button>
                </>
              ) : (
                <button
                  type="button"
                  disabled={album.photos.length === 0}
                  onClick={() => {
                    setCoverPickerOpen(true);
                  }}
                >
                  <PlusGlyph />
                  Add cover photo
                </button>
              )}
            </ContextMenu>
          )}
          {album.cover_pending && (
            <span className="album-cover-pending" role="status">
              A new cover is being prepared.
            </span>
          )}
        </div>
        <div className="album-overview-copy">
          <div className="album-heading-row">
            <div>
              <p className="ui-eyebrow">Album</p>
              <h1 id="album-title">{album.name}</h1>
            </div>
            <div className="album-action-group">
              <ButtonLink
                variant="secondary"
                to={`/families/${encodeURIComponent(familySlug)}/stories/new?type=album&subjectId=${encodeURIComponent(album.id)}`}
              >
                <PenLineGlyph />
                Write story
              </ButtonLink>
              {album.permissions.can_contribute && (
                <Button variant="primary" onClick={addPhotos}>
                  <PlusGlyph />
                  Add photos
                </Button>
              )}
              <LoveButton
                familySlug={familySlug}
                targetType="album"
                targetId={album.id}
              />
              {album.permissions.can_manage && (
                <Button
                  iconOnly
                  aria-label="Edit album"
                  onClick={() => {
                    setEditOpen(true);
                  }}
                >
                  <PencilGlyph />
                </Button>
              )}
              <AlbumMenu
                album={album}
                familySlug={familySlug}
                onEdit={() => {
                  setEditOpen(true);
                }}
                onUpload={addPhotos}
                onCollection={() => {
                  setCollectionOpen(true);
                }}
                onExport={() => {
                  exportAlbum.mutate(undefined, {
                    onSuccess: () => {
                      setNotice({
                        title: "Album export started",
                        description:
                          "Only Photos you are authorised to download are included.",
                      });
                    },
                  });
                }}
                onDelete={() => {
                  setDeleteOpen(true);
                }}
              />
            </div>
          </div>
          {album.description_html ? (
            <div
              className="album-description"
              dangerouslySetInnerHTML={{ __html: album.description_html }}
            />
          ) : album.description ? (
            <p className="album-description">{album.description}</p>
          ) : null}
          <div className="album-meta-row">
            <span>
              <CalendarGlyph />
              {formatDateRange(album.starts_on, album.ends_on)}
            </span>
            <span>
              <ImageGlyph />
              {album.photos.length}{" "}
              {album.photos.length === 1 ? "photo" : "photos"}
            </span>
            {album.location && (
              <span>
                <LocationPinGlyph />
                {album.location}
              </span>
            )}
            <span>
              <PeopleGlyph />
              {album.visibility === "family_space"
                ? "Family album"
                : album.visibility === "selected"
                  ? "Selected people"
                  : "Private album"}
            </span>
          </div>
          <div className="album-detail-group">
            <b>People in this album</b>
            <div className="album-people-list">
              {(album.people ?? [])
                .slice(0, showAllPeople ? (album.people ?? []).length : 4)
                .map((person) => (
                  <EntityLink
                    key={person.id}
                    entity="person"
                    to={`/families/${encodeURIComponent(familySlug)}/people/${encodeURIComponent(person.id)}`}
                    className={`album-person-avatar${avatarTone(person.name)}`}
                  >
                    <span aria-hidden="true">{initials(person.name)}</span>
                    <span className="sr-only">{person.name}</span>
                  </EntityLink>
                ))}
              {(album.people ?? []).length > 4 && !showAllPeople && (
                <button
                  type="button"
                  className="album-people-more"
                  onClick={() => {
                    setShowAllPeople(true);
                  }}
                >
                  and {(album.people ?? []).length - 4} others
                </button>
              )}
              {(album.people ?? []).length === 0 && (
                <span className="album-empty-meta">No People added</span>
              )}
            </div>
          </div>
          <div className="album-detail-group">
            <b>Tags</b>
            <div className="album-tags-editor">
              <EventTagsEditor
                familySlug={familySlug}
                tags={album.tags ?? []}
                entityLabel="Album"
                variant="inline-add"
                canEdit={album.permissions.can_manage}
                pending={updateTags.isPending}
                saveError={updateTags.isError}
                onSave={(tags) => updateTags.mutateAsync({ tags })}
              />
            </div>
          </div>
        </div>
      </section>

      <section
        className="album-photo-catalogue"
        aria-labelledby="album-photos-title"
      >
        <div className="album-photo-toolbar">
          <h2 id="album-photos-title">
            Photos <span>{album.photos.length}</span>
          </h2>
          <label className="album-search">
            <SearchGlyph />
            <span className="sr-only">Search this album</span>
            <input
              value={queryText}
              placeholder="Search this album…"
              onChange={(event) => {
                setQueryText(event.target.value);
              }}
            />
          </label>
          <label className="album-sort">
            <span className="sr-only">Sort photos</span>
            <select
              value={sort}
              onChange={(event) => {
                setSort(event.target.value as "oldest" | "newest");
              }}
            >
              <option value="oldest">Sort: Oldest first</option>
              <option value="newest">Sort: Newest first</option>
            </select>
            <ChevronGlyph />
          </label>
          <div className="album-view-toggle" aria-label="Photo view">
            <button
              type="button"
              className={view === "grid" ? "active" : ""}
              aria-label="Grid view"
              aria-pressed={view === "grid"}
              onClick={() => {
                setView("grid");
              }}
            >
              <ImageGlyph />
            </button>
            <button
              type="button"
              className={view === "list" ? "active" : ""}
              aria-label="List view"
              aria-pressed={view === "list"}
              onClick={() => {
                setView("list");
              }}
            >
              <ListGlyph />
            </button>
          </div>
        </div>
        {photos.length > 0 ? (
          <AlbumDetailPhotoGrid
            familySlug={familySlug}
            album={album}
            photos={photos}
            availableAlbums={albums.data ?? [album]}
            view={view}
            onCreateAlbum={() =>
              void navigate(
                `/families/${encodeURIComponent(familySlug)}/albums#create-album-title`,
              )
            }
            onSetCover={(photoId) => {
              setCover(photoId);
            }}
          />
        ) : (
          <div className="album-photo-empty">
            <ImageGlyph />
            <b>
              {album.photos.length === 0
                ? "No photos in this Album yet"
                : "No photos match your search"}
            </b>
            <span>
              {album.photos.length === 0
                ? "Add photographs when this Album is ready."
                : "Try a different title or file name."}
            </span>
            {album.photos.length === 0 && album.permissions.can_contribute && (
              <Button variant="primary" onClick={addPhotos}>
                <PlusGlyph />
                Add photos
              </Button>
            )}
            {album.photos.length > 0 && (
              <Button
                variant="secondary"
                onClick={() => {
                  setQueryText("");
                }}
              >
                <XGlyph />
                Clear search
              </Button>
            )}
          </div>
        )}
      </section>
      {featuredStory && (
        <FeaturedAlbumStory
          familySlug={familySlug}
          album={album}
          summary={featuredStory}
        />
      )}

      <input
        ref={fileInput}
        className="sr-only album-file-input"
        type="file"
        aria-label="Add photographs to this Album"
        accept="image/jpeg,image/png,image/heic,image/heif,image/webp,image/tiff"
        onChange={(event) => {
          const file = event.target.files?.[0];
          if (!file) return;
          upload.mutate(
            { albumId: album.id, file },
            {
              onSuccess: () => {
                setNotice({
                  title: "Upload received.",
                  description: "Processing may take a moment.",
                });
              },
            },
          );
          event.target.value = "";
        }}
      />
      {upload.isError && (
        <p className="album-action-error" role="alert">
          The photograph could not be uploaded.
        </p>
      )}
      {exportAlbum.isError && (
        <p className="album-action-error" role="alert">
          The Album export could not be requested.
        </p>
      )}
      {cover.isError && (
        <p className="album-action-error" role="alert">
          The Album cover could not be saved.
        </p>
      )}

      {editOpen && (
        <AlbumEditDialog
          album={album}
          familySlug={familySlug}
          open
          onClose={() => {
            setEditOpen(false);
          }}
        />
      )}
      {coverPickerOpen && (
        <CoverPickerDialog
          album={album}
          open
          pending={cover.isPending}
          onClose={() => {
            setCoverPickerOpen(false);
          }}
          onChoose={setCover}
        />
      )}
      {repositionOpen && album.cover && (
        <RepositionCoverDialog
          album={album}
          familySlug={familySlug}
          open
          pending={cover.isPending}
          onClose={() => {
            setRepositionOpen(false);
          }}
          onSave={(x, y) => {
            setCover(album.cover?.photo_id ?? "", x, y);
          }}
        />
      )}
      <CollectionPickerDialog
        open={collectionOpen}
        familySlug={familySlug}
        mode="source"
        sourceName={album.name}
        pending={populateCollection.isPending}
        onClose={() => {
          setCollectionOpen(false);
        }}
        onAdd={(collectionId) => {
          populateCollection.mutate(collectionId, {
            onSuccess: () => {
              setNotice({ title: "Album photos added to collection." });
            },
          });
        }}
      />
      <ConfirmDialog
        open={deleteOpen}
        title={`Delete “${album.name}”?`}
        confirmLabel="Delete album"
        destructive
        pending={remove.isPending}
        onCancel={() => {
          setDeleteOpen(false);
        }}
        onConfirm={() => {
          remove.mutate();
        }}
      >
        <p>
          This Album will be removed. Its Photos remain safely in the Family
          Space unless they are deleted separately.
        </p>
        {remove.isError && <p role="alert">The Album could not be deleted.</p>}
      </ConfirmDialog>
    </main>
  );
}
