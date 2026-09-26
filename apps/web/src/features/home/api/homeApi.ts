import { apiClient } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";
import type { HomeReadModel } from "@/features/home/types/home";

export async function getHomeReadModel(
  familySlug: string,
  signal?: AbortSignal,
): Promise<HomeReadModel> {
  return unwrap(
    await apiClient.get<ApiEnvelope<HomeReadModel>>(
      `/api/families/${encodeURIComponent(familySlug)}/home`,
      { signal },
    ),
  );
}
