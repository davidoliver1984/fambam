import { apiClient } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type { DateMemory } from "@/features/memories/types/dateMemory";

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
