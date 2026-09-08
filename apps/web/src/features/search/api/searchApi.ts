import { apiClient } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type {
  DiscoveryResponse,
  SearchCriteria,
  SearchGroup,
  SearchPage,
  SearchSuggestion,
  SearchSuggestionType,
} from "../types/search";

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

export async function getSearchSuggestions(
  familySlug: string,
  type: SearchSuggestionType,
  prefix: string,
  signal?: AbortSignal,
): Promise<SearchSuggestion[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<SearchSuggestion[]>>(
      `/api/families/${encodeURIComponent(familySlug)}/search/suggestions`,
      { params: { type, prefix }, signal },
    ),
  );
}

export async function getDiscovery(
  familySlug: string,
  type: DiscoveryResponse["source"]["type"],
  id: string,
  signal?: AbortSignal,
): Promise<DiscoveryResponse> {
  return unwrap(
    await apiClient.get<ApiEnvelope<DiscoveryResponse>>(
      `/api/families/${encodeURIComponent(familySlug)}/discover/${type}/${encodeURIComponent(id)}`,
      { signal },
    ),
  );
}
