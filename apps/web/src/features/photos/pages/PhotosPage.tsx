import { useState } from "react";
import { Link, useParams } from "react-router";

import { PhotoPresentationImage } from "../components/PhotoPresentationImage";
import { PhotoForm } from "../components/PhotoForm";
import { PhotoDuplicateHolds } from "../components/PhotoDuplicateHolds";
import {
  useCreatePhotoMutation,
  useRestorePhotoMutation,
} from "../hooks/usePhotoMutations";
import {
  useDeletedPhotosQuery,
  usePromotableMediaUploadsQuery,
  usePhotosQuery,
} from "../hooks/usePhotoQueries";
import type { CreatePhotoInput } from "../types/photo";

export function PhotosPage() {
  const { familySlug = "" } = useParams();
  const [filters, setFilters] = useState({
    tag: "",
    location: "",
    historical_year: "",
    without_confirmed_date: false,
  });
  const photos = usePhotosQuery(familySlug, filters);
  const createPhoto = useCreatePhotoMutation(familySlug);
  const promotableUploads = usePromotableMediaUploadsQuery(familySlug);
  const deleted = useDeletedPhotosQuery(familySlug);
  const restore = useRestorePhotoMutation(familySlug);

  if (photos.isPending) return <p role="status">Loading photographs…</p>;
  if (photos.isError)
    return <p role="alert">The photograph archive could not be loaded.</p>;

  return (
    <main className="journey-page" aria-labelledby="photos-title">
      <p className="eyebrow">Family archive</p>
      <div className="journey-heading">
        <div>
          <h1 id="photos-title">Photographs</h1>
          <p>{photos.data.length} photographs in this view.</p>
        </div>
        <Link
          className="journey-action"
          to={`/families/${encodeURIComponent(familySlug)}/uploads`}
        >
          Add photos
        </Link>
      </div>
      <section aria-labelledby="dynamic-view-title">
        <h2 id="dynamic-view-title">Filter this view</h2>
        <label htmlFor="photo-tag-filter">Tag</label>
        <input
          id="photo-tag-filter"
          value={filters.tag}
          onChange={(event) => {
            setFilters({ ...filters, tag: event.target.value });
          }}
        />
        <label htmlFor="photo-location-filter">Location</label>
        <input
          id="photo-location-filter"
          value={filters.location}
          onChange={(event) => {
            setFilters({ ...filters, location: event.target.value });
          }}
        />
        <label htmlFor="photo-year-filter">Historical year</label>
        <input
          id="photo-year-filter"
          inputMode="numeric"
          value={filters.historical_year}
          onChange={(event) => {
            setFilters({ ...filters, historical_year: event.target.value });
          }}
        />
        <label>
          <input
            type="checkbox"
            checked={filters.without_confirmed_date}
            onChange={(event) => {
              setFilters({
                ...filters,
                without_confirmed_date: event.target.checked,
              });
            }}
          />
          Without a confirmed date
        </label>
      </section>
      {photos.data.length === 0 ? (
        <p>No Photo records have been created yet.</p>
      ) : (
        <ul className="journey-photo-grid">
          {photos.data.map((photo) => (
            <li key={photo.id}>
              <Link
                to={`/families/${encodeURIComponent(familySlug)}/photos/${photo.id}`}
              >
                <PhotoPresentationImage
                  familySlug={familySlug}
                  photoId={photo.id}
                  mediaUploadId={photo.media_upload.id}
                  fallbackTransform="thumbnail"
                  alt={photo.caption ?? photo.media_upload.client_filename}
                />
                <strong>
                  {photo.caption ?? photo.media_upload.client_filename}
                </strong>
              </Link>
              <span className="journey-photo-visibility">
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
        {promotableUploads.isPending && (
          <p role="status">Loading ready uploads…</p>
        )}
        {promotableUploads.isError && (
          <p role="alert">Ready uploads could not be loaded.</p>
        )}
        {promotableUploads.data !== undefined && (
          <>
            {promotableUploads.data.length === 0 && (
              <p>There are no ready uploads available to promote.</p>
            )}
            <PhotoForm
              promotableUploads={promotableUploads.data}
              pending={createPhoto.isPending}
              onSubmit={(input) =>
                createPhoto.mutateAsync(input as CreatePhotoInput)
              }
            />
          </>
        )}
      </section>
      <PhotoDuplicateHolds familySlug={familySlug} />
      {deleted.data !== undefined && deleted.data.length > 0 && (
        <section aria-labelledby="deleted-photos-title">
          <h2 id="deleted-photos-title">Recently removed Photos</h2>
          <ul>
            {deleted.data.map((photo) => (
              <li key={photo.id}>
                {photo.caption ?? photo.client_filename}{" "}
                {photo.permissions.can_restore && (
                  <button
                    type="button"
                    onClick={() => {
                      restore.mutate(photo.id);
                    }}
                  >
                    Restore
                  </button>
                )}
              </li>
            ))}
          </ul>
        </section>
      )}
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
