import { apiClient } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type { FamilyActivity } from "@/features/activities/types/familyActivity";

export async function getRecentFamilyActivities(
  familySlug: string,
  signal?: AbortSignal,
): Promise<FamilyActivity[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<FamilyActivity[]>>(
      `/api/families/${encodeURIComponent(familySlug)}/activities/recent`,
      { signal },
    ),
  );
}
