import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  getNotifications,
  getNotificationPresentationPreferences,
  getNotificationPreferences,
  markNotificationRead,
  updateNotificationPresentationPreferences,
  updateNotificationPreferences,
} from "../api/notificationApi";
import type {
  NotificationPreference,
  NotificationPresentationPreference,
} from "../types/notification";
const keys = {
  all: (slug: string) => ["families", slug, "notifications"] as const,
  preferences: (slug: string) =>
    ["families", slug, "notification-preferences"] as const,
  presentation: (slug: string) =>
    ["families", slug, "notification-presentation-preferences"] as const,
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
export const useNotificationPresentationPreferencesQuery = (slug: string) =>
  useQuery({
    queryKey: keys.presentation(slug),
    queryFn: ({ signal }) =>
      getNotificationPresentationPreferences(slug, signal),
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

export function useUpdateNotificationPresentationPreferences(slug: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (
      preferences: Array<
        Pick<NotificationPresentationPreference, "key" | "channel" | "enabled">
      >,
    ) => updateNotificationPresentationPreferences(slug, preferences),
    onSuccess: (data) => client.setQueryData(keys.presentation(slug), data),
  });
}
