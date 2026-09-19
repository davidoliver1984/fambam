import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  addCollectionPhoto,
  createCollection,
  deleteCollection,
  getCollection,
  getCollections,
  removeCollectionPhoto,
  requestCollectionExport,
} from "../api/collectionApi";
import { collectionKeys } from "../api/collectionKeys";
import type { CollectionInput } from "../types/collection";

export function useCollectionsQuery(familySlug: string) {
  return useQuery({
    queryKey: collectionKeys.list(familySlug),
    queryFn: ({ signal }) => getCollections(familySlug, signal),
    retry: false,
  });
}
export function useCollectionQuery(familySlug: string, collectionId: string) {
  return useQuery({
    queryKey: collectionKeys.detail(familySlug, collectionId),
    queryFn: ({ signal }) => getCollection(familySlug, collectionId, signal),
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
export function useCollectionMutations(
  familySlug: string,
  collectionId: string,
) {
  const client = useQueryClient();
  const refresh = () =>
    client.invalidateQueries({
      queryKey: collectionKeys.detail(familySlug, collectionId),
    });
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
    }),
    requestExport: useMutation({
      mutationFn: () => requestCollectionExport(familySlug, collectionId),
    }),
  };
}
