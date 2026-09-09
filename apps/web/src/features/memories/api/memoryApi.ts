import { apiClient } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type { DateMemory } from "@/features/memories/types/dateMemory";
import type { HomepageMemories } from "@/features/memories/types/homepageMemory";

export async function getDateMemories(
  familySlug: string,
  signal?: AbortSignal,
): Promise<DateMemory[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<DateMemory[]>>(
      `/api/families/${encodeURIComponent(familySlug)}/memories/date-based`,
      { signal },
    ),
  );
}

export async function getHomepageMemories(
  familySlug: string,
  signal?: AbortSignal,
): Promise<HomepageMemories> {
  return unwrap(
    await apiClient.get<ApiEnvelope<HomepageMemories>>(
      `/api/families/${encodeURIComponent(familySlug)}/memories/homepage`,
      { signal },
    ),
  );
}
