import { Link } from "react-router";

import { PhotoPresentationImage } from "@/features/photos/components/PhotoPresentationImage";

import type { AlbumPhoto } from "../types/album";

type Props = {
  familySlug: string;
  albumId: string;
  eventId?: string | null;
  photos: AlbumPhoto[];
  canRemove?: boolean;
  onRemove?: (photoId: string) => void;
};

function photoPath(
  familySlug: string,
  albumId: string,
  photoId: string,
  eventId?: string | null,
): string {
  const search = new URLSearchParams({ albumId });
  if (eventId !== null && eventId !== undefined) {
    search.set("eventId", eventId);
  }

  return `/families/${encodeURIComponent(familySlug)}/photos/${encodeURIComponent(photoId)}?${search.toString()}`;
}

export function AlbumPhotoGrid({
  familySlug,
  albumId,
  eventId,
  photos,
  canRemove = false,
  onRemove,
}: Props) {
  return (
    <ul className="album-photo-grid">
      {photos.map((photo) => (
        <li key={photo.id}>
          <Link to={photoPath(familySlug, albumId, photo.id, eventId)}>
            <PhotoPresentationImage
              familySlug={familySlug}
              photoId={photo.id}
              mediaUploadId={photo.media_upload_id}
              fallbackTransform="thumbnail"
              alt={photo.caption ?? photo.client_filename}
              className="album-photo-thumbnail"
            />
            <span>{photo.caption ?? photo.client_filename}</span>
          </Link>
          {canRemove && onRemove !== undefined ? (
            <button
              type="button"
              onClick={() => {
                onRemove(photo.id);
              }}
            >
              Remove
            </button>
          ) : null}
        </li>
      ))}
    </ul>
  );
}
