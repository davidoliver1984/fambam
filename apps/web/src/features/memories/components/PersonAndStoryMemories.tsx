import { Link } from "react-router";

import { MediaVariantImage } from "@/features/media-uploads/components/MediaVariantImage";
import { useHomepageMemoriesQuery } from "@/features/memories/hooks/useHomepageMemoriesQuery";
import type { MemoryContext } from "@/features/memories/types/homepageMemory";

type Props = {
  familySlug: string;
};

function ContextList({
  label,
  items,
}: {
  label: string;
  items: MemoryContext[];
}) {
  if (items.length === 0) {
    return null;
  }

  return (
    <li>
      {label}: {items.map((item) => item.name).join(", ")}
    </li>
  );
}

export function PersonAndStoryMemories({ familySlug }: Props) {
  const query = useHomepageMemoriesQuery(familySlug);

  if (query.isPending) {
    return <p role="status">Finding recent people and Stories…</p>;
  }

  if (query.isError) {
    return <p role="alert">Recent people and Stories could not be loaded.</p>;
  }

  return (
    <>
      <section aria-labelledby="people-memories-title">
        <h2 id="people-memories-title">People with new memories</h2>
        {query.data.people.length === 0 ? (
          <p>No new person-centred memories in the recent period.</p>
        ) : (
          <ul className="memory-list">
            {query.data.people.map((person) => (
              <li key={person.person_id}>
                <strong>{person.preferred_name}</strong>
                <p>
                  {person.memory_count} new{" "}
                  {person.memory_count === 1 ? "memory" : "memories"}
                </p>
                <Link
                  to={`/families/${encodeURIComponent(familySlug)}/discover/people/${encodeURIComponent(person.person_id)}`}
                >
                  More from {person.preferred_name}
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section aria-labelledby="recent-stories-title">
        <h2 id="recent-stories-title">Recent Stories</h2>
        {query.data.stories.length === 0 ? (
          <p>No Stories have been added recently.</p>
        ) : (
          <ol className="memory-list">
            {query.data.stories.map((story) => (
              <li key={story.id}>
                <Link
                  to={`/families/${encodeURIComponent(familySlug)}/photos/${encodeURIComponent(story.photo_id)}`}
                >
                  <MediaVariantImage
                    familySlug={familySlug}
                    mediaUploadId={story.media_upload_id}
                    transform="thumbnail"
                    alt={story.photo_caption ?? "Family photograph"}
                  />
                  <strong>{story.photo_caption ?? "Untitled Photo"}</strong>
                </Link>
                <p>{story.excerpt}</p>
                <p>Story by {story.author.name}</p>
                <ul aria-label="Story context">
                  <ContextList label="People" items={story.people} />
                  <ContextList label="Albums" items={story.albums} />
                  <ContextList label="Events" items={story.events} />
                </ul>
              </li>
            ))}
          </ol>
        )}
      </section>
    </>
  );
}
