import { FaceObservationPreview } from "./FaceObservationPreview";

import type { FaceIdentitySuppression } from "../types/faceRecognition";

type Props = {
  suppressions: FaceIdentitySuppression[];
  canResolve: boolean;
  pending: boolean;
  onReopen: (suppressionId: string) => void;
};

export function FaceSuppressionsPanel({
  suppressions,
  canResolve,
  pending,
  onReopen,
}: Props) {
  return (
    <section aria-labelledby="face-suppressions-title">
      <h2 id="face-suppressions-title">Rejected suggestions</h2>
      {suppressions.length === 0 ? (
        <p>No active identity suppressions.</p>
      ) : (
        <ul>
          {suppressions.map((suppression) => (
            <li key={suppression.id}>
              <FaceObservationPreview observation={suppression.observation} />
              <p>{suppression.person.preferred_name} will not be suggested.</p>
              {canResolve && (
                <button
                  type="button"
                  disabled={pending}
                  onClick={() => {
                    onReopen(suppression.id);
                  }}
                >
                  Reopen suggestion
                </button>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
