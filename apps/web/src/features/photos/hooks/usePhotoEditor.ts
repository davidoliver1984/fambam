import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  activatePhotoVersion,
  applyPhotoEditPreview,
  createPhotoEditPreview,
  createRestorePreview,
  discardPhotoEditPreview,
  getPhotoEditPreviewDelivery,
  getPhotoVersionDelivery,
  getPhotoVersions,
} from "../api/photoEditorApi";
import { photoEditorKeys } from "../api/photoEditorKeys";
import { photoKeys } from "../api/photoKeys";
import type { EditRecipe } from "../types/photoEditor";

export function usePhotoVersionsQuery(familySlug: string, photoId: string) {
  return useQuery({
    queryKey: photoEditorKeys.versions(familySlug, photoId),
    queryFn: ({ signal }) => getPhotoVersions(familySlug, photoId, signal),
    enabled: familySlug !== "" && photoId !== "",
    retry: false,
  });
}

export function usePhotoVersionDeliveryQuery(
  familySlug: string,
  photoId: string,
  versionId: string | null,
) {
  return useQuery({
    queryKey: photoEditorKeys.versionDelivery(
      familySlug,
      photoId,
      versionId ?? "",
    ),
    queryFn: ({ signal }) =>
      getPhotoVersionDelivery(familySlug, photoId, versionId ?? "", signal),
    enabled: familySlug !== "" && photoId !== "" && versionId !== null,
    retry: false,
    staleTime: 0,
  });
}

export function usePhotoEditPreviewDeliveryQuery(
  familySlug: string,
  photoId: string,
  previewId: string | null,
) {
  return useQuery({
    queryKey: photoEditorKeys.previewDelivery(
      familySlug,
      photoId,
      previewId ?? "",
    ),
    queryFn: ({ signal }) =>
      getPhotoEditPreviewDelivery(familySlug, photoId, previewId ?? "", signal),
    enabled: familySlug !== "" && photoId !== "" && previewId !== null,
    retry: false,
    staleTime: 0,
  });
}

function useInvalidatePhotoEditor(familySlug: string, photoId: string) {
  const queryClient = useQueryClient();
  return async () => {
    await Promise.all([
      queryClient.invalidateQueries({
        queryKey: photoEditorKeys.versions(familySlug, photoId),
      }),
      queryClient.invalidateQueries({ queryKey: photoKeys.all(familySlug) }),
    ]);
  };
}

export function useCreatePhotoEditPreviewMutation(
  familySlug: string,
  photoId: string,
) {
  return useMutation({
    mutationFn: (recipe: EditRecipe) =>
      createPhotoEditPreview(familySlug, photoId, recipe),
  });
}

export function useCreateRestorePreviewMutation(
  familySlug: string,
  photoId: string,
) {
  return useMutation({
    mutationFn: () => createRestorePreview(familySlug, photoId),
  });
}

export function useApplyPhotoEditPreviewMutation(
  familySlug: string,
  photoId: string,
) {
  const invalidate = useInvalidatePhotoEditor(familySlug, photoId);
  return useMutation({
    mutationFn: (previewId: string) =>
      applyPhotoEditPreview(familySlug, photoId, previewId),
    onSuccess: invalidate,
  });
}

export function useDiscardPhotoEditPreviewMutation(
  familySlug: string,
  photoId: string,
) {
  return useMutation({
    mutationFn: (previewId: string) =>
      discardPhotoEditPreview(familySlug, photoId, previewId),
  });
}

export function useActivatePhotoVersionMutation(
  familySlug: string,
  photoId: string,
) {
  const invalidate = useInvalidatePhotoEditor(familySlug, photoId);
  return useMutation({
    mutationFn: (versionId: string | null) =>
      activatePhotoVersion(familySlug, photoId, versionId),
    onSuccess: invalidate,
  });
}
