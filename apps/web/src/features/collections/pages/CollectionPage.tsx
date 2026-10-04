import { useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { Link, useNavigate, useParams } from "react-router";

import {
  Breadcrumbs,
  Button,
  ConfirmDialog,
  ContextMenu,
  Dialog,
} from "@/components/ui";
import {
  useFamilyExportMutations,
  useFamilyExportsQuery,
} from "@/features/exports/hooks/useFamilyExports";
import type { FamilyExport } from "@/features/exports/types/familyExport";
import { PhotoPresentationImage } from "@/features/photos/components/PhotoPresentationImage";
import { usePhotosQuery } from "@/features/photos/hooks/usePhotoQueries";
import type { UncertainDate } from "@/features/photos/types/photo";

import {
  useAddCollectionPhotosMutation,
  useCollectionMutations,
  useCollectionQuery,
  useReorderCollectionPhotosMutation,
  useUpdateCollectionMutation,
} from "../hooks/useCollections";

import "./collection-detail.css";

const dateFormatter = new Intl.DateTimeFormat("en-GB", {
  day: "numeric",
  month: "long",
  year: "numeric",
  timeZone: "UTC",
});

type IconName =
  | "check"
  | "download"
  | "lock"
  | "pencil"
  | "plus"
  | "sparkles"
  | "trash"
  | "up"
  | "down"
  | "x";

function Icon({ name }: { name: IconName }) {
  const paths: Record<IconName, ReactNode> = {
    check: <path d="m5 12 4 4L19 6" />,
    download: <path d="M12 3v12m-5-5 5 5 5-5M5 21h14" />,
    lock: (
      <>
        <rect x="5" y="10" width="14" height="11" rx="2" />
        <path d="M8 10V7a4 4 0 0 1 8 0v3" />
      </>
    ),
    pencil: <path d="m4 20 4.5-1 10-10a2.1 2.1 0 0 0-3-3l-10 10L4 20Z" />,
    plus: <path d="M12 5v14M5 12h14" />,
    sparkles: (
      <>
        <path d="m12 3 1.2 3.8L17 8l-3.8 1.2L12 13l-1.2-3.8L7 8l3.8-1.2L12 3Z" />
        <path d="m19 14 .7 2.3L22 17l-2.3.7L19 20l-.7-2.3L16 17l2.3-.7L19 14ZM5 13l.9 2.1L8 16l-2.1.9L5 19l-.9-2.1L2 16l2.1-.9L5 13Z" />
      </>
    ),
    trash: (
      <>
        <path d="M4 7h16M9 7V4h6v3m3 0-1 14H7L6 7" />
        <path d="M10 11v6m4-6v6" />
      </>
    ),
    up: <path d="m6 14 6-6 6 6" />,
    down: <path d="m6 10 6 6 6-6" />,
    x: <path d="m6 6 12 12M18 6 6 18" />,
  };
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      {paths[name]}
    </svg>
  );
}

function formatHistoricalDate(date: UncertainDate | null): string | null {
  if (date === null || date.value === null || date.precision === "unknown")
    return null;
  if (date.precision === "decade" || date.precision === "year")
    return date.value;
  if (date.precision === "month") {
    const parsed = new Date(`${date.value}-01T00:00:00Z`);
    return Number.isNaN(parsed.getTime())
      ? date.value
      : new Intl.DateTimeFormat("en-GB", {
          month: "long",
          year: "numeric",
          timeZone: "UTC",
        }).format(parsed);
  }
  const parsed = new Date(`${date.value}T00:00:00Z`);
  const label = Number.isNaN(parsed.getTime())
    ? date.value
    : dateFormatter.format(parsed);
  return date.precision === "approximate" ? `Around ${label}` : label;
}

function latestCollectionExport(
  exports: FamilyExport[] | undefined,
  collectionId: string,
): FamilyExport | undefined {
  return exports
    ?.filter((item) => item.collection_id === collectionId)
    .toSorted((a, b) =>
      (b.created_at ?? "").localeCompare(a.created_at ?? ""),
    )[0];
}

function exportLabel(item: FamilyExport | undefined, requesting: boolean) {
  if (requesting) return "Requesting export…";
  if (item === undefined) return "Export final set";
  if (item.state === "pending" || item.state === "processing")
    return "Preparing final set…";
  if (item.state === "ready") return "Download final set";
  return "Try export again";
}

export function CollectionPage() {
  const { familySlug = "", collectionId = "" } = useParams();
  const navigate = useNavigate();
  const collection = useCollectionQuery(familySlug, collectionId);
  const exports = useFamilyExportsQuery(familySlug, true);
  const exportActions = useFamilyExportMutations(familySlug);
  const actions = useCollectionMutations(familySlug, collectionId);
  const update = useUpdateCollectionMutation(familySlug, collectionId);
  const reorder = useReorderCollectionPhotosMutation(familySlug, collectionId);
  const addPhotos = useAddCollectionPhotosMutation(familySlug, collectionId);
  const [draft, setDraft] = useState<{
    name: string;
    description: string;
  } | null>(null);
  const titleInput = useRef<HTMLInputElement>(null);
  const [addOpen, setAddOpen] = useState(false);
  const photos = usePhotosQuery(familySlug, {}, addOpen);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [selected, setSelected] = useState<string[]>([]);
  const [notice, setNotice] = useState<string | null>(null);
  const base = `/families/${encodeURIComponent(familySlug)}`;
  const editing = draft !== null;

  useEffect(() => {
    if (notice === null) return;
    const timeout = window.setTimeout(() => {
      setNotice(null);
    }, 5_000);
    return () => {
      window.clearTimeout(timeout);
    };
  }, [notice]);

  useEffect(() => {
    if (!editing) return;
    const focusAtStart = () => {
      titleInput.current?.focus({ preventScroll: true });
      titleInput.current?.setSelectionRange(0, 0);
      if (titleInput.current !== null) titleInput.current.scrollLeft = 0;
    };
    focusAtStart();
    const frame = window.requestAnimationFrame(focusAtStart);
    return () => {
      window.cancelAnimationFrame(frame);
    };
  }, [editing]);

  const included = useMemo(
    () => new Set((collection.data?.photos ?? []).map((item) => item.id)),
    [collection.data?.photos],
  );
  const candidates = (photos.data ?? []).filter(
    (photo) => !included.has(photo.id),
  );
  const queriedExport = latestCollectionExport(exports.data, collectionId);
  const activeExport =
    actions.requestExport.data !== undefined &&
    (queriedExport === undefined ||
      (actions.requestExport.data.created_at ?? "") >=
        (queriedExport.created_at ?? ""))
      ? actions.requestExport.data
      : queriedExport;

  if (collection.isPending)
    return (
      <div className="collection-page-state" role="status">
        Loading Collection…
      </div>
    );
  if (collection.isError)
    return (
      <div
        className="collection-page-state collection-page-state--error"
        role="alert"
      >
        This Collection is unavailable.
      </div>
    );

  const item = collection.data;
  const itemPhotos = item.photos ?? [];
  const editName = draft?.name ?? item.name;
  const editDescription = draft?.description ?? item.description ?? "";
  const exportPending =
    actions.requestExport.isPending ||
    activeExport?.state === "pending" ||
    activeExport?.state === "processing";

  function startExport() {
    if (activeExport?.state === "ready") {
      exportActions.download.mutate(activeExport.id, {
        onSuccess: ({ url }) => {
          window.location.assign(url);
        },
      });
      return;
    }
    if (exportPending) return;
    actions.requestExport.mutate(undefined, {
      onSuccess: () => {
        setNotice("Collection export requested");
      },
    });
  }

  function move(index: number, direction: -1 | 1) {
    const nextIndex = index + direction;
    if (nextIndex < 0 || nextIndex >= itemPhotos.length) return;
    const ids = itemPhotos.map((photo) => photo.id);
    [ids[index], ids[nextIndex]] = [ids[nextIndex], ids[index]];
    reorder.mutate(ids, {
      onSuccess: () => {
        setNotice("Photo order updated");
      },
    });
  }

  return (
    <main className="collection-detail-page" aria-labelledby="collection-title">
      <Breadcrumbs
        items={[
          { label: "Collections", to: `${base}/collections` },
          { label: item.name },
        ]}
      />
      <header className="collection-detail-head">
        <div className="collection-detail-copy">
          <p className="ui-eyebrow">Private collection</p>
          {editing ? (
            <>
              <label className="ui-visually-hidden" htmlFor="collection-name">
                Collection title
              </label>
              <input
                ref={titleInput}
                id="collection-name"
                className="collection-title-input"
                value={editName}
                maxLength={120}
                required
                onChange={(event) => {
                  setDraft((current) => ({
                    name: event.target.value,
                    description: current?.description ?? "",
                  }));
                }}
              />
              <label
                className="ui-visually-hidden"
                htmlFor="collection-description"
              >
                Collection description
              </label>
              <textarea
                id="collection-description"
                value={editDescription}
                maxLength={5000}
                onChange={(event) => {
                  setDraft((current) => ({
                    name: current?.name ?? item.name,
                    description: event.target.value,
                  }));
                }}
              />
              {update.isError && (
                <p className="collection-inline-error" role="alert">
                  The Collection could not be updated.
                </p>
              )}
            </>
          ) : (
            <>
              <h1 id="collection-title">{item.name}</h1>
              {item.description !== null && <p>{item.description}</p>}
            </>
          )}
        </div>
        <div className="collection-head-actions">
          {editing ? (
            <Button
              variant="primary"
              disabled={update.isPending || editName.trim() === ""}
              onClick={() => {
                update.mutate(
                  {
                    name: editName.trim(),
                    description:
                      editDescription.trim() === ""
                        ? null
                        : editDescription.trim(),
                  },
                  {
                    onSuccess: () => {
                      setDraft(null);
                      setNotice("Collection updated");
                    },
                  },
                );
              }}
            >
              <Icon name="check" />
              Save changes
            </Button>
          ) : (
            <Button
              onClick={() => {
                setDraft({
                  name: item.name,
                  description: item.description ?? "",
                });
              }}
            >
              <Icon name="pencil" />
              Rename &amp; describe
            </Button>
          )}
          <Button
            variant="primary"
            onClick={() => {
              setSelected([]);
              setAddOpen(true);
            }}
          >
            <Icon name="plus" />
            Add Photos
          </Button>
          <ContextMenu label="Collection options" placement="bottom-end">
            <button
              type="button"
              disabled={exportPending || exportActions.download.isPending}
              onClick={startExport}
            >
              <Icon name="download" />
              {activeExport?.state === "ready"
                ? "Download Photo set"
                : "Export Photo set"}
            </button>
            <hr />
            <button
              type="button"
              className="collection-menu-danger"
              onClick={() => {
                setDeleteOpen(true);
              }}
            >
              <Icon name="trash" />
              Delete collection
            </button>
          </ContextMenu>
        </div>
      </header>

      <section className="collection-boundary" aria-label="Collection privacy">
        <Icon name="lock" />
        <span>
          <b>Only you can see this Collection</b>
          <small>
            {itemPhotos.length} unique Photo
            {itemPhotos.length === 1 ? "" : "s"}
            {" · "}Changes here never remove a Photo from its Album or Event
          </small>
        </span>
        <Button
          disabled={exportPending || exportActions.download.isPending}
          onClick={startExport}
        >
          <Icon name="download" />
          {exportLabel(activeExport, actions.requestExport.isPending)}
        </Button>
      </section>

      {(exports.isError ||
        actions.requestExport.isError ||
        exportActions.download.isError) && (
        <p className="collection-export-message" role="alert">
          {exportActions.download.isError
            ? "The Collection download could not be authorised."
            : "The Collection export could not be requested."}
        </p>
      )}
      {activeExport !== undefined &&
        (activeExport.state === "pending" ||
          activeExport.state === "processing") && (
          <p className="collection-export-message" role="status">
            Your final Photo set is being prepared.
          </p>
        )}
      {activeExport?.state === "failed" && (
        <p className="collection-export-message" role="alert">
          The final Photo set could not be prepared. You can try again.
        </p>
      )}
      {activeExport?.state === "expired" && (
        <p className="collection-export-message" role="status">
          The previous download expired. Request a fresh Photo set.
        </p>
      )}

      {reorder.isError && (
        <p className="collection-row-error" role="alert">
          The Photo order could not be updated.
        </p>
      )}
      {actions.removePhoto.isError && (
        <p className="collection-row-error" role="alert">
          The Photo could not be removed from this Collection.
        </p>
      )}
      <ol className="collection-curation-list">
        {itemPhotos.map((photo, index) => {
          const meta = [
            photo.location_description,
            photo.people.map((person) => person.preferred_name).join(", "),
            formatHistoricalDate(photo.historical_date),
          ].filter((value): value is string => value !== null && value !== "");
          return (
            <li key={photo.id}>
              <span className="collection-curation-order">{index + 1}</span>
              <Link
                className="collection-curation-photo"
                to={`${base}/photos/${encodeURIComponent(photo.id)}?collectionId=${encodeURIComponent(collectionId)}`}
                aria-label={`View ${photo.caption ?? "Untitled Photo"}`}
              >
                <PhotoPresentationImage
                  familySlug={familySlug}
                  photoId={photo.id}
                  mediaUploadId={photo.media_upload_id}
                  fallbackTransform="thumbnail"
                  alt=""
                />
              </Link>
              <span className="collection-curation-copy">
                <b>{photo.caption ?? "Untitled Photo"}</b>
                <small>{meta.join(" · ") || "Details not recorded"}</small>
              </span>
              <span className="collection-reorder-actions">
                <button
                  type="button"
                  disabled={reorder.isPending || index === 0}
                  aria-label={`Move ${photo.caption ?? "Untitled Photo"} up`}
                  onClick={() => {
                    move(index, -1);
                  }}
                >
                  <Icon name="up" />
                </button>
                <button
                  type="button"
                  disabled={
                    reorder.isPending || index === itemPhotos.length - 1
                  }
                  aria-label={`Move ${photo.caption ?? "Untitled Photo"} down`}
                  onClick={() => {
                    move(index, 1);
                  }}
                >
                  <Icon name="down" />
                </button>
                <button
                  type="button"
                  className="collection-remove-photo"
                  disabled={actions.removePhoto.isPending}
                  aria-label={`Remove ${photo.caption ?? "Untitled Photo"} from Collection`}
                  onClick={() => {
                    actions.removePhoto.mutate(photo.id, {
                      onSuccess: () => {
                        setNotice("Photo removed from Collection");
                      },
                    });
                  }}
                >
                  <Icon name="x" />
                  Remove
                </button>
              </span>
            </li>
          );
        })}
      </ol>

      {itemPhotos.length === 0 && (
        <section className="collection-empty-state">
          <Icon name="sparkles" />
          <h2>This Collection is empty</h2>
          <p>Add existing Photos from the Family Space.</p>
          <Button
            onClick={() => {
              setSelected([]);
              setAddOpen(true);
            }}
          >
            Add Photos
          </Button>
        </section>
      )}

      <Dialog
        open={addOpen}
        title="Add existing Photos"
        description="Choose Photos from anywhere in the Family Space. A Photo can only appear once in this Collection."
        className="collection-photo-picker"
        pending={addPhotos.isPending}
        onClose={() => {
          setAddOpen(false);
        }}
      >
        {photos.isPending ? (
          <p role="status">Loading Photos…</p>
        ) : photos.isError ? (
          <p role="alert">Photos could not be loaded.</p>
        ) : candidates.length === 0 ? (
          <p className="collection-picker-empty">
            Every available Photo is already in this Collection.
          </p>
        ) : (
          <div className="collection-photo-choices">
            {candidates.map((photo) => (
              <label key={photo.id}>
                <input
                  type="checkbox"
                  checked={selected.includes(photo.id)}
                  onChange={() => {
                    setSelected((current) =>
                      current.includes(photo.id)
                        ? current.filter((id) => id !== photo.id)
                        : [...current, photo.id],
                    );
                  }}
                />
                <span className="collection-choice-thumb">
                  <PhotoPresentationImage
                    familySlug={familySlug}
                    photoId={photo.id}
                    mediaUploadId={photo.media_upload.id}
                    fallbackTransform="thumbnail"
                    alt=""
                  />
                </span>
                <span>
                  <b>{photo.caption ?? photo.media_upload.client_filename}</b>
                  <small>
                    {[
                      photo.location_description,
                      photo.people
                        .filter((person) => person.status === "approved")
                        .map((person) => person.person.preferred_name)
                        .join(", "),
                      formatHistoricalDate(photo.historical_date),
                    ]
                      .filter(Boolean)
                      .join(" · ") || "Details not recorded"}
                  </small>
                </span>
              </label>
            ))}
          </div>
        )}
        {addPhotos.isError && (
          <p className="collection-dialog-error" role="alert">
            The selected Photos could not be added. Nothing was changed.
          </p>
        )}
        <footer className="collection-dialog-footer">
          <Button
            disabled={addPhotos.isPending}
            onClick={() => {
              setAddOpen(false);
            }}
          >
            Cancel
          </Button>
          <Button
            variant="primary"
            disabled={addPhotos.isPending || selected.length === 0}
            onClick={() => {
              addPhotos.mutate(selected, {
                onSuccess: () => {
                  const count = selected.length;
                  setSelected([]);
                  setAddOpen(false);
                  setNotice(
                    `${String(count)} unique Photo${count === 1 ? "" : "s"} added to this Collection`,
                  );
                },
              });
            }}
          >
            Add {selected.length || ""} Photo
            {selected.length === 1 ? "" : "s"}
          </Button>
        </footer>
      </Dialog>

      <ConfirmDialog
        open={deleteOpen}
        title={`Delete “${item.name}”?`}
        confirmLabel="Delete collection"
        className="collection-delete-dialog"
        destructive
        pending={actions.removeCollection.isPending}
        onCancel={() => {
          setDeleteOpen(false);
        }}
        onConfirm={() => {
          actions.removeCollection.mutate(undefined, {
            onSuccess: () => {
              void navigate(`${base}/collections`);
            },
          });
        }}
      >
        <p>
          The Collection will be removed. Its Photos stay in Fambam, their
          Albums and their Events.
        </p>
        {actions.removeCollection.isError && (
          <p role="alert">The Collection could not be deleted.</p>
        )}
      </ConfirmDialog>

      {notice !== null && (
        <div className="collection-notice" role="status">
          <span>{notice}</span>
          <button
            type="button"
            aria-label="Dismiss notification"
            onClick={() => {
              setNotice(null);
            }}
          >
            <Icon name="x" />
          </button>
        </div>
      )}
    </main>
  );
}
