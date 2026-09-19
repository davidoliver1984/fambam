import { useState, type SyntheticEvent } from "react";
import { Link, useNavigate, useParams } from "react-router";

import { PhotoPresentationImage } from "@/features/photos/components/PhotoPresentationImage";
import { usePhotosQuery } from "@/features/photos/hooks/usePhotoQueries";

import {
  useCollectionMutations,
  useCollectionQuery,
} from "../hooks/useCollections";

export function CollectionPage() {
  const { familySlug = "", collectionId = "" } = useParams();
  const navigate = useNavigate();
  const collection = useCollectionQuery(familySlug, collectionId);
  const photos = usePhotosQuery(familySlug);
  const actions = useCollectionMutations(familySlug, collectionId);
  const [photoId, setPhotoId] = useState("");
  const base = `/families/${encodeURIComponent(familySlug)}`;
  if (collection.isPending) return <p role="status">Loading Collection…</p>;
  if (collection.isError)
    return <p role="alert">This Collection is unavailable.</p>;
  const included = new Set(
    (collection.data.photos ?? []).map((item) => item.id),
  );
  function add(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    actions.add.mutate(photoId, {
      onSuccess: () => {
        setPhotoId("");
      },
    });
  }
  return (
    <main
      className="journey-page journey-detail"
      aria-labelledby="collection-title"
    >
      <p className="eyebrow">Collection</p>
      <h1 id="collection-title">{collection.data.name}</h1>
      {collection.data.description && <p>{collection.data.description}</p>}
      {(collection.data.photos ?? []).length === 0 ? (
        <p>No photographs have been added.</p>
      ) : (
        <ul className="collection-photo-grid">
          {(collection.data.photos ?? []).map((photo) => (
            <li key={photo.id}>
              <Link to={`${base}/photos/${encodeURIComponent(photo.id)}`}>
                <PhotoPresentationImage
                  familySlug={familySlug}
                  photoId={photo.id}
                  mediaUploadId={photo.media_upload_id}
                  fallbackTransform="thumbnail"
                  alt=""
                />
                <strong>{photo.caption ?? "Untitled Photo"}</strong>
              </Link>
              <button
                type="button"
                onClick={() => {
                  actions.removePhoto.mutate(photo.id);
                }}
              >
                Remove
              </button>
            </li>
          ))}
        </ul>
      )}
      <form onSubmit={add}>
        <label htmlFor="collection-photo">Add a photograph</label>
        <select
          id="collection-photo"
          required
          value={photoId}
          onChange={(event) => {
            setPhotoId(event.target.value);
          }}
        >
          <option value="">Select…</option>
          {(photos.data ?? [])
            .filter((photo) => !included.has(photo.id))
            .map((photo) => (
              <option key={photo.id} value={photo.id}>
                {photo.caption ?? photo.media_upload.client_filename}
              </option>
            ))}
        </select>
        <button
          type="submit"
          disabled={actions.add.isPending || photoId === ""}
        >
          Add photograph
        </button>
      </form>
      <button
        type="button"
        disabled={actions.requestExport.isPending}
        onClick={() => {
          actions.requestExport.mutate();
        }}
      >
        Request Collection export
      </button>
      {actions.requestExport.isSuccess && (
        <p role="status">Export requested. Track it from Exports.</p>
      )}
      <button
        className="danger-action"
        type="button"
        onClick={() => {
          if (
            window.confirm(
              "Delete this private Collection? The photographs remain in the archive.",
            )
          )
            actions.removeCollection.mutate(undefined, {
              onSuccess: () => {
                void navigate(`${base}/collections`);
              },
            });
        }}
      >
        Delete Collection
      </button>
    </main>
  );
}
