import { useMutation, useQueryClient } from "@tanstack/react-query";

import {
  createPhoto,
  deletePhoto,
  replacePhotoTags,
  resolvePhotoMetadataProposal,
  resolvePhotoPersonProposal,
  resolvePhotoProvenanceProposal,
  submitPhotoProvenance,
  submitPhotoMetadata,
  submitPhotoPerson,
  updatePhoto,
  restorePhoto,
} from "../api/photoApi";
import { photoKeys } from "../api/photoKeys";
import type {
  CreatePhotoInput,
  PhotoMetadataInput,
  PhotoProposalResolution,
  PhotoProvenanceInput,
  UpdatePhotoInput,
} from "../types/photo";

export function useCreatePhotoMutation(familySlug: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: CreatePhotoInput) => createPhoto(familySlug, input),
    onSuccess: async (result) => {
      if (
        result.outcome === "duplicate_detected" ||
        result.outcome === "cancelled"
      ) {
        return;
      }
      await queryClient.invalidateQueries({
        queryKey: photoKeys.list(familySlug),
      });
    },
  });
}

export function useDeletePhotoMutation(familySlug: string, photoId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => deletePhoto(familySlug, photoId),
    onSuccess: async () => {
      queryClient.removeQueries({
        queryKey: photoKeys.detail(familySlug, photoId),
      });
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: photoKeys.list(familySlug) }),
        queryClient.invalidateQueries({
          queryKey: photoKeys.deleted(familySlug),
        }),
      ]);
    },
  });
}

export function useRestorePhotoMutation(familySlug: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (photoId: string) => restorePhoto(familySlug, photoId),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: photoKeys.list(familySlug) }),
        queryClient.invalidateQueries({
          queryKey: photoKeys.deleted(familySlug),
        }),
      ]);
    },
  });
}

export function useSubmitPhotoMetadataMutation(
  familySlug: string,
  photoId: string,
) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: PhotoMetadataInput) =>
      submitPhotoMetadata(familySlug, photoId, input),
    onSuccess: async (proposal) => {
      await queryClient.invalidateQueries({
        queryKey: photoKeys.metadataProposals(familySlug, photoId),
      });
      if (proposal.status === "approved") {
        await queryClient.invalidateQueries({
          queryKey: photoKeys.detail(familySlug, photoId),
        });
      }
    },
  });
}

export function useResolvePhotoMetadataMutation(
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
      resolvePhotoMetadataProposal(familySlug, photoId, proposalId, resolution),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: photoKeys.metadataProposals(familySlug, photoId),
        }),
        queryClient.invalidateQueries({
          queryKey: photoKeys.detail(familySlug, photoId),
        }),
      ]);
    },
  });
}

export function useSubmitPhotoPersonMutation(
  familySlug: string,
  photoId: string,
) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (personId: string) =>
      submitPhotoPerson(familySlug, photoId, personId),
    onSuccess: async (association) => {
      await queryClient.invalidateQueries({
        queryKey: photoKeys.personProposals(familySlug, photoId),
      });
      if (association.status === "approved") {
        await queryClient.invalidateQueries({
          queryKey: photoKeys.detail(familySlug, photoId),
        });
      }
    },
  });
}

export function useResolvePhotoPersonMutation(
  familySlug: string,
  photoId: string,
) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      associationId,
      resolution,
    }: {
      associationId: string;
      resolution: PhotoProposalResolution;
    }) =>
      resolvePhotoPersonProposal(
        familySlug,
        photoId,
        associationId,
        resolution,
      ),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: photoKeys.personProposals(familySlug, photoId),
        }),
        queryClient.invalidateQueries({
          queryKey: photoKeys.detail(familySlug, photoId),
        }),
      ]);
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
