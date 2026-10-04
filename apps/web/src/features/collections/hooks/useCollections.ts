import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  addCollectionPhoto,
  addCollectionPhotos,
  createCollection,
  deleteCollection,
  getCollection,
  getCollections,
  populateCollection,
  removeCollectionPhoto,
  reorderCollectionPhotos,
  requestCollectionExport,
  updateCollection,
} from "../api/collectionApi";
import { collectionKeys } from "../api/collectionKeys";
import { familyExportKeys } from "@/features/exports/api/familyExportKeys";
import type {
  CollectionInput,
  CollectionUpdateInput,
} from "../types/collection";

export function useCollectionsQuery(familySlug: string, enabled = true) {
  return useQuery({
    queryKey: collectionKeys.list(familySlug),
    queryFn: ({ signal }) => getCollections(familySlug, signal),
    enabled: enabled && familySlug !== "",
    retry: false,
  });
}
export function useCollectionQuery(
  familySlug: string,
  collectionId: string,
  enabled = true,
) {
  return useQuery({
    queryKey: collectionKeys.detail(familySlug, collectionId),
    queryFn: ({ signal }) => getCollection(familySlug, collectionId, signal),
    enabled: enabled && familySlug !== "" && collectionId !== "",
    retry: false,
  });
}
export function useCreateCollectionMutation(familySlug: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (input: CollectionInput) => createCollection(familySlug, input),
    onSuccess: () =>
      client.invalidateQueries({ queryKey: collectionKeys.list(familySlug) }),
  });
}
export function usePopulateCollectionMutation(
  familySlug: string,
  source: { type: "album" | "event"; id: string },
) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (collectionId: string) =>
      populateCollection(familySlug, collectionId, source.type, source.id),
    onSuccess: (collection) =>
      client.invalidateQueries({
        queryKey: collectionKeys.detail(familySlug, collection.id),
      }),
  });
}
export function useUpdateCollectionMutation(
  familySlug: string,
  collectionId: string,
) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (input: CollectionUpdateInput) =>
      updateCollection(familySlug, collectionId, input),
    onSuccess: async () => {
      await Promise.all([
        client.invalidateQueries({
          queryKey: collectionKeys.detail(familySlug, collectionId),
        }),
        client.invalidateQueries({ queryKey: collectionKeys.list(familySlug) }),
      ]);
    },
  });
}
export function useReorderCollectionPhotosMutation(
  familySlug: string,
  collectionId: string,
) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (photoIds: string[]) =>
      reorderCollectionPhotos(familySlug, collectionId, photoIds),
    onSuccess: async () => {
      await Promise.all([
        client.invalidateQueries({
          queryKey: collectionKeys.detail(familySlug, collectionId),
        }),
        client.invalidateQueries({ queryKey: collectionKeys.list(familySlug) }),
      ]);
    },
  });
}
export function useAddCollectionPhotosMutation(
  familySlug: string,
  collectionId: string,
) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (photoIds: string[]) =>
      addCollectionPhotos(familySlug, collectionId, photoIds),
    onSuccess: async () => {
      await Promise.all([
        client.invalidateQueries({
          queryKey: collectionKeys.detail(familySlug, collectionId),
        }),
        client.invalidateQueries({ queryKey: collectionKeys.list(familySlug) }),
      ]);
    },
  });
}
export function useCollectionMutations(
  familySlug: string,
  collectionId: string,
) {
  const client = useQueryClient();
  const refresh = async () => {
    await Promise.all([
      client.invalidateQueries({
        queryKey: collectionKeys.detail(familySlug, collectionId),
      }),
      client.invalidateQueries({ queryKey: collectionKeys.list(familySlug) }),
    ]);
  };
  return {
    add: useMutation({
      mutationFn: (photoId: string) =>
        addCollectionPhoto(familySlug, collectionId, photoId),
      onSuccess: refresh,
    }),
    removePhoto: useMutation({
      mutationFn: (photoId: string) =>
        removeCollectionPhoto(familySlug, collectionId, photoId),
      onSuccess: refresh,
    }),
    removeCollection: useMutation({
      mutationFn: () => deleteCollection(familySlug, collectionId),
      onSuccess: () =>
        client.invalidateQueries({ queryKey: collectionKeys.list(familySlug) }),
    }),
    requestExport: useMutation({
      mutationFn: () => requestCollectionExport(familySlug, collectionId),
      onSuccess: () =>
        client.invalidateQueries({
          queryKey: familyExportKeys.all(familySlug),
        }),
    }),
  };
}
