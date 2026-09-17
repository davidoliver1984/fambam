import { useState } from "react";
import { Link, useParams } from "react-router";

import { AlbumPhotoGrid } from "../components/AlbumPhotoGrid";
import {
  useAlbumQuery,
  useAlbumUploadMutation,
} from "../hooks/useAlbumQueries";

export function AlbumPage() {
  const { familySlug = "", albumId = "" } = useParams();
  const album = useAlbumQuery(familySlug, albumId);
  const upload = useAlbumUploadMutation(familySlug);
  const [file, setFile] = useState<File>();

  if (album.isPending) return <p role="status">Loading Event Album…</p>;
  if (album.isError)
    return <p role="alert">This Event Album is unavailable.</p>;

  return (
    <main className="auth people" aria-labelledby="album-title">
      <p className="eyebrow">Event Album</p>
      <h1 id="album-title">{album.data.name}</h1>
      {album.data.description !== null && <p>{album.data.description}</p>}
      {album.data.photos.length === 0 ? (
        <p>No photographs have been shared yet.</p>
      ) : (
        <AlbumPhotoGrid
          familySlug={familySlug}
          albumId={album.data.id}
          eventId={album.data.event?.id}
          photos={album.data.photos}
        />
      )}
      {album.data.permissions.can_contribute && (
        <form
          onSubmit={(event) => {
            event.preventDefault();
            if (file !== undefined)
              upload.mutate({ albumId: album.data.id, file });
          }}
        >
          <label htmlFor="event-photo-upload">
            Add photographs to this Event
          </label>
          <input
            id="event-photo-upload"
            type="file"
            accept="image/jpeg,image/png,image/heic,image/heif,image/webp,image/tiff"
            onChange={(event) => {
              setFile(event.target.files?.[0]);
            }}
            required
          />
          <button type="submit" disabled={upload.isPending}>
            Upload photograph
          </button>
        </form>
      )}
      {album.data.event !== null && album.data.event !== undefined && (
        <Link
          to={`/families/${encodeURIComponent(familySlug)}/events/${album.data.event.id}`}
        >
          Back to {album.data.event.name}
        </Link>
      )}
    </main>
  );
}
