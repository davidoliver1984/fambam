import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  getNotifications,
  getNotificationPreferences,
  markNotificationRead,
  updateNotificationPreferences,
} from "../api/notificationApi";
import type { NotificationPreference } from "../types/notification";
const keys = {
  all: (slug: string) => ["families", slug, "notifications"] as const,
  preferences: (slug: string) =>
    ["families", slug, "notification-preferences"] as const,
};
export const useNotificationsQuery = (slug: string) =>
  useQuery({
    queryKey: keys.all(slug),
    queryFn: ({ signal }) => getNotifications(slug, signal),
    enabled: slug !== "",
  });
export const useNotificationPreferencesQuery = (slug: string) =>
  useQuery({
    queryKey: keys.preferences(slug),
    queryFn: ({ signal }) => getNotificationPreferences(slug, signal),
    enabled: slug !== "",
  });
export function useMarkNotificationRead(slug: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => markNotificationRead(slug, id),
    onSuccess: () => client.invalidateQueries({ queryKey: keys.all(slug) }),
  });
}
export function useUpdateNotificationPreferences(slug: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (preferences: NotificationPreference[]) =>
      updateNotificationPreferences(slug, preferences),
    onSuccess: (data) => client.setQueryData(keys.preferences(slug), data),
  });
}
