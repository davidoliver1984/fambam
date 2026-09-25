import type { FamilyNotification } from "@/features/notifications/types/notification";

export function notificationTarget(
  familySlug: string,
  item: FamilyNotification,
) {
  const base = `/families/${encodeURIComponent(familySlug)}`;
  const photoPath = (id: string) => {
    const albumContext = item.album_id
      ? `?${new URLSearchParams({ albumId: item.album_id })}`
      : "";
    return `${base}/photos/${encodeURIComponent(id)}${albumContext}`;
  };
  const presentationTarget = item.presentation?.target;
  if (presentationTarget) {
    const id = encodeURIComponent(presentationTarget.id);
    switch (presentationTarget.type) {
      case "photo":
        return photoPath(presentationTarget.id);
      case "album":
        return `${base}/albums/${id}`;
      case "story":
        return `${base}/stories/${id}`;
      case "person":
        return `${base}/people/${id}`;
      case "event":
        return `${base}/events/${id}`;
      case "family_export":
        return `${base}/exports`;
    }
  }
  if (item.photo_id) return photoPath(item.photo_id);
  if (item.album_id) return `${base}/albums/${item.album_id}`;
  if (item.story_id) return `${base}/stories/${item.story_id}`;
  if (item.event_id) return `${base}/events/${item.event_id}`;
  if (item.person_id) return `${base}/people/${item.person_id}`;
  return base;
}
