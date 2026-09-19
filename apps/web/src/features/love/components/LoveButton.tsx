import { Link } from "react-router";

import { useLove } from "../hooks/useLove";
import type { LoveTarget } from "../types/love";

export function LoveButton({
  familySlug,
  targetType,
  targetId,
}: {
  familySlug: string;
  targetType: LoveTarget;
  targetId: string;
}) {
  const { query, mutation } = useLove(familySlug, targetType, targetId);
  if (query.isPending) return <p role="status">Loading family appreciation…</p>;
  if (query.isError)
    return <p role="alert">Family appreciation could not be loaded.</p>;
  const summary = query.data;
  return (
    <section className="love-control" aria-label="Family appreciation">
      <button
        type="button"
        aria-pressed={summary.loved_by_me}
        disabled={mutation.isPending}
        onClick={() => {
          mutation.mutate(summary.loved_by_me);
        }}
      >
        {summary.loved_by_me ? "Loved" : "Love this"} · {summary.count}
      </button>
      {summary.reactors.length > 0 && (
        <p>
          {summary.reactors.map((reactor, index) => (
            <span key={reactor.user_id}>
              {index > 0 ? ", " : "Loved by "}
              {reactor.person ? (
                <Link
                  to={`/families/${encodeURIComponent(familySlug)}/people/${encodeURIComponent(reactor.person.id)}`}
                >
                  {reactor.person.name}
                </Link>
              ) : (
                reactor.name
              )}
            </span>
          ))}
        </p>
      )}
      {mutation.isError && (
        <p role="alert">Your response could not be saved.</p>
      )}
    </section>
  );
}
