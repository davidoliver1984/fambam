import { Link } from "react-router";

import {
  PhotoTileMenu,
  PhotoTileStats,
} from "@/features/events/components/EventPhotoTile";
import { PhotoPresentationImage } from "@/features/photos/components/PhotoPresentationImage";

import type { Album, AlbumPhoto } from "../types/album";

type Props = {
  familySlug: string;
  album: Album;
  photos: AlbumPhoto[];
  availableAlbums: Album[];
  view: "grid" | "list";
  onCreateAlbum: () => void;
  onSetCover: (photoId: string) => void;
};

function photoPath(
  familySlug: string,
  albumId: string,
  photoId: string,
  eventId?: string | null,
): string {
  const search = new URLSearchParams({ albumId });
  if (eventId !== null && eventId !== undefined) search.set("eventId", eventId);
  return `/families/${encodeURIComponent(familySlug)}/photos/${encodeURIComponent(photoId)}?${search.toString()}`;
}

const exactDate = new Intl.DateTimeFormat("en-GB", {
  day: "numeric",
  month: "long",
  year: "numeric",
  timeZone: "UTC",
});

function photoDate(photo: AlbumPhoto) {
  const date = photo.historical_date;
  if (date?.value === null || date?.value === undefined)
    return "Date not recorded";
  const parsed = new Date(`${date.value}T00:00:00Z`);
  if (date.precision === "year") return String(parsed.getUTCFullYear());
  if (date.precision === "decade")
    return `${String(Math.floor(parsed.getUTCFullYear() / 10) * 10)}s`;
  if (date.precision === "month")
    return new Intl.DateTimeFormat("en-GB", {
      month: "long",
      year: "numeric",
      timeZone: "UTC",
    }).format(parsed);
  if (date.precision === "approximate")
    return `Around ${exactDate.format(parsed)}`;
  if (date.precision === "unknown") return "Date not recorded";
  return exactDate.format(parsed);
}

export function AlbumDetailPhotoGrid({
  familySlug,
  album,
  photos,
  availableAlbums,
  view,
  onCreateAlbum,
  onSetCover,
}: Props) {
  return (
    <div className={`album-neat-grid album-neat-grid--${view}`}>
      {photos.map((photo) => {
        const name = photo.caption ?? photo.client_filename;
        return (
          <article className="album-photo-item" key={photo.id}>
            <span className="album-photo-item__media">
              <Link
                className="album-photo-item__image-link"
                to={photoPath(familySlug, album.id, photo.id, album.event?.id)}
                aria-label={`Open ${name}`}
              >
                <PhotoPresentationImage
                  familySlug={familySlug}
                  photoId={photo.id}
                  mediaUploadId={photo.media_upload_id}
                  fallbackTransform="thumbnail"
                  alt=""
                  className="album-photo-thumbnail"
                />
              </Link>
              <PhotoTileStats
                familySlug={familySlug}
                photoId={photo.id}
                albumId={album.id}
                summary={
                  photo.conversation === undefined
                    ? undefined
                    : {
                        loveCount: photo.conversation.love_count,
                        commentCount: photo.conversation.comment_count,
                        viewerHasLoved: photo.conversation.viewer_has_loved,
                        canInteract: photo.conversation.can_interact,
                      }
                }
              />
            </span>
            <Link
              className="album-photo-item__link"
              to={photoPath(familySlug, album.id, photo.id, album.event?.id)}
            >
              <span className="album-photo-item__copy">
                <b>{name}</b>
                <small>{photoDate(photo)}</small>
              </span>
            </Link>
            <PhotoTileMenu
              familySlug={familySlug}
              photoId={photo.id}
              mediaUploadId={photo.media_upload_id}
              caption={photo.caption}
              album={{
                id: album.id,
                canManage: album.permissions.can_manage,
                name: album.name,
              }}
              availableAlbums={availableAlbums}
              onCreateAlbum={onCreateAlbum}
              onSetAlbumCover={
                album.permissions.can_manage
                  ? () => {
                      onSetCover(photo.id);
                    }
                  : undefined
              }
              allowDelete={false}
            />
          </article>
        );
      })}
    </div>
  );
}
