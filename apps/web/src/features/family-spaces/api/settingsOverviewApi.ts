import { apiClient } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import {
  type SettingsOverview,
  settingsOverviewSchema,
} from "../types/settingsOverview";

export function parseSettingsOverview(value: unknown): SettingsOverview {
  return settingsOverviewSchema.parse(value);
}

export async function getSettingsOverview(
  familySlug: string,
  signal?: AbortSignal,
): Promise<SettingsOverview> {
  const value = unwrap(
    await apiClient.get<ApiEnvelope<unknown>>(
      `/api/families/${encodeURIComponent(familySlug)}/settings/overview`,
      { signal },
    ),
  );

  return parseSettingsOverview(value);
}
