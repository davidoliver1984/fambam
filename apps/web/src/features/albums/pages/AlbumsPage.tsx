import { useState } from "react";
import { Link, useParams } from "react-router";

import { useFamilySpaceQuery } from "@/features/family-spaces/hooks/useFamilySpaceQuery";
import { PhotoPresentationImage } from "@/features/photos/components/PhotoPresentationImage";
import { usePhotosQuery } from "@/features/photos/hooks/usePhotoQueries";

import { AlbumPhotoGrid } from "../components/AlbumPhotoGrid";
import {
  useAddAlbumPhotoMutation,
  useAlbumsQuery,
  useAlbumUploadMutation,
  useRemoveAlbumPhotoMutation,
} from "../hooks/useAlbumQueries";

export function AlbumsPage() {
  const { familySlug = "" } = useParams();
  const albums = useAlbumsQuery(familySlug);
  const family = useFamilySpaceQuery(familySlug);
  const canBrowsePhotos = ["owner", "administrator", "member"].includes(
    family.data?.role ?? "",
  );
  const canCreateAlbum = canBrowsePhotos;
  const availablePhotos = usePhotosQuery(familySlug, {}, canBrowsePhotos);
  const addPhoto = useAddAlbumPhotoMutation(familySlug);
  const removePhoto = useRemoveAlbumPhotoMutation(familySlug);
  const uploadPhoto = useAlbumUploadMutation(familySlug);
  const [photoIds, setPhotoIds] = useState<Record<string, string>>({});
  const [files, setFiles] = useState<Record<string, File | undefined>>({});

  if (albums.isPending) return <p role="status">Loading albums…</p>;
  if (albums.isError) return <p role="alert">Albums could not be loaded.</p>;

  return (
    <main className="journey-page" aria-labelledby="albums-title">
      <p className="eyebrow">Family archive</p>
      <div className="journey-heading">
        <div>
          <h1 id="albums-title">Albums</h1>
          <p>Photographs gathered into the moments they belong to.</p>
        </div>
        {canCreateAlbum && (
          <Link
            className="journey-action"
            to={`/families/${encodeURIComponent(familySlug)}/albums/new`}
          >
            Create album
          </Link>
        )}
      </div>
      {albums.data.length === 0 && <p>No albums have been created yet.</p>}
      <div className="journey-album-grid">
        {albums.data.map((album) => (
          <section key={album.id} aria-labelledby={`album-${album.id}`}>
            <h2 id={`album-${album.id}`}>
              <Link
                to={`/families/${encodeURIComponent(familySlug)}/albums/${album.id}`}
              >
                {album.name}
              </Link>
            </h2>
            {((album.cover !== null && album.cover !== undefined) ||
              album.photos.length > 0) && (
              <Link
                className="journey-album-cover"
                to={`/families/${encodeURIComponent(familySlug)}/albums/${album.id}`}
                aria-label={`Open ${album.name}`}
              >
                <PhotoPresentationImage
                  familySlug={familySlug}
                  photoId={album.cover?.photo_id ?? album.photos[0].id}
                  mediaUploadId={
                    album.cover?.media_upload_id ??
                    album.photos[0].media_upload_id
                  }
                  fallbackTransform="thumbnail"
                  alt=""
                />
              </Link>
            )}
            <p>{album.visibility.replace("_", " ")}</p>
            {album.photos.length === 0 ? (
              <p>No photographs have been shared yet.</p>
            ) : (
              <AlbumPhotoGrid
                familySlug={familySlug}
                albumId={album.id}
                eventId={album.event?.id}
                photos={album.photos}
                canRemove={album.permissions.can_contribute}
                onRemove={(photoId) => {
                  removePhoto.mutate({ albumId: album.id, photoId });
                }}
              />
            )}
            {album.permissions.can_contribute && (
              <>
                {canBrowsePhotos && (
                  <form
                    onSubmit={(event) => {
                      event.preventDefault();
                      const photoId = (photoIds[album.id] ?? "").trim();
                      if (photoId) {
                        addPhoto.mutate({
                          albumId: album.id,
                          photoId,
                          confirmed: true,
                        });
                      }
                    }}
                  >
                    <label htmlFor={`photo-${album.id}`}>
                      Choose a Photo already in the archive
                    </label>
                    <select
                      id={`photo-${album.id}`}
                      value={photoIds[album.id] ?? ""}
                      onChange={(event) => {
                        setPhotoIds({
                          ...photoIds,
                          [album.id]: event.target.value,
                        });
                      }}
                    >
                      <option value="">Select a photograph</option>
                      {availablePhotos.data
                        ?.filter(
                          (photo) =>
                            !album.photos.some(
                              (linked) => linked.id === photo.id,
                            ),
                        )
                        .map((photo) => (
                          <option key={photo.id} value={photo.id}>
                            {photo.caption ??
                              photo.media_upload.client_filename}
                          </option>
                        ))}
                    </select>
                    {availablePhotos.isPending && (
                      <p role="status">Loading available Photos…</p>
                    )}
                    {availablePhotos.isError && (
                      <p role="alert">Available Photos could not be loaded.</p>
                    )}
                    <p>
                      <small>
                        Adding a private Photo to this Album may widen who can
                        see it. Submitting confirms that change.
                      </small>
                    </p>
                    <button type="submit">Add Photo</button>
                  </form>
                )}
                <form
                  onSubmit={(event) => {
                    event.preventDefault();
                    const file = files[album.id];
                    if (file !== undefined)
                      uploadPhoto.mutate({ albumId: album.id, file });
                  }}
                >
                  <label htmlFor={`upload-${album.id}`}>
                    Upload a new Photo to this Album
                  </label>
                  <input
                    id={`upload-${album.id}`}
                    type="file"
                    accept="image/jpeg,image/png,image/heic,image/heif,image/webp,image/tiff"
                    onChange={(event) => {
                      setFiles({
                        ...files,
                        [album.id]: event.target.files?.[0],
                      });
                    }}
                    required
                  />
                  <button type="submit" disabled={uploadPhoto.isPending}>
                    Upload to Album
                  </button>
                </form>
              </>
            )}
          </section>
        ))}
      </div>
      <Link to={`/families/${encodeURIComponent(familySlug)}`}>
        Back to Family Space
      </Link>
    </main>
  );
}
