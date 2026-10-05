import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";
import type {
  FamilyNotification,
  NotificationPreference,
  NotificationPresentationPreference,
} from "../types/notification";

const base = (slug: string) => `/api/families/${encodeURIComponent(slug)}`;
export async function getNotifications(slug: string, signal?: AbortSignal) {
  return unwrap(
    await apiClient.get<ApiEnvelope<FamilyNotification[]>>(
      `${base(slug)}/notifications`,
      { signal },
    ),
  );
}
export async function getNotificationPresentationPreferences(
  slug: string,
  signal?: AbortSignal,
): Promise<NotificationPresentationPreference[]> {
  const response = await apiClient.get<
    ApiEnvelope<NotificationPreference[]> & {
      presentation: NotificationPresentationPreference[];
    }
  >(`${base(slug)}/notification-preferences`, { signal });
  return response.data.presentation;
}

export async function updateNotificationPresentationPreferences(
  slug: string,
  preferences: Array<
    Pick<NotificationPresentationPreference, "key" | "channel" | "enabled">
  >,
): Promise<NotificationPresentationPreference[]> {
  await ensureCsrfCookie();
  const response = await apiClient.put<
    ApiEnvelope<NotificationPreference[]> & {
      presentation: NotificationPresentationPreference[];
    }
  >(`${base(slug)}/notification-preferences`, {
    presentation_preferences: preferences,
  });
  return response.data.presentation;
}
export async function markNotificationRead(slug: string, id: string) {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.patch<ApiEnvelope<{ id: string; read_at: string }>>(
      `${base(slug)}/notifications/${encodeURIComponent(id)}/read`,
    ),
  );
}
export async function getNotificationPreferences(
  slug: string,
  signal?: AbortSignal,
) {
  return unwrap(
    await apiClient.get<ApiEnvelope<NotificationPreference[]>>(
      `${base(slug)}/notification-preferences`,
      { signal },
    ),
  );
}
export async function updateNotificationPreferences(
  slug: string,
  preferences: NotificationPreference[],
) {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.put<ApiEnvelope<NotificationPreference[]>>(
      `${base(slug)}/notification-preferences`,
      { preferences },
    ),
  );
}
