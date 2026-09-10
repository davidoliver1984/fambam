import { Link } from "react-router";
import {
  useMarkNotificationRead,
  useNotificationPreferencesQuery,
  useNotificationsQuery,
  useUpdateNotificationPreferences,
} from "../hooks/useNotifications";
import type {
  FamilyNotification,
  NotificationPreference,
} from "../types/notification";

const labels = {
  comment: "Photo conversation",
  contribution: "New photographs",
  story: "New story",
  identity: "Identity confirmed",
} as const;
function target(slug: string, item: FamilyNotification) {
  if (item.photo_id)
    return `/families/${encodeURIComponent(slug)}/photos/${item.photo_id}`;
  if (item.album_id)
    return `/families/${encodeURIComponent(slug)}/albums/${item.album_id}`;
  if (item.person_id)
    return `/families/${encodeURIComponent(slug)}/people/${item.person_id}`;
  return `/families/${encodeURIComponent(slug)}`;
}
export function NotificationCentre({ familySlug }: { familySlug: string }) {
  const notifications = useNotificationsQuery(familySlug);
  const preferences = useNotificationPreferencesQuery(familySlug);
  const read = useMarkNotificationRead(familySlug);
  const update = useUpdateNotificationPreferences(familySlug);
  const toggle = (choice: NotificationPreference) => {
    if (!preferences.data) return;
    update.mutate(
      preferences.data.map((item) =>
        item.category === choice.category && item.channel === choice.channel
          ? { ...item, enabled: !item.enabled }
          : item,
      ),
    );
  };
  return (
    <section aria-labelledby="notification-title">
      <h2 id="notification-title">Notifications</h2>
      {notifications.isPending ? (
        <p role="status">Loading notifications…</p>
      ) : notifications.isError ? (
        <p role="alert">Notifications could not be loaded.</p>
      ) : notifications.data.length === 0 ? (
        <p>No notifications yet.</p>
      ) : (
        <ul>
          {notifications.data.map((item) => (
            <li key={item.id}>
              <Link
                to={target(familySlug, item)}
                onClick={() => {
                  if (!item.read_at) read.mutate(item.id);
                }}
              >
                {labels[item.category]}
              </Link>
              {item.read_at === null && " — new"}
            </li>
          ))}
        </ul>
      )}
      <details>
        <summary>Notification preferences</summary>
        {preferences.isPending ? (
          <p role="status">Loading preferences…</p>
        ) : preferences.isError ? (
          <p role="alert">Preferences could not be loaded.</p>
        ) : (
          <fieldset disabled={update.isPending}>
            {preferences.data.map((item) => (
              <label key={`${item.category}-${item.channel}`}>
                <input
                  type="checkbox"
                  checked={item.enabled}
                  onChange={() => {
                    toggle(item);
                  }}
                />
                {labels[item.category]} —{" "}
                {item.channel === "in_app" ? "in app" : "email"}
              </label>
            ))}
          </fieldset>
        )}
        {update.isError && <p role="alert">Preferences could not be saved.</p>}
      </details>
    </section>
  );
}
