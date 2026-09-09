import { Link } from "react-router";

import type { FamilyActivity } from "@/features/activities/types/familyActivity";
import { useRecentFamilyActivitiesQuery } from "@/features/activities/hooks/useRecentFamilyActivitiesQuery";

type RecentFamilyActivityProps = {
  familySlug: string;
};

function activityText(activity: FamilyActivity): string {
  switch (activity.action_type) {
    case "photos_added_to_album":
      return `${activity.actor.name} added ${String(activity.photo_count)} ${activity.photo_count === 1 ? "photo" : "photos"} to ${activity.subject.label}`;
    case "story_added":
      return `${activity.actor.name} added a story`;
    case "person_identity_confirmed":
      return `${activity.actor.name} confirmed ${activity.subject.label} in a photo`;
    case "album_created":
      return `${activity.actor.name} created the album ${activity.subject.label}`;
    case "event_created":
      return `${activity.actor.name} created the event ${activity.subject.label}`;
  }
}

function activityPath(familySlug: string, activity: FamilyActivity): string {
  const family = `/families/${encodeURIComponent(familySlug)}`;
  switch (activity.subject.type) {
    case "album":
      return `${family}/albums/${encodeURIComponent(activity.subject.id)}`;
    case "event":
      return `${family}/events/${encodeURIComponent(activity.subject.id)}`;
    case "person":
      return `${family}/people/${encodeURIComponent(activity.subject.id)}`;
    case "story":
      return `${family}/photos/${encodeURIComponent(activity.subject.photo_id ?? "")}`;
  }
}

export function RecentFamilyActivity({
  familySlug,
}: RecentFamilyActivityProps) {
  const query = useRecentFamilyActivitiesQuery(familySlug);

  return (
    <section aria-labelledby="recent-family-activity-title">
      <h2 id="recent-family-activity-title">Recent family activity</h2>
      {query.isPending && <p role="status">Loading recent family activity…</p>}
      {query.isError && (
        <p role="alert">Recent family activity could not be loaded.</p>
      )}
      {query.data?.length === 0 && (
        <p>
          Your family’s new Albums, Events, Stories and photographs will appear
          here.
        </p>
      )}
      {query.data !== undefined && query.data.length > 0 && (
        <ol className="activity-list">
          {query.data.map((activity) => (
            <li key={activity.id}>
              <Link to={activityPath(familySlug, activity)}>
                {activityText(activity)}
              </Link>{" "}
              <time dateTime={activity.created_at}>
                {new Date(activity.created_at).toLocaleDateString()}
              </time>
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}
