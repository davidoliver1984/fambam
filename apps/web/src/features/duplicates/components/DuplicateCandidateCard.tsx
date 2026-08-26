import { Link } from "react-router";

import type { DuplicateCandidate } from "../types/duplicate";

type Props = {
  candidate: DuplicateCandidate;
  familySlug: string;
  pending: boolean;
  onIgnore: () => void;
  onDismiss: () => void;
};

function sourceDescription(candidate: DuplicateCandidate): string {
  if (candidate.source === "exact")
    return "The preserved originals have the same SHA-256 checksum.";
  if (candidate.source === "member_flagged")
    return "A family member suggested that these may be the same photograph.";
  const version = candidate.processing_version?.toString() ?? "unknown";
  const score = candidate.score?.toString() ?? "unknown";
  return `The version ${version} visual comparison found a Hamming distance of ${score}.`;
}

export function DuplicateCandidateCard({
  candidate,
  familySlug,
  pending,
  onIgnore,
  onDismiss,
}: Props) {
  const photos = [candidate.photo, candidate.candidate_photo];

  return (
    <article
      className="duplicate-card"
      aria-labelledby={`duplicate-${candidate.id}`}
    >
      <h2 id={`duplicate-${candidate.id}`}>Possible duplicate</h2>
      <p>{sourceDescription(candidate)}</p>
      <div className="duplicate-comparison">
        {photos.map((photo) => (
          <figure key={photo.id}>
            <img
              src={photo.image_url}
              alt={photo.caption ?? photo.client_filename}
            />
            <figcaption>
              <Link
                to={`/families/${encodeURIComponent(familySlug)}/photos/${photo.id}`}
              >
                {photo.caption ?? photo.client_filename}
              </Link>
              <span>
                {photo.visibility === "private" ? "Private" : "Family Space"}
              </span>
            </figcaption>
          </figure>
        ))}
      </div>
      <div className="duplicate-actions">
        <button type="button" onClick={onIgnore} disabled={pending}>
          Leave for later
        </button>
        <button type="button" onClick={onDismiss} disabled={pending}>
          {pending ? "Saving…" : "Not a duplicate"}
        </button>
      </div>
    </article>
  );
}
