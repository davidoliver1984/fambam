import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  addPhotoToAlbum,
  createAlbum,
  deleteAlbum,
  getAlbums,
  getAlbum,
  removePhotoFromAlbum,
  uploadPhotoToAlbum,
  requestAlbumExport,
  setAlbumCover,
  updateAlbum,
} from "../api/albumApi";
import { albumKeys } from "../api/albumKeys";
import { familyExportKeys } from "@/features/exports/api/familyExportKeys";
import { homeKeys } from "@/features/home/hooks/useHomeQuery";
import { searchKeys } from "@/features/search/api/searchKeys";
import type {
  CreateAlbumInput,
  SetAlbumCoverInput,
  UpdateAlbumInput,
} from "../types/album";

export function useAlbumsQuery(familySlug: string, enabled = true) {
  return useQuery({
    queryKey: albumKeys.list(familySlug),
    queryFn: ({ signal }) => getAlbums(familySlug, signal),
    enabled: enabled && familySlug !== "",
    retry: false,
  });
}

export function useAlbumQuery(familySlug: string, albumId: string) {
  return useQuery({
    queryKey: albumKeys.detail(familySlug, albumId),
    queryFn: ({ signal }) => getAlbum(familySlug, albumId, signal),
    enabled: familySlug !== "" && albumId !== "",
    retry: false,
  });
}

export function useAlbumUploadMutation(familySlug: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (input: { albumId: string; file: File }) =>
      uploadPhotoToAlbum(familySlug, input.albumId, input.file),
    onSuccess: () =>
      client.invalidateQueries({ queryKey: albumKeys.all(familySlug) }),
  });
}

export function useAlbumExportMutation(familySlug: string, albumId: string) {
  return useMutation({
    mutationFn: () => requestAlbumExport(familySlug, albumId),
  });
}

export function useCreateAlbumMutation(familySlug: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (input: CreateAlbumInput) => createAlbum(familySlug, input),
    onSuccess: () =>
      client.invalidateQueries({ queryKey: albumKeys.all(familySlug) }),
  });
}

export function useUpdateAlbumMutation(familySlug: string, albumId: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (input: UpdateAlbumInput) =>
      updateAlbum(familySlug, albumId, input),
    onSuccess: async () => {
      await Promise.all([
        client.invalidateQueries({ queryKey: albumKeys.all(familySlug) }),
        client.invalidateQueries({ queryKey: searchKeys.all(familySlug) }),
        client.invalidateQueries({ queryKey: homeKeys.detail(familySlug) }),
      ]);
    },
  });
}

export function useDeleteAlbumMutation(familySlug: string, albumId: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: () => deleteAlbum(familySlug, albumId),
    onSuccess: async () => {
      client.removeQueries({ queryKey: albumKeys.detail(familySlug, albumId) });
      await Promise.all([
        client.invalidateQueries({ queryKey: albumKeys.list(familySlug) }),
        client.invalidateQueries({ queryKey: searchKeys.all(familySlug) }),
        client.invalidateQueries({ queryKey: homeKeys.detail(familySlug) }),
        client.invalidateQueries({
          queryKey: familyExportKeys.all(familySlug),
        }),
      ]);
    },
  });
}

export function useAlbumCoverMutation(familySlug: string, albumId: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (input: SetAlbumCoverInput) =>
      setAlbumCover(familySlug, albumId, input),
    onSuccess: () =>
      client.invalidateQueries({ queryKey: albumKeys.all(familySlug) }),
  });
}

export function useAddAlbumPhotoMutation(familySlug: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (input: {
      albumId: string;
      photoId: string;
      confirmed: boolean;
    }) =>
      addPhotoToAlbum(
        familySlug,
        input.albumId,
        input.photoId,
        input.confirmed,
      ),
    onSuccess: () =>
      client.invalidateQueries({ queryKey: albumKeys.all(familySlug) }),
  });
}

export function useRemoveAlbumPhotoMutation(familySlug: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (input: { albumId: string; photoId: string }) =>
      removePhotoFromAlbum(familySlug, input.albumId, input.photoId),
    onSuccess: () =>
      client.invalidateQueries({ queryKey: albumKeys.all(familySlug) }),
  });
}
