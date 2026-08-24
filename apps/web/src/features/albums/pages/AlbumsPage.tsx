import { useState, type SyntheticEvent } from "react";
import { Link, useParams } from "react-router";

import { useFamilySpaceQuery } from "@/features/family-spaces/hooks/useFamilySpaceQuery";

import {
  useAddAlbumPhotoMutation,
  useAlbumsQuery,
  useAlbumUploadMutation,
  useCreateAlbumMutation,
  useRemoveAlbumPhotoMutation,
} from "../hooks/useAlbumQueries";
import type { AlbumVisibility } from "../types/album";

export function AlbumsPage() {
  const { familySlug = "" } = useParams();
  const albums = useAlbumsQuery(familySlug);
  const family = useFamilySpaceQuery(familySlug);
  const create = useCreateAlbumMutation(familySlug);
  const addPhoto = useAddAlbumPhotoMutation(familySlug);
  const removePhoto = useRemoveAlbumPhotoMutation(familySlug);
  const uploadPhoto = useAlbumUploadMutation(familySlug);
  const [name, setName] = useState("");
  const [visibility, setVisibility] = useState<AlbumVisibility>("family_space");
  const [photoIds, setPhotoIds] = useState<Record<string, string>>({});
  const [files, setFiles] = useState<Record<string, File | undefined>>({});

  if (albums.isPending) return <p role="status">Loading albums…</p>;
  if (albums.isError) return <p role="alert">Albums could not be loaded.</p>;

  function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    create.mutate(
      { name: name.trim(), description: null, visibility },
      {
        onSuccess: () => {
          setName("");
        },
      },
    );
  }

  return (
    <main className="auth people" aria-labelledby="albums-title">
      <p className="eyebrow">Family archive</p>
      <h1 id="albums-title">Albums</h1>
      {albums.data.length === 0 && <p>No albums have been created yet.</p>}
      {albums.data.map((album) => (
        <section key={album.id} aria-labelledby={`album-${album.id}`}>
          <h2 id={`album-${album.id}`}>{album.name}</h2>
          <p>{album.visibility.replace("_", " ")}</p>
          <ul>
            {album.photos.map((photo) => (
              <li key={photo.id}>
                <Link
                  to={`/families/${encodeURIComponent(familySlug)}/photos/${photo.id}`}
                >
                  {photo.caption ?? photo.client_filename}
                </Link>{" "}
                {album.permissions.can_contribute && (
                  <button
                    type="button"
                    onClick={() => {
                      removePhoto.mutate({
                        albumId: album.id,
                        photoId: photo.id,
                      });
                    }}
                  >
                    Remove
                  </button>
                )}
              </li>
            ))}
          </ul>
          {album.permissions.can_contribute && (
            <>
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
                <label htmlFor={`photo-${album.id}`}>Photo ID</label>
                <input
                  id={`photo-${album.id}`}
                  value={photoIds[album.id] ?? ""}
                  onChange={(event) => {
                    setPhotoIds({
                      ...photoIds,
                      [album.id]: event.target.value,
                    });
                  }}
                />
                <p>
                  <small>
                    Adding a private Photo to this Album may widen who can see
                    it. Submitting confirms that change.
                  </small>
                </p>
                <button type="submit">Add Photo</button>
              </form>
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
                    setFiles({ ...files, [album.id]: event.target.files?.[0] });
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
      {(family.data === undefined || family.data.role !== "contributor") && (
        <section aria-labelledby="create-album-title">
          <h2 id="create-album-title">Create an Album</h2>
          <form onSubmit={submit}>
            <label htmlFor="album-name">Name</label>
            <input
              id="album-name"
              value={name}
              onChange={(event) => {
                setName(event.target.value);
              }}
              required
            />
            <label htmlFor="album-visibility">Audience</label>
            <select
              id="album-visibility"
              value={visibility}
              onChange={(event) => {
                setVisibility(event.target.value as AlbumVisibility);
              }}
            >
              <option value="family_space">Family Space</option>
              <option value="selected">Selected people</option>
              <option value="private">Private</option>
            </select>
            <button type="submit" disabled={create.isPending}>
              Create Album
            </button>
          </form>
        </section>
      )}
      <Link to={`/families/${encodeURIComponent(familySlug)}`}>
        Back to Family Space
      </Link>
    </main>
  );
}
