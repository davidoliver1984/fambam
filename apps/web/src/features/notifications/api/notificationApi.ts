import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";
import type {
  FamilyNotification,
  NotificationPreference,
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
