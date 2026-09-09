import { Link } from "react-router";

import { useDateMemoriesQuery } from "@/features/memories/hooks/useDateMemoriesQuery";

type DateMemoriesProps = {
  familySlug: string;
};

export function DateMemories({ familySlug }: DateMemoriesProps) {
  const query = useDateMemoriesQuery(familySlug);

  return (
    <section aria-labelledby="date-memories-title">
      <h2 id="date-memories-title">Memories from this time</h2>
      {query.isPending && <p role="status">Finding family memories…</p>}
      {query.isError && (
        <p role="alert">Date-based memories could not be loaded.</p>
      )}
      {query.data?.length === 0 && (
        <p>No photographs have a matching historical date yet.</p>
      )}
      {query.data !== undefined && query.data.length > 0 && (
        <ol className="memory-list">
          {query.data.map((memory) => (
            <li key={memory.photo_id}>
              <Link
                to={`/families/${encodeURIComponent(familySlug)}/photos/${encodeURIComponent(memory.photo_id)}`}
              >
                {memory.label}
              </Link>
              <p>{memory.reason}</p>
              {memory.added_at !== null && (
                <small>
                  Added to fambam{" "}
                  <time dateTime={memory.added_at}>
                    {new Date(memory.added_at).toLocaleDateString()}
                  </time>
                </small>
              )}
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}
