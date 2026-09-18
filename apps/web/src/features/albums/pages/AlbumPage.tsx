import { useState } from "react";
import { Link, useParams } from "react-router";
import { PhotoPresentationImage } from "@/features/photos/components/PhotoPresentationImage";

import { AlbumPhotoGrid } from "../components/AlbumPhotoGrid";
import {
  useAlbumQuery,
  useAlbumUploadMutation,
  useAlbumExportMutation,
  useAlbumCoverMutation,
} from "../hooks/useAlbumQueries";

export function AlbumPage() {
  const { familySlug = "", albumId = "" } = useParams();
  const album = useAlbumQuery(familySlug, albumId);
  const upload = useAlbumUploadMutation(familySlug);
  const exportAlbum = useAlbumExportMutation(familySlug, albumId);
  const cover = useAlbumCoverMutation(familySlug, albumId);
  const [file, setFile] = useState<File>();

  if (album.isPending) return <p role="status">Loading Album…</p>;
  if (album.isError) return <p role="alert">This Album is unavailable.</p>;

  return (
    <main className="journey-page journey-detail" aria-labelledby="album-title">
      <p className="eyebrow">Album</p>
      <h1 id="album-title">{album.data.name}</h1>
      {album.data.description !== null && <p>{album.data.description}</p>}
      {album.data.cover && (
        <figure className="journey-album-cover">
          <PhotoPresentationImage
            familySlug={familySlug}
            photoId={album.data.cover.photo_id}
            mediaUploadId={album.data.cover.media_upload_id}
            fallbackTransform="display"
            alt={`${album.data.name} cover`}
          />
        </figure>
      )}
      <p>
        {album.data.photos.length}{" "}
        {album.data.photos.length === 1 ? "photo" : "photos"}
        {album.data.starts_on ? ` · From ${album.data.starts_on}` : ""}
        {album.data.location ? ` · ${album.data.location}` : ""}
      </p>
      {album.data.cover_pending && (
        <p role="status">A new cover is being prepared.</p>
      )}
      {album.data.permissions.can_manage && album.data.photos.length > 0 && (
        <form
          onSubmit={(event) => {
            event.preventDefault();
            const selected = new FormData(event.currentTarget).get(
              "cover_photo_id",
            );
            cover.mutate({
              photoId:
                typeof selected === "string" && selected !== ""
                  ? selected
                  : null,
              confirmVisibilityWidening: false,
            });
          }}
        >
          <label htmlFor="album-cover-choice">Album cover</label>
          <select
            id="album-cover-choice"
            name="cover_photo_id"
            defaultValue={album.data.cover?.photo_id ?? ""}
          >
            <option value="">No cover</option>
            {album.data.photos.map((photo) => (
              <option key={photo.id} value={photo.id}>
                {photo.caption ?? photo.client_filename}
              </option>
            ))}
          </select>
          <p>
            <small>Choose from photographs already in this Album.</small>
          </p>
          <button type="submit" disabled={cover.isPending}>
            Save cover
          </button>
          {cover.isError && (
            <p role="alert">The Album cover could not be saved.</p>
          )}
          {cover.isSuccess && <p role="status">Album cover saved.</p>}
        </form>
      )}
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
      <button
        type="button"
        disabled={exportAlbum.isPending}
        onClick={() => {
          exportAlbum.mutate();
        }}
      >
        Export Album Photos
      </button>
      {exportAlbum.isError && (
        <p role="alert">The Album export could not be requested.</p>
      )}
      {exportAlbum.isSuccess && (
        <p role="status">
          Album export requested.{" "}
          <Link to={`/families/${encodeURIComponent(familySlug)}/exports`}>
            View export status
          </Link>
        </p>
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
            Add photographs to this Album
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
            {upload.isPending ? "Uploading…" : "Upload photograph"}
          </button>
          {upload.isError && (
            <p role="alert">The photograph could not be uploaded.</p>
          )}
          {upload.isSuccess && (
            <p role="status">Upload received. Processing may take a moment.</p>
          )}
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
