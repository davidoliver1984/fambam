import { useMutation, useQueryClient } from "@tanstack/react-query";

import {
  createPhoto,
  replacePhotoTags,
  resolvePhotoProvenanceProposal,
  submitPhotoProvenance,
  updatePhoto,
} from "../api/photoApi";
import { photoKeys } from "../api/photoKeys";
import type {
  CreatePhotoInput,
  PhotoProposalResolution,
  PhotoProvenanceInput,
  UpdatePhotoInput,
} from "../types/photo";

export function useCreatePhotoMutation(familySlug: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: CreatePhotoInput) => createPhoto(familySlug, input),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: photoKeys.list(familySlug),
      });
    },
  });
}

export function useUpdatePhotoMutation(familySlug: string, photoId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: UpdatePhotoInput) =>
      updatePhoto(familySlug, photoId, input),
    onSuccess: async (photo) => {
      queryClient.setQueryData(photoKeys.detail(familySlug, photoId), photo);
      await queryClient.invalidateQueries({
        queryKey: photoKeys.list(familySlug),
      });
    },
  });
}

export function useReplacePhotoTagsMutation(
  familySlug: string,
  photoId: string,
) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (tags: string[]) => replacePhotoTags(familySlug, photoId, tags),
    onSuccess: async (photo) => {
      queryClient.setQueryData(photoKeys.detail(familySlug, photoId), photo);
      await queryClient.invalidateQueries({
        queryKey: photoKeys.list(familySlug),
      });
    },
  });
}

export function useSubmitPhotoProvenanceMutation(
  familySlug: string,
  photoId: string,
) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: PhotoProvenanceInput) =>
      submitPhotoProvenance(familySlug, photoId, input),
    onSuccess: async (proposal) => {
      await queryClient.invalidateQueries({
        queryKey: photoKeys.proposals(familySlug, photoId),
      });
      if (proposal.status === "approved") {
        await queryClient.invalidateQueries({
          queryKey: photoKeys.detail(familySlug, photoId),
        });
      }
    },
  });
}

export function useResolvePhotoProvenanceMutation(
  familySlug: string,
  photoId: string,
) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      proposalId,
      resolution,
    }: {
      proposalId: string;
      resolution: PhotoProposalResolution;
    }) =>
      resolvePhotoProvenanceProposal(
        familySlug,
        photoId,
        proposalId,
        resolution,
      ),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: photoKeys.proposals(familySlug, photoId),
        }),
        queryClient.invalidateQueries({
          queryKey: photoKeys.detail(familySlug, photoId),
        }),
        queryClient.invalidateQueries({ queryKey: photoKeys.list(familySlug) }),
      ]);
    },
  });
}
