import { Link } from "react-router";

import { PhotoPresentationImage } from "@/features/photos/components/PhotoPresentationImage";

import type { PhotoSearchSummary } from "../types/search";

export function SearchPhotoCard({
  familySlug,
  photo,
  showDiscovery = false,
}: {
  familySlug: string;
  photo: PhotoSearchSummary;
  showDiscovery?: boolean;
}) {
  const base = `/families/${encodeURIComponent(familySlug)}`;

  return (
    <li className="search-photo-card">
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
      {photo.historical_date?.value && (
        <small>{photo.historical_date.value}</small>
      )}
      {photo.people.length > 0 && (
        <small>
          {photo.people.map((person) => person.preferred_name).join(", ")}
        </small>
      )}
      {showDiscovery && (
        <Link to={`${base}/discover/photos/${encodeURIComponent(photo.id)}`}>
          Explore related
        </Link>
      )}
    </li>
  );
}
