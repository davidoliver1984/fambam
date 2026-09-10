export type NotificationCategory =
  "comment" | "contribution" | "story" | "identity" | "export";
export type PreferenceNotificationCategory = Exclude<
  NotificationCategory,
  "export"
>;
export type NotificationChannel = "in_app" | "email";
export type FamilyNotification = {
  id: string;
  category: NotificationCategory;
  photo_id: string | null;
  album_id: string | null;
  story_id: string | null;
  person_id: string | null;
  comment_id: string | null;
  family_export_id: string | null;
  read_at: string | null;
  created_at: string;
};
export type NotificationPreference = {
  category: PreferenceNotificationCategory;
  channel: NotificationChannel;
  enabled: boolean;
};
