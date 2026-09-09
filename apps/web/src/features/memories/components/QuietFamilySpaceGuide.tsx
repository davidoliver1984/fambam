import { Link } from "react-router";

import { useRecentFamilyActivitiesQuery } from "@/features/activities/hooks/useRecentFamilyActivitiesQuery";
import { useDateMemoriesQuery } from "@/features/memories/hooks/useDateMemoriesQuery";
import { useHomepageMemoriesQuery } from "@/features/memories/hooks/useHomepageMemoriesQuery";

type Props = {
  familySlug: string;
  canExplorePeople: boolean;
  canExploreEvents: boolean;
  canExploreAlbums: boolean;
};

const LOW_ACTIVITY_THRESHOLD = 4;

export function QuietFamilySpaceGuide({
  familySlug,
  canExplorePeople,
  canExploreEvents,
  canExploreAlbums,
}: Props) {
  const activity = useRecentFamilyActivitiesQuery(familySlug);
  const dates = useDateMemoriesQuery(familySlug);
  const homepage = useHomepageMemoriesQuery(familySlug);
  const loading = activity.isPending || dates.isPending || homepage.isPending;
  const itemCount =
    (activity.data?.length ?? 0) +
    (dates.data?.length ?? 0) +
    (homepage.data?.people.length ?? 0) +
    (homepage.data?.stories.length ?? 0);

  if (loading || itemCount >= LOW_ACTIVITY_THRESHOLD) {
    return null;
  }

  const familyPath = `/families/${encodeURIComponent(familySlug)}`;

  return (
    <section aria-labelledby="explore-family-archive-title">
      <h2 id="explore-family-archive-title">Explore the family archive</h2>
      <p>
        Things are quiet at the moment, but the family archive is ready to
        explore.
      </p>
      <ul>
        <li>
          <Link to={`${familyPath}/photos`}>Browse photographs</Link>
        </li>
        {canExplorePeople && (
          <li>
            <Link to={`${familyPath}/people`}>Explore People</Link>
          </li>
        )}
        {canExploreAlbums && (
          <li>
            <Link to={`${familyPath}/albums`}>Explore Albums</Link>
          </li>
        )}
        {canExploreEvents && (
          <li>
            <Link to={`${familyPath}/events`}>Explore Events</Link>
          </li>
        )}
        {(homepage.data?.stories.length ?? 0) > 0 && (
          <li>
            <Link to={`${familyPath}#recent-stories-title`}>
              Read recent Stories
            </Link>
          </li>
        )}
      </ul>
    </section>
  );
}
