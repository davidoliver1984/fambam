import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  addPhotoToAlbum,
  createAlbum,
  getAlbums,
  getAlbum,
  removePhotoFromAlbum,
  uploadPhotoToAlbum,
} from "../api/albumApi";
import { albumKeys } from "../api/albumKeys";
import type { CreateAlbumInput } from "../types/album";

export function useAlbumsQuery(familySlug: string) {
  return useQuery({
    queryKey: albumKeys.list(familySlug),
    queryFn: ({ signal }) => getAlbums(familySlug, signal),
    enabled: familySlug !== "",
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

export function useCreateAlbumMutation(familySlug: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (input: CreateAlbumInput) => createAlbum(familySlug, input),
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
