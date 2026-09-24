import { Link } from "react-router";

import { useLove } from "../hooks/useLove";
import type { LoveTarget } from "../types/love";

function HeartIcon({ filled }: { filled: boolean }) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill={filled ? "currentColor" : "none"}
      stroke="currentColor"
      strokeWidth="1.8"
      className="love-button__heart"
    >
      <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78Z" />
    </svg>
  );
}

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
        className={`love-button${summary.loved_by_me ? " loved" : ""}`}
        aria-pressed={summary.loved_by_me}
        disabled={mutation.isPending}
        onClick={() => {
          mutation.mutate(summary.loved_by_me);
        }}
      >
        <HeartIcon filled={summary.loved_by_me} />
        <span className="love-control__label">
          {summary.loved_by_me ? "Loved" : "Love this"} ·
        </span>{" "}
        <span className="love-control__count">{summary.count}</span>
        <i className="love-sprite" aria-hidden="true">
          <b>♥</b>
          <b>♥</b>
          <b>♥</b>
          <b>♥</b>
        </i>
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
