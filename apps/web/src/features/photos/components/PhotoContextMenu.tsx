import { useEffect, useMemo, useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Link, useLocation, useNavigate } from "react-router";

import {
  ActionNotice,
  Button,
  ConfirmDialog,
  ContextMenu,
  Dialog,
  type ActionNoticeMessage,
} from "@/components/ui";
import {
  useAddAlbumPhotoMutation,
  useAlbumsQuery,
  useRemoveAlbumPhotoMutation,
} from "@/features/albums/hooks/useAlbumQueries";
import type { Album } from "@/features/albums/types/album";
import { addCollectionPhoto } from "@/features/collections/api/collectionApi";
import { collectionKeys } from "@/features/collections/api/collectionKeys";
import { CollectionPickerDialog } from "@/features/events/components/CollectionPickerDialog";
import {
  DownloadGlyph,
  EllipsisGlyph,
  ImageGlyph,
  LinkGlyph,
  PencilGlyph,
  PlusGlyph,
  ScanFaceGlyph,
  SearchGlyph,
  ShieldGlyph,
  SlidersGlyph,
  SparklesGlyph,
  XGlyph,
} from "@/features/events/components/EventGlyphs";
import { getOriginalMediaDelivery } from "@/features/media-uploads/api/mediaUploadApi";

import { usePhotoAlbumHistoryQuery } from "../hooks/usePhotoQueries";
import type { Photo } from "../types/photo";
import { PhotoEditorDialog } from "./PhotoEditorDialog";

type Props = {
  familySlug: string;
  photo: Photo;
};

export function PhotoContextMenu({ familySlug, photo }: Props) {
  const navigate = useNavigate();
  const location = useLocation();
  const queryClient = useQueryClient();
  const [menuOpen, setMenuOpen] = useState(false);
  const [albumPickerOpen, setAlbumPickerOpen] = useState(false);
  const [albumSearch, setAlbumSearch] = useState("");
  const [albumChoices, setAlbumChoices] = useState<string[] | null>(null);
  const [collectionOpen, setCollectionOpen] = useState(false);
  const [editorOpen, setEditorOpen] = useState(false);
  const [removePickerOpen, setRemovePickerOpen] = useState(false);
  const [removeAlbum, setRemoveAlbum] = useState<{
    id: string;
    name: string;
  } | null>(null);
  const [notice, setNotice] = useState<ActionNoticeMessage | null>(null);
  const photoUrl = `/families/${encodeURIComponent(familySlug)}/photos/${encodeURIComponent(photo.id)}`;
  const albumDialogOpen = albumPickerOpen || removePickerOpen;
  const albums = useAlbumsQuery(familySlug, {}, albumDialogOpen);
  const history = usePhotoAlbumHistoryQuery(
    familySlug,
    photo.id,
    albumDialogOpen,
  );
  const availableAlbums = useMemo(() => albums.data ?? [], [albums.data]);
  const currentAlbums = useMemo(() => {
    const unique = new Map<string, { id: string; name: string }>();
    (history.data ?? [])
      .filter((item) => item.is_current)
      .forEach((item) => {
        unique.set(item.album.id, item.album);
      });
    return [...unique.values()];
  }, [history.data]);
  const addAlbumOptions = useMemo(() => {
    const options = new Map<
      string,
      { id: string; name: string; album: Album | null }
    >();
    currentAlbums.forEach((album) => {
      options.set(album.id, { ...album, album: null });
    });
    availableAlbums.forEach((album) => {
      options.set(album.id, { id: album.id, name: album.name, album });
    });
    return [...options.values()];
  }, [availableAlbums, currentAlbums]);
  const filteredAlbumOptions = addAlbumOptions.filter((option) =>
    option.name.toLocaleLowerCase().includes(albumSearch.toLocaleLowerCase()),
  );
  const selectedAlbumChoices =
    albumChoices ?? currentAlbums.map((album) => album.id);
  const singleCurrentAlbum =
    currentAlbums.length === 1 ? currentAlbums[0] : undefined;
  const singleRemovableAlbum =
    removePickerOpen &&
    history.isSuccess &&
    albums.isSuccess &&
    singleCurrentAlbum !== undefined &&
    availableAlbums.some(
      (album) =>
        album.id === singleCurrentAlbum.id && album.permissions.can_manage,
    )
      ? singleCurrentAlbum
      : null;
  const removalTarget = removeAlbum ?? singleRemovableAlbum;
  const addAlbum = useAddAlbumPhotoMutation(familySlug);
  const removeAlbumPhoto = useRemoveAlbumPhotoMutation(familySlug);

  const saveMemberships = useMutation({
    mutationFn: async (albumIds: string[]) => {
      const currentIds = new Set(currentAlbums.map((album) => album.id));
      const nextIds = new Set(albumIds);
      await Promise.all([
        ...albumIds
          .filter((albumId) => !currentIds.has(albumId))
          .map((albumId) =>
            addAlbum.mutateAsync({
              albumId,
              photoId: photo.id,
              confirmed: false,
            }),
          ),
        ...currentAlbums
          .filter((album) => {
            const available = availableAlbums.find(
              (candidate) => candidate.id === album.id,
            );
            return (
              available?.permissions.can_manage === true &&
              !nextIds.has(album.id)
            );
          })
          .map((album) =>
            removeAlbumPhoto.mutateAsync({
              albumId: album.id,
              photoId: photo.id,
            }),
          ),
      ]);
    },
  });
  const removeMembership = useMutation({
    mutationFn: (album: { id: string; name: string }) =>
      removeAlbumPhoto.mutateAsync({ albumId: album.id, photoId: photo.id }),
  });
  const addToCollection = useMutation({
    mutationFn: (collectionId: string) =>
      addCollectionPhoto(familySlug, collectionId, photo.id),
    onSuccess: () =>
      queryClient.invalidateQueries({
        queryKey: collectionKeys.list(familySlug),
      }),
  });
  useEffect(() => {
    if (notice === null) return;
    const timeout = window.setTimeout(() => {
      setNotice(null);
    }, 4_000);
    return () => {
      window.clearTimeout(timeout);
    };
  }, [notice]);

  const download = useMutation({
    mutationFn: () =>
      getOriginalMediaDelivery(familySlug, photo.media_upload.id),
    onSuccess: (delivery) => {
      const link = document.createElement("a");
      link.href = delivery.url;
      link.download = photo.media_upload.client_filename;
      document.body.append(link);
      link.click();
      link.remove();
      setNotice({ title: "Original download started" });
    },
  });

  return (
    <>
      <ContextMenu
        label="Photo options"
        open={menuOpen}
        onOpenChange={setMenuOpen}
        trigger={
          <>
            <EllipsisGlyph />
            <span className="ui-visually-hidden">Photo options</span>
          </>
        }
      >
        <button
          type="button"
          onClick={() => {
            setMenuOpen(false);
            setAlbumChoices(null);
            setAlbumSearch("");
            setAlbumPickerOpen(true);
          }}
        >
          <PlusGlyph />
          Add to album…
        </button>
        {photo.album_count > 0 && (
          <button
            type="button"
            onClick={() => {
              setMenuOpen(false);
              setRemovePickerOpen(true);
            }}
          >
            <XGlyph />
            Remove from album…
          </button>
        )}
        <button
          type="button"
          onClick={() => {
            setMenuOpen(false);
            setCollectionOpen(true);
          }}
        >
          <SparklesGlyph />
          Add to collection…
        </button>
        <button
          type="button"
          onClick={() => {
            setMenuOpen(false);
            setEditorOpen(true);
          }}
        >
          <SlidersGlyph />
          Edit photo
        </button>
        <hr aria-hidden="true" />
        <Link to={`${photoUrl}#edit-photo-title`}>
          <PencilGlyph />
          Edit details
        </Link>
        <Link
          to={`/families/${encodeURIComponent(familySlug)}/photos/review-people?${new URLSearchParams(
            {
              photo_id: photo.id,
              return_to: `${location.pathname}${location.search}`,
            },
          ).toString()}`}
        >
          <ScanFaceGlyph />
          Identify / Review people
        </Link>
        <button
          type="button"
          disabled={download.isPending}
          onClick={() => {
            setMenuOpen(false);
            download.mutate();
          }}
        >
          <DownloadGlyph />
          Download original
        </button>
        <button
          type="button"
          onClick={() => {
            const url = `${window.location.origin}${photoUrl}`;
            void navigator.clipboard.writeText(url).then(() => {
              setNotice({ title: "Fambam link copied" });
              setMenuOpen(false);
            });
          }}
        >
          <LinkGlyph />
          Copy Fambam link
        </button>
      </ContextMenu>

      <Dialog
        open={albumPickerOpen}
        title="Add to album"
        description="A Photo can belong to more than one Album. Existing memberships are already selected."
        className="photos-album-picker"
        pending={saveMemberships.isPending}
        onClose={() => {
          setAlbumPickerOpen(false);
        }}
      >
        <label className="photos-album-picker__search">
          <SearchGlyph />
          <span className="ui-visually-hidden">Search albums</span>
          <input
            data-autofocus
            value={albumSearch}
            placeholder="Search albums…"
            onChange={(event) => {
              setAlbumSearch(event.target.value);
            }}
          />
        </label>
        <div className="photos-album-picker__list">
          {(history.isPending || albums.isPending) && (
            <p role="status">Loading Album memberships…</p>
          )}
          {(history.isError || albums.isError) && (
            <p role="alert">Album memberships could not be loaded.</p>
          )}
          {filteredAlbumOptions.map((option) => {
            const lockedCurrentMembership =
              selectedAlbumChoices.includes(option.id) &&
              option.album?.permissions.can_manage !== true;
            return (
              <label key={option.id}>
                <input
                  type="checkbox"
                  checked={selectedAlbumChoices.includes(option.id)}
                  disabled={lockedCurrentMembership}
                  aria-label={`Add to ${option.name}`}
                  onChange={() => {
                    setAlbumChoices((current) => {
                      const choices = current ?? selectedAlbumChoices;
                      return choices.includes(option.id)
                        ? choices.filter((id) => id !== option.id)
                        : [...choices, option.id];
                    });
                  }}
                />
                <span>
                  <b>{option.name}</b>
                  <small>
                    {option.album === null
                      ? "Current membership"
                      : `${String(option.album.photo_count)} Photos`}
                  </small>
                </span>
              </label>
            );
          })}
          {albums.hasNextPage && (
            <Button
              type="button"
              variant="secondary"
              disabled={albums.isFetchingNextPage}
              onClick={() => {
                void albums.fetchNextPage();
              }}
            >
              {albums.isFetchingNextPage
                ? "Loading more albums…"
                : "Load more albums"}
            </Button>
          )}
        </div>
        <button
          type="button"
          className="photos-album-picker__create"
          onClick={() => {
            setAlbumPickerOpen(false);
            void navigate(
              `/families/${encodeURIComponent(familySlug)}/albums#create-album-title`,
            );
          }}
        >
          <PlusGlyph />
          Create new album
        </button>
        <p className="photos-album-picker__audit">
          <ShieldGlyph />
          Membership changes are recorded in audit history.
        </p>
        {saveMemberships.isError && (
          <p role="alert">Album memberships could not be updated.</p>
        )}
        <footer className="photos-dialog-footer">
          <Button
            onClick={() => {
              setAlbumPickerOpen(false);
            }}
          >
            Cancel
          </Button>
          <Button
            variant="primary"
            disabled={
              saveMemberships.isPending ||
              !history.isSuccess ||
              !albums.isSuccess
            }
            onClick={() => {
              saveMemberships.mutate(selectedAlbumChoices, {
                onSuccess: () => {
                  setAlbumPickerOpen(false);
                },
              });
            }}
          >
            Save album changes
          </Button>
        </footer>
      </Dialog>

      <Dialog
        open={removePickerOpen && removalTarget === null}
        title="Remove from album"
        description="Choose the Album to remove this Photo from."
        className="photos-remove-picker"
        onClose={() => {
          setRemovePickerOpen(false);
        }}
      >
        <div className="photos-remove-picker__list">
          {(history.isPending || albums.isPending) && (
            <p role="status">Loading Album memberships…</p>
          )}
          {(history.isError || albums.isError) && (
            <p role="alert">Album memberships could not be loaded.</p>
          )}
          {currentAlbums.map((album) => {
            const available = availableAlbums.find(
              (candidate) => candidate.id === album.id,
            );
            return (
              <button
                type="button"
                key={album.id}
                disabled={available?.permissions.can_manage !== true}
                onClick={() => {
                  setRemovePickerOpen(false);
                  setRemoveAlbum(album);
                }}
              >
                <ImageGlyph />
                <span>
                  <b>{album.name}</b>
                  <small>The Photo stays in Fambam</small>
                </span>
                <span aria-hidden="true">›</span>
              </button>
            );
          })}
          {history.isSuccess && currentAlbums.length === 0 && (
            <p>No manageable Album memberships are available.</p>
          )}
          {albums.hasNextPage && (
            <Button
              type="button"
              variant="secondary"
              disabled={albums.isFetchingNextPage}
              onClick={() => {
                void albums.fetchNextPage();
              }}
            >
              {albums.isFetchingNextPage
                ? "Loading more albums…"
                : "Load more albums"}
            </Button>
          )}
        </div>
        <footer className="photos-dialog-footer">
          <Button
            onClick={() => {
              setRemovePickerOpen(false);
            }}
          >
            Cancel
          </Button>
        </footer>
      </Dialog>

      <ConfirmDialog
        open={removalTarget !== null}
        title={`Remove this Photo from ${removalTarget?.name ?? "Album"}?`}
        confirmLabel="Remove from album"
        pending={removeMembership.isPending}
        onCancel={() => {
          setRemoveAlbum(null);
          setRemovePickerOpen(false);
        }}
        onConfirm={() => {
          if (removalTarget === null) return;
          removeMembership.mutate(removalTarget, {
            onSuccess: () => {
              setRemoveAlbum(null);
              setRemovePickerOpen(false);
            },
          });
        }}
      >
        <p>The Photo will remain in Fambam and in any other Albums.</p>
      </ConfirmDialog>

      <PhotoEditorDialog
        open={editorOpen}
        familySlug={familySlug}
        photoId={photo.id}
        mediaUploadId={photo.media_upload.id}
        title={photo.caption ?? photo.media_upload.client_filename}
        onClose={() => {
          setEditorOpen(false);
        }}
      />
      <CollectionPickerDialog
        open={collectionOpen}
        familySlug={familySlug}
        mode="photo"
        sourceName={photo.caption ?? photo.media_upload.client_filename}
        pending={addToCollection.isPending}
        onClose={() => {
          setCollectionOpen(false);
        }}
        onAdd={(collectionId) => {
          addToCollection.mutate(collectionId);
        }}
      />
      {notice !== null && (
        <ActionNotice
          {...notice}
          onDismiss={() => {
            setNotice(null);
          }}
        />
      )}
    </>
  );
}
