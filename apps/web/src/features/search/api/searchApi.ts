import { apiClient } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type { SearchCriteria, SearchGroup, SearchPage } from "../types/search";

type SearchResponse<Group extends SearchGroup> = {
  [Key in Group]: SearchPage<Key>;
};

export async function searchArchive<Group extends SearchGroup>(
  familySlug: string,
  group: Group,
  criteria: SearchCriteria,
  cursor: string | null,
  signal?: AbortSignal,
): Promise<SearchPage<Group>> {
  const params = {
    ...criteria,
    group,
    limit: 12,
    ...(cursor === null ? {} : { cursor }),
  };
  const result = unwrap(
    await apiClient.get<ApiEnvelope<SearchResponse<Group>>>(
      `/api/families/${encodeURIComponent(familySlug)}/search`,
      { params, signal },
    ),
  );

  return result[group];
}
