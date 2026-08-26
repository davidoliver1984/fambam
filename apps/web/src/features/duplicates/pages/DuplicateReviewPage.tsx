import { useState } from "react";
import { Link, useParams } from "react-router";

import { DuplicateCandidateCard } from "../components/DuplicateCandidateCard";
import {
  useDismissDuplicateCandidateMutation,
  useDuplicateCandidatesQuery,
  useDuplicateDecisionsQuery,
  useReopenDuplicateDecisionMutation,
} from "../hooks/useDuplicateReview";

export function DuplicateReviewPage() {
  const { familySlug = "" } = useParams();
  const candidates = useDuplicateCandidatesQuery(familySlug);
  const decisions = useDuplicateDecisionsQuery(familySlug);
  const dismiss = useDismissDuplicateCandidateMutation(familySlug);
  const reopen = useReopenDuplicateDecisionMutation(familySlug);
  const [ignored, setIgnored] = useState<string[]>([]);

  if (candidates.isPending || decisions.isPending) {
    return <p role="status">Loading duplicate review…</p>;
  }
  if (candidates.isError || decisions.isError) {
    return (
      <p role="alert">
        The duplicate review is unavailable or you are not allowed to open it.
      </p>
    );
  }

  const visible = candidates.data.filter(
    (candidate) => !ignored.includes(candidate.id),
  );

  return (
    <main className="auth people" aria-labelledby="duplicate-review-title">
      <p className="eyebrow">Family archive</p>
      <h1 id="duplicate-review-title">Duplicate review</h1>
      <p>
        Suggestions are advisory. This page never merges, deletes or
        consolidates Photos.
      </p>
      {dismiss.isError && (
        <p role="alert">The decision could not be saved. Try again.</p>
      )}
      {visible.length === 0 ? (
        <p>No unresolved duplicate suggestions are waiting for review.</p>
      ) : (
        visible.map((candidate) => (
          <DuplicateCandidateCard
            key={candidate.id}
            candidate={candidate}
            familySlug={familySlug}
            pending={dismiss.isPending}
            onIgnore={() => {
              setIgnored([...ignored, candidate.id]);
            }}
            onDismiss={() => {
              dismiss.mutate(candidate.id);
            }}
          />
        ))
      )}

      <section aria-labelledby="settled-duplicates-title">
        <h2 id="settled-duplicates-title">Settled duplicate decisions</h2>
        <p>
          Reopening makes a pair eligible for a future detection run; it does
          not change either Photo.
        </p>
        {reopen.isError && (
          <p role="alert">The decision could not be reopened.</p>
        )}
        {decisions.data.length === 0 ? (
          <p>No settled duplicate decisions.</p>
        ) : (
          <ul>
            {decisions.data.map((decision) => (
              <li key={decision.id}>
                {decision.photo.caption ?? decision.photo.client_filename} and{" "}
                {decision.candidate_photo.caption ??
                  decision.candidate_photo.client_filename}{" "}
                <button
                  type="button"
                  disabled={reopen.isPending}
                  onClick={() => {
                    reopen.mutate(decision.id);
                  }}
                >
                  Reopen decision
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>
      <Link to={`/families/${encodeURIComponent(familySlug)}/photos`}>
        Back to photographs
      </Link>
    </main>
  );
}
