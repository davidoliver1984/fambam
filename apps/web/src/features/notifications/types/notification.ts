export type NotificationCategory =
  "comment" | "contribution" | "story" | "identity";
export type NotificationChannel = "in_app" | "email";
export interface FamilyNotification {
  id: string;
  category: NotificationCategory;
  photo_id: string | null;
  album_id: string | null;
  story_id: string | null;
  person_id: string | null;
  comment_id: string | null;
  read_at: string | null;
  created_at: string;
}
export interface NotificationPreference {
  category: NotificationCategory;
  channel: NotificationChannel;
  enabled: boolean;
}
