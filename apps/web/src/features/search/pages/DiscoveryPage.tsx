import { type ReactNode } from "react";
import { Link, useParams } from "react-router";

import { useDiscoveryQuery } from "../hooks/useArchiveSearchQuery";
import type { DiscoveryResponse } from "../types/search";

const sourceTypes = ["people", "photos", "albums", "events"] as const;

export function DiscoveryPage() {
  const { familySlug = "", type = "", id = "" } = useParams();
  const validType = sourceTypes.find((candidate) => candidate === type);

  if (!validType) {
    return <p role="alert">That discovery source is not supported.</p>;
  }

  return <DiscoveryResults familySlug={familySlug} type={validType} id={id} />;
}

function DiscoveryResults({
  familySlug,
  type,
  id,
}: {
  familySlug: string;
  type: DiscoveryResponse["source"]["type"];
  id: string;
}) {
  const discovery = useDiscoveryQuery(familySlug, type, id);
  const base = `/families/${encodeURIComponent(familySlug)}`;

  if (discovery.isPending) return <p role="status">Finding related items…</p>;
  if (discovery.isError) {
    return <p role="alert">Related items could not be loaded.</p>;
  }

  const related = discovery.data.related;

  return (
    <main className="auth people" aria-labelledby="discovery-title">
      <p className="eyebrow">Family archive</p>
      <h1 id="discovery-title">Related family memories</h1>
      <DiscoverySection title="People">
        {related.people?.map((person) => (
          <li key={person.id}>
            <Link to={`${base}/people/${person.id}`}>
              {person.preferred_name}
            </Link>
          </li>
        ))}
      </DiscoverySection>
      <DiscoverySection title="Photos">
        {related.photos?.map((photo) => (
          <li key={photo.id}>
            <Link to={`${base}/photos/${photo.id}`}>
              {photo.caption ?? "Untitled Photo"}
            </Link>
          </li>
        ))}
      </DiscoverySection>
      <DiscoverySection title="Albums">
        {related.albums?.map((album) => (
          <li key={album.id}>
            <Link to={`${base}/albums/${album.id}`}>{album.name}</Link>
          </li>
        ))}
      </DiscoverySection>
      <DiscoverySection title="Events">
        {related.events?.map((familyEvent) => (
          <li key={familyEvent.id}>
            <Link to={`${base}/events/${familyEvent.id}`}>
              {familyEvent.name}
            </Link>
          </li>
        ))}
      </DiscoverySection>
      <DiscoverySection title="Stories">
        {related.stories?.map((story) => (
          <li key={story.id}>
            <Link to={`${base}/photos/${story.photo_id}`}>
              {story.photo_caption ?? "Story on an untitled Photo"}
            </Link>
            <p>{story.excerpt}</p>
          </li>
        ))}
      </DiscoverySection>
      <Link to={`${base}/search`}>Back to Search</Link>
    </main>
  );
}

function DiscoverySection({
  title,
  children,
}: {
  title: string;
  children: ReactNode;
}) {
  const items = Array.isArray(children) ? children.filter(Boolean) : children;
  if (!items || (Array.isArray(items) && items.length === 0)) return null;

  return (
    <section>
      <h2>{title}</h2>
      <ul>{items}</ul>
    </section>
  );
}
