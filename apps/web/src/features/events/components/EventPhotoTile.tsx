import { useState } from "react";
import { Link } from "react-router";
import { useMutation, useQueryClient } from "@tanstack/react-query";

import { Button, ConfirmDialog, ContextMenu, Dialog } from "@/components/ui";
import {
  addPhotoToAlbum,
  removePhotoFromAlbum,
} from "@/features/albums/api/albumApi";
import { albumKeys } from "@/features/albums/api/albumKeys";
import type { Album } from "@/features/albums/types/album";
import { addCollectionPhoto } from "@/features/collections/api/collectionApi";
import { useCurrentUserQuery } from "@/features/account/hooks/useCurrentUserQuery";
import { getMediaVariantDelivery } from "@/features/media-uploads/api/mediaUploadApi";
import { deletePhoto } from "@/features/photos/api/photoApi";
import { PhotoEditorDialog } from "@/features/photos/components/PhotoEditorDialog";
import {
  usePhotoConversation,
  usePhotoConversationMutations,
} from "@/features/photos/hooks/usePhotoConversation";
import { usePhotoQuery } from "@/features/photos/hooks/usePhotoQueries";

import { CollectionPickerDialog } from "./CollectionPickerDialog";
import {
  CommentGlyph,
  DownloadGlyph,
  EllipsisGlyph,
  HeartGlyph,
  LinkGlyph,
  PencilGlyph,
  PlusGlyph,
  ScanFaceGlyph,
  SearchGlyph,
  ShieldGlyph,
  SlidersGlyph,
  SparklesGlyph,
  TrashGlyph,
  XGlyph,
} from "./EventGlyphs";

export function PhotoTileStats({
  familySlug,
  photoId,
  albumId,
}: {
  familySlug: string;
  photoId: string;
  albumId?: string;
}) {
  const conversation = usePhotoConversation(familySlug, photoId, albumId);
  const currentUser = useCurrentUserQuery();
  const mutations = usePhotoConversationMutations(familySlug, photoId, albumId);
  if (conversation.data === undefined || currentUser.data === undefined)
    return null;
  const loveCount = conversation.data.reactions.filter(
    (reaction) => reaction.reaction === "love",
  ).length;
  const lovedByMe = conversation.data.reactions.some(
    (reaction) =>
      reaction.user_id === currentUser.data.id && reaction.reaction === "love",
  );
  const commentCount = conversation.data.comments.length;
  return (
    <div className="event-photo-tile__stats">
      <button
        type="button"
        className={`event-photo-love${lovedByMe ? " loved" : ""}`}
        aria-label={`${lovedByMe ? "Remove love" : "Love"} · ${String(loveCount)}`}
        aria-pressed={lovedByMe}
        disabled={
          !conversation.data.permissions.can_interact ||
          mutations.react.isPending ||
          mutations.removeReaction.isPending
        }
        onClick={() => {
          if (lovedByMe) mutations.removeReaction.mutate();
          else mutations.react.mutate("love");
        }}
      >
        <HeartGlyph />
        {loveCount}
        <i className="love-sprite" aria-hidden="true">
          <b>♥</b>
          <b>♥</b>
          <b>♥</b>
          <b>♥</b>
        </i>
      </button>
      <Link
        className="event-photo-comments"
        to={`/families/${encodeURIComponent(familySlug)}/photos/${encodeURIComponent(photoId)}`}
        aria-label={`${String(commentCount)} ${commentCount === 1 ? "comment" : "comments"}`}
      >
        <CommentGlyph />
        {commentCount}
      </Link>
    </div>
  );
}

export function PhotoTileMenu({
  familySlug,
  photoId,
  mediaUploadId,
  caption,
  album,
  availableAlbums,
  onCreateAlbum,
}: {
  familySlug: string;
  photoId: string;
  mediaUploadId: string;
  caption: string | null;
  album?: { id: string; canManage: boolean; name: string };
  availableAlbums: Album[];
  onCreateAlbum: () => void;
}) {
  const client = useQueryClient();
  const [menuOpen, setMenuOpen] = useState(false);
  const [copied, setCopied] = useState(false);
  const [albumPickerOpen, setAlbumPickerOpen] = useState(false);
  const [albumSearch, setAlbumSearch] = useState("");
  const [albumChoices, setAlbumChoices] = useState<string[]>([]);
  const [removeOpen, setRemoveOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [editorOpen, setEditorOpen] = useState(false);
  const photo = usePhotoQuery(familySlug, photoId);
  const photoUrl = `/families/${encodeURIComponent(familySlug)}/photos/${photoId}`;
  const saveAlbumMemberships = useMutation({
    mutationFn: async (albumIds: string[]) => {
      const current = availableAlbums.filter((candidate) =>
        candidate.photos.some((photo) => photo.id === photoId),
      );
      const currentIds = new Set(current.map((candidate) => candidate.id));
      const nextIds = new Set(albumIds);
      await Promise.all([
        ...albumIds
          .filter((albumId) => !currentIds.has(albumId))
          .map((albumId) =>
            addPhotoToAlbum(familySlug, albumId, photoId, false),
          ),
        ...current
          .filter(
            (candidate) =>
              candidate.permissions.can_manage && !nextIds.has(candidate.id),
          )
          .map((candidate) =>
            removePhotoFromAlbum(familySlug, candidate.id, photoId),
          ),
      ]);
    },
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: albumKeys.all(familySlug) });
    },
  });
  const removeFromAlbum = useMutation({
    mutationFn: () =>
      removePhotoFromAlbum(familySlug, album?.id ?? "", photoId),
    onSuccess: () => {
      if (album)
        void client.invalidateQueries({
          queryKey: albumKeys.detail(familySlug, album.id),
        });
    },
  });
  const download = useMutation({
    mutationFn: () =>
      getMediaVariantDelivery(familySlug, mediaUploadId, "display"),
    onSuccess: (delivery) => {
      window.open(delivery.url, "_blank", "noopener");
    },
  });
  const remove = useMutation({
    mutationFn: () => deletePhoto(familySlug, photoId),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: albumKeys.all(familySlug) });
    },
  });
  const [collectionOpen, setCollectionOpen] = useState(false);
  const addToCollection = useMutation({
    mutationFn: (collectionId: string) =>
      addCollectionPhoto(familySlug, collectionId, photoId),
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
            <span className="sr-only">Photo options</span>
          </>
        }
      >
        <button
          type="button"
          onClick={() => {
            setMenuOpen(false);
            setAlbumChoices(
              availableAlbums
                .filter((candidate) =>
                  candidate.photos.some((photo) => photo.id === photoId),
                )
                .map((candidate) => candidate.id),
            );
            setAlbumSearch("");
            setAlbumPickerOpen(true);
          }}
        >
          <PlusGlyph />
          Add to album…
        </button>
        {album !== undefined && (
          <button
            type="button"
            disabled={removeFromAlbum.isPending}
            onClick={() => {
              setMenuOpen(false);
              setRemoveOpen(true);
            }}
          >
            <XGlyph />
            Remove from {album.name}
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
            setEditorOpen(true);
          }}
        >
          <SlidersGlyph />
          Edit photo
        </button>
        <Link to={`${photoUrl}#edit-photo-title`}>
          <PencilGlyph />
          Edit details
        </Link>
        <Link to={`${photoUrl}#photo-family-metadata-title`}>
          <ScanFaceGlyph />
          Identify / Review people
        </Link>
        <button
          type="button"
          disabled={download.isPending}
          onClick={() => {
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
              setCopied(true);
              setMenuOpen(false);
            });
          }}
        >
          <LinkGlyph />
          {copied ? "Link copied" : "Copy Fambam link"}
        </button>
        {photo.data?.permissions.can_update === true && (
          <button
            type="button"
            disabled={remove.isPending}
            onClick={() => {
              setMenuOpen(false);
              setDeleteOpen(true);
            }}
          >
            <TrashGlyph />
            Delete Photo
          </button>
        )}
      </ContextMenu>
      <Dialog
        open={albumPickerOpen}
        title="Add to album"
        description="A Photo can belong to more than one Album. Existing memberships are already selected."
        className="event-album-picker"
        pending={saveAlbumMemberships.isPending}
        onClose={() => {
          setAlbumPickerOpen(false);
        }}
      >
        <label className="event-album-picker__search">
          <SearchGlyph />
          <span className="sr-only">Search albums</span>
          <input
            data-autofocus
            value={albumSearch}
            placeholder="Search albums…"
            onChange={(event) => {
              setAlbumSearch(event.target.value);
            }}
          />
        </label>
        <div className="event-album-picker__list">
          {availableAlbums
            .filter((candidate) =>
              candidate.name
                .toLocaleLowerCase()
                .includes(albumSearch.toLocaleLowerCase()),
            )
            .map((candidate) => (
              <label
                key={candidate.id}
                className={
                  albumChoices.includes(candidate.id) ? "selected" : ""
                }
                aria-label={`Add to ${candidate.name}`}
              >
                <input
                  type="checkbox"
                  value={candidate.id}
                  checked={albumChoices.includes(candidate.id)}
                  onChange={() => {
                    setAlbumChoices((current) =>
                      current.includes(candidate.id)
                        ? current.filter((id) => id !== candidate.id)
                        : [...current, candidate.id],
                    );
                  }}
                />
                <span>
                  <b>{candidate.name}</b>
                  <small>{candidate.photos.length} Photos</small>
                </span>
              </label>
            ))}
        </div>
        <button
          type="button"
          className="event-album-picker__create"
          onClick={() => {
            setAlbumPickerOpen(false);
            onCreateAlbum();
          }}
        >
          <PlusGlyph />
          Create new album
        </button>
        <p className="event-album-picker__audit">
          <ShieldGlyph />
          Membership changes are recorded in audit history.
        </p>
        <footer className="event-dialog-footer">
          <Button
            type="button"
            variant="secondary"
            onClick={() => {
              setAlbumPickerOpen(false);
            }}
          >
            Cancel
          </Button>
          <Button
            type="button"
            variant="primary"
            disabled={saveAlbumMemberships.isPending}
            onClick={() => {
              saveAlbumMemberships.mutate(albumChoices, {
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
      <PhotoEditorDialog
        open={editorOpen}
        familySlug={familySlug}
        photoId={photoId}
        mediaUploadId={mediaUploadId}
        title={
          photo.data?.caption ??
          photo.data?.media_upload.client_filename ??
          caption ??
          "Photo"
        }
        onClose={() => {
          setEditorOpen(false);
        }}
      />
      <CollectionPickerDialog
        open={collectionOpen}
        familySlug={familySlug}
        mode="photo"
        sourceName={photo.data?.caption ?? "Photo"}
        pending={addToCollection.isPending}
        onClose={() => {
          setCollectionOpen(false);
        }}
        onAdd={(collectionId) => {
          addToCollection.mutate(collectionId);
        }}
      />
      <ConfirmDialog
        open={removeOpen}
        title={`Remove from ${album?.name ?? "album"}?`}
        confirmLabel="Remove Photo"
        pending={removeFromAlbum.isPending}
        onCancel={() => {
          setRemoveOpen(false);
        }}
        onConfirm={() => {
          removeFromAlbum.mutate(undefined, {
            onSuccess: () => {
              setRemoveOpen(false);
            },
          });
        }}
      >
        <p>The Photo stays in Fambam and in any other Albums.</p>
      </ConfirmDialog>
      <ConfirmDialog
        open={deleteOpen}
        title="Delete Photo?"
        confirmLabel="Delete Photo"
        destructive
        pending={remove.isPending}
        onCancel={() => {
          setDeleteOpen(false);
        }}
        onConfirm={() => {
          remove.mutate(undefined, {
            onSuccess: () => {
              setDeleteOpen(false);
            },
          });
        }}
      >
        <p>
          This removes the Photo from Fambam, every Album and every linked view.
          This cannot be undone.
        </p>
      </ConfirmDialog>
    </>
  );
}
