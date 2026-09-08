import { FaceObservationPreview } from "./FaceObservationPreview";

import type { FaceIdentityAssignment } from "../types/faceRecognition";

type Props = {
  assignment: FaceIdentityAssignment;
  canResolve: boolean;
  pending: boolean;
  onApprove: () => void;
  onReject: () => void;
};

export function FaceSuggestionCard({
  assignment,
  canResolve,
  pending,
  onApprove,
  onReject,
}: Props) {
  return (
    <article aria-labelledby={`face-assignment-${assignment.id}`}>
      <h2 id={`face-assignment-${assignment.id}`}>
        Suggested identity: {assignment.person.preferred_name}
      </h2>
      <FaceObservationPreview observation={assignment.observation} />
      <p>Source: {assignment.proposal_source.replaceAll("_", " ")}</p>
      {canResolve ? (
        <div>
          <button type="button" disabled={pending} onClick={onApprove}>
            Confirm identity
          </button>
          <button type="button" disabled={pending} onClick={onReject}>
            Reject identity
          </button>
        </div>
      ) : (
        <p>An Owner or Administrator must resolve this suggestion.</p>
      )}
    </article>
  );
}
