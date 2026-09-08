import {
  useInfiniteQuery,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";

import {
  createSavedSearch,
  deleteSavedSearch,
  getSavedSearches,
  runSavedSearch,
  updateSavedSearch,
} from "../api/searchApi";
import { searchKeys } from "../api/searchKeys";
import type { SavedSearchInput, SearchGroup } from "../types/search";

export function useSavedSearchesQuery(familySlug: string) {
  return useQuery({
    queryKey: searchKeys.saved(familySlug),
    queryFn: ({ signal }) => getSavedSearches(familySlug, signal),
    enabled: familySlug !== "",
    retry: false,
  });
}

export function useCreateSavedSearchMutation(familySlug: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: SavedSearchInput) =>
      createSavedSearch(familySlug, input),
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: searchKeys.saved(familySlug) }),
  });
}

export function useUpdateSavedSearchMutation(familySlug: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: SavedSearchInput }) =>
      updateSavedSearch(familySlug, id, input),
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: searchKeys.saved(familySlug) }),
  });
}

export function useDeleteSavedSearchMutation(familySlug: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => deleteSavedSearch(familySlug, id),
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: searchKeys.saved(familySlug) }),
  });
}

export function useSavedSearchResultsQuery<Group extends SearchGroup>(
  familySlug: string,
  id: string | null,
  group: Group,
  enabled = true,
) {
  return useInfiniteQuery({
    queryKey: searchKeys.savedResults(familySlug, id ?? "", group),
    queryFn: ({ pageParam, signal }) =>
      runSavedSearch(familySlug, id ?? "", group, pageParam, signal),
    initialPageParam: null as string | null,
    getNextPageParam: (lastPage) => lastPage.next_cursor ?? undefined,
    enabled: enabled && familySlug !== "" && id !== null,
    retry: false,
  });
}
