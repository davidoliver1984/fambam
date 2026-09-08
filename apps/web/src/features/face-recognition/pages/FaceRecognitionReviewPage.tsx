import { Link, useParams } from "react-router";

import { useFamilySpaceQuery } from "@/features/family-spaces/hooks/useFamilySpaceQuery";

import { FaceSuggestionCard } from "../components/FaceSuggestionCard";
import { FaceSuppressionsPanel } from "../components/FaceSuppressionsPanel";
import {
  useApproveFaceIdentityMutation,
  useFaceIdentityAssignmentsQuery,
  useFaceIdentitySuppressionsQuery,
  useRejectFaceIdentityMutation,
  useReopenFaceSuppressionMutation,
} from "../hooks/useFaceRecognition";

export function FaceRecognitionReviewPage() {
  const { familySlug = "" } = useParams();
  const family = useFamilySpaceQuery(familySlug);
  const assignments = useFaceIdentityAssignmentsQuery(familySlug);
  const suppressions = useFaceIdentitySuppressionsQuery(familySlug);
  const approve = useApproveFaceIdentityMutation(familySlug);
  const reject = useRejectFaceIdentityMutation(familySlug);
  const reopen = useReopenFaceSuppressionMutation(familySlug);

  if (family.isPending || assignments.isPending || suppressions.isPending) {
    return <p role="status">Loading face identity review…</p>;
  }
  if (family.isError || assignments.isError || suppressions.isError) {
    return (
      <p role="alert">
        Face identity review is unavailable or you are not allowed to open it.
      </p>
    );
  }

  const canResolve =
    family.data.role === "owner" || family.data.role === "administrator";
  const actionFailed = approve.isError || reject.isError || reopen.isError;

  return (
    <main className="auth people" aria-labelledby="face-review-title">
      <p className="eyebrow">Family archive</p>
      <h1 id="face-review-title">Face identity review</h1>
      <p>
        Suggestions are not identity facts until an Owner or Administrator
        confirms them.
      </p>
      {actionFailed && (
        <p role="alert">The identity review action could not be completed.</p>
      )}
      <section aria-labelledby="pending-face-suggestions-title">
        <h2 id="pending-face-suggestions-title">Pending suggestions</h2>
        {assignments.data.length === 0 ? (
          <p>No face identity suggestions are waiting for review.</p>
        ) : (
          assignments.data.map((assignment) => (
            <FaceSuggestionCard
              key={assignment.id}
              assignment={assignment}
              canResolve={canResolve}
              pending={approve.isPending || reject.isPending}
              onApprove={() => {
                approve.mutate(assignment.id);
              }}
              onReject={() => {
                reject.mutate(assignment.id);
              }}
            />
          ))
        )}
      </section>
      <FaceSuppressionsPanel
        suppressions={suppressions.data}
        canResolve={canResolve}
        pending={reopen.isPending}
        onReopen={(suppressionId) => {
          reopen.mutate(suppressionId);
        }}
      />
      <Link to={`/families/${encodeURIComponent(familySlug)}/face-clusters`}>
        Review unknown face groups
      </Link>
    </main>
  );
}
