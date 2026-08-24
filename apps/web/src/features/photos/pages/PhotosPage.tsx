import { Link, useParams } from "react-router";

import { PhotoForm } from "../components/PhotoForm";
import { useCreatePhotoMutation } from "../hooks/usePhotoMutations";
import { usePhotosQuery } from "../hooks/usePhotoQueries";
import type { CreatePhotoInput } from "../types/photo";

export function PhotosPage() {
  const { familySlug = "" } = useParams();
  const photos = usePhotosQuery(familySlug);
  const createPhoto = useCreatePhotoMutation(familySlug);

  if (photos.isPending) return <p role="status">Loading photographs…</p>;
  if (photos.isError)
    return <p role="alert">The photograph archive could not be loaded.</p>;

  return (
    <main className="auth people" aria-labelledby="photos-title">
      <p className="eyebrow">Family archive</p>
      <h1 id="photos-title">Photographs</h1>
      {photos.data.length === 0 ? (
        <p>No Photo records have been created yet.</p>
      ) : (
        <ul className="photo-list">
          {photos.data.map((photo) => (
            <li key={photo.id}>
              <Link
                to={`/families/${encodeURIComponent(familySlug)}/photos/${photo.id}`}
              >
                {photo.caption ?? photo.media_upload.client_filename}
              </Link>
              <span>
                {photo.visibility === "private" ? "Private" : "Family Space"}
              </span>
              {photo.tags.length > 0 && (
                <small>{photo.tags.map((tag) => tag.label).join(", ")}</small>
              )}
            </li>
          ))}
        </ul>
      )}
      <section aria-labelledby="create-photo-title">
        <h2 id="create-photo-title">Create a Photo record</h2>
        <PhotoForm
          pending={createPhoto.isPending}
          onSubmit={(input) =>
            createPhoto.mutateAsync(input as CreatePhotoInput)
          }
        />
      </section>
      <p>
        <Link to={`/families/${encodeURIComponent(familySlug)}/uploads`}>
          Upload photographs
        </Link>
      </p>
      <Link to={`/families/${encodeURIComponent(familySlug)}`}>
        Back to Family Space
      </Link>
    </main>
  );
}
