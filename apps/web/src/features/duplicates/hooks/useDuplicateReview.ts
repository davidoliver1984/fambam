import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  dismissDuplicateCandidate,
  flagPhotoDuplicate,
  getDuplicateCandidates,
  getDuplicateDecisions,
  reopenDuplicateDecision,
} from "../api/duplicateApi";
import { duplicateKeys } from "../api/duplicateKeys";

export function useDuplicateCandidatesQuery(familySlug: string) {
  return useQuery({
    queryKey: duplicateKeys.candidates(familySlug),
    queryFn: ({ signal }) => getDuplicateCandidates(familySlug, signal),
    enabled: familySlug !== "",
    retry: false,
  });
}

export function useDuplicateDecisionsQuery(familySlug: string) {
  return useQuery({
    queryKey: duplicateKeys.decisions(familySlug),
    queryFn: ({ signal }) => getDuplicateDecisions(familySlug, signal),
    enabled: familySlug !== "",
    retry: false,
  });
}

export function useDismissDuplicateCandidateMutation(familySlug: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (candidateId: string) =>
      dismissDuplicateCandidate(familySlug, candidateId),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: duplicateKeys.candidates(familySlug),
        }),
        queryClient.invalidateQueries({
          queryKey: duplicateKeys.decisions(familySlug),
        }),
      ]);
    },
  });
}

export function useReopenDuplicateDecisionMutation(familySlug: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (decisionId: string) =>
      reopenDuplicateDecision(familySlug, decisionId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: duplicateKeys.decisions(familySlug),
      });
    },
  });
}

export function useFlagPhotoDuplicateMutation(
  familySlug: string,
  photoId: string,
) {
  return useMutation({
    mutationFn: (candidatePhotoId: string) =>
      flagPhotoDuplicate(familySlug, photoId, candidatePhotoId),
  });
}
