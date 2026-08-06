import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  addPersonToCircle,
  createFamilyCircle,
  deleteFamilyCircle,
  getFamilyCircles,
  removePersonFromCircle,
  updateFamilyCircle,
} from "../api/circleApi";
import { personKeys } from "../api/personKeys";
import type { FamilyCircleInput } from "../types/person";

export function useFamilyCirclesQuery(familySlug: string) {
  return useQuery({
    queryKey: personKeys.circles(familySlug),
    queryFn: ({ signal }) => getFamilyCircles(familySlug, signal),
    enabled: familySlug !== "",
  });
}

function useInvalidateCircles(familySlug: string) {
  const queryClient = useQueryClient();
  return async () =>
    queryClient.invalidateQueries({ queryKey: personKeys.circles(familySlug) });
}

export function useCreateFamilyCircleMutation(familySlug: string) {
  const invalidate = useInvalidateCircles(familySlug);
  return useMutation({
    mutationFn: (input: FamilyCircleInput) =>
      createFamilyCircle(familySlug, input),
    onSuccess: invalidate,
  });
}

export function useUpdateFamilyCircleMutation(familySlug: string) {
  const invalidate = useInvalidateCircles(familySlug);
  return useMutation({
    mutationFn: ({
      circleId,
      input,
    }: {
      circleId: string;
      input: FamilyCircleInput;
    }) => updateFamilyCircle(familySlug, circleId, input),
    onSuccess: invalidate,
  });
}

export function useDeleteFamilyCircleMutation(familySlug: string) {
  const invalidate = useInvalidateCircles(familySlug);
  return useMutation({
    mutationFn: (circleId: string) => deleteFamilyCircle(familySlug, circleId),
    onSuccess: invalidate,
  });
}

export function useAddCirclePersonMutation(familySlug: string) {
  const invalidate = useInvalidateCircles(familySlug);
  return useMutation({
    mutationFn: ({
      circleId,
      personId,
    }: {
      circleId: string;
      personId: string;
    }) => addPersonToCircle(familySlug, circleId, personId),
    onSuccess: invalidate,
  });
}

export function useRemoveCirclePersonMutation(familySlug: string) {
  const invalidate = useInvalidateCircles(familySlug);
  return useMutation({
    mutationFn: ({
      circleId,
      personId,
    }: {
      circleId: string;
      personId: string;
    }) => removePersonFromCircle(familySlug, circleId, personId),
    onSuccess: invalidate,
  });
}
