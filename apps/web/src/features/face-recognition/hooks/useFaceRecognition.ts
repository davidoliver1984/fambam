import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { toLaravelFieldErrors } from "@/api/errors";

import {
  approveFaceIdentityAssignment,
  getFaceClusters,
  getFaceIdentityAssignments,
  getFaceIdentitySuppressions,
  generateFaceIdentitySuggestions,
  mergeFaceClusters,
  nameFaceCluster,
  proposeFaceIdentity,
  rejectFaceIdentityAssignment,
  reopenFaceIdentitySuppression,
  splitFaceCluster,
} from "../api/faceRecognitionApi";
import { faceRecognitionKeys } from "../api/faceRecognitionKeys";

export function useFaceIdentityAssignmentsQuery(familySlug: string) {
  return useQuery({
    queryKey: faceRecognitionKeys.assignments(familySlug),
    queryFn: ({ signal }) => getFaceIdentityAssignments(familySlug, signal),
    enabled: familySlug !== "",
    retry: false,
  });
}

export function useFaceIdentitySuppressionsQuery(familySlug: string) {
  return useQuery({
    queryKey: faceRecognitionKeys.suppressions(familySlug),
    queryFn: ({ signal }) => getFaceIdentitySuppressions(familySlug, signal),
    enabled: familySlug !== "",
    retry: false,
  });
}

export function useFaceClustersQuery(familySlug: string) {
  return useQuery({
    queryKey: faceRecognitionKeys.clusters(familySlug),
    queryFn: ({ signal }) => getFaceClusters(familySlug, signal),
    enabled: familySlug !== "",
    retry: false,
  });
}

function useInvalidateFaceRecognition(familySlug: string) {
  const queryClient = useQueryClient();
  return () =>
    queryClient.invalidateQueries({
      queryKey: faceRecognitionKeys.all(familySlug),
    });
}

export function useProposeFaceIdentityMutation(familySlug: string) {
  const invalidate = useInvalidateFaceRecognition(familySlug);
  return useMutation({
    mutationFn: (input: { faceObservationId: string; personId: string }) =>
      proposeFaceIdentity(familySlug, input.faceObservationId, input.personId),
    onSuccess: invalidate,
  });
}

export function useGenerateFaceSuggestionsMutation(familySlug: string) {
  const invalidate = useInvalidateFaceRecognition(familySlug);
  const mutation = useMutation({
    mutationFn: (faceObservationId: string) =>
      generateFaceIdentitySuggestions(familySlug, faceObservationId),
    onSuccess: async () => {
      await invalidate();
    },
  });
  return {
    ...mutation,
    processingDisabledMessage:
      toLaravelFieldErrors(mutation.error).recognition_processing_disabled ??
      null,
  };
}

export function useApproveFaceIdentityMutation(familySlug: string) {
  const invalidate = useInvalidateFaceRecognition(familySlug);
  return useMutation({
    mutationFn: (assignmentId: string) =>
      approveFaceIdentityAssignment(familySlug, assignmentId),
    onSuccess: invalidate,
  });
}

export function useRejectFaceIdentityMutation(familySlug: string) {
  const invalidate = useInvalidateFaceRecognition(familySlug);
  return useMutation({
    mutationFn: (assignmentId: string) =>
      rejectFaceIdentityAssignment(familySlug, assignmentId),
    onSuccess: invalidate,
  });
}

export function useReopenFaceSuppressionMutation(familySlug: string) {
  const invalidate = useInvalidateFaceRecognition(familySlug);
  return useMutation({
    mutationFn: (suppressionId: string) =>
      reopenFaceIdentitySuppression(familySlug, suppressionId),
    onSuccess: invalidate,
  });
}

export function useNameFaceClusterMutation(familySlug: string) {
  const invalidate = useInvalidateFaceRecognition(familySlug);
  return useMutation({
    mutationFn: (input: {
      clusterId: string;
      personId: string;
      confirm: boolean;
    }) =>
      nameFaceCluster(
        familySlug,
        input.clusterId,
        input.personId,
        input.confirm,
      ),
    onSuccess: invalidate,
  });
}

export function useMergeFaceClustersMutation(familySlug: string) {
  const invalidate = useInvalidateFaceRecognition(familySlug);
  return useMutation({
    mutationFn: (clusterIds: string[]) =>
      mergeFaceClusters(familySlug, clusterIds),
    onSuccess: invalidate,
  });
}

export function useSplitFaceClusterMutation(familySlug: string) {
  const invalidate = useInvalidateFaceRecognition(familySlug);
  return useMutation({
    mutationFn: (input: { clusterId: string; groups: string[][] }) =>
      splitFaceCluster(familySlug, input.clusterId, input.groups),
    onSuccess: invalidate,
  });
}
