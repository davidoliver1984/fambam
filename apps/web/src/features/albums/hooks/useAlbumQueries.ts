import {
  useInfiniteQuery,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";

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
import { photoKeys } from "@/features/photos/api/photoKeys";
import { searchKeys } from "@/features/search/api/searchKeys";
import type {
  CreateAlbumInput,
  AlbumListCriteria,
  SetAlbumCoverInput,
  UpdateAlbumInput,
} from "../types/album";

export function useAlbumsQuery(
  familySlug: string,
  criteria: AlbumListCriteria = {},
  enabled = true,
) {
  return useInfiniteQuery({
    queryKey: albumKeys.page(familySlug, criteria),
    queryFn: ({ pageParam, signal }) =>
      getAlbums(familySlug, criteria, pageParam, signal),
    initialPageParam: null as string | null,
    getNextPageParam: (lastPage) => lastPage.next_cursor ?? undefined,
    select: (data) => data.pages.flatMap((page) => page.items),
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
    mutationFn: (input: {
      albumId: string;
      file: File;
      asCover?: boolean;
      coverFocalY?: number;
    }) =>
      uploadPhotoToAlbum(
        familySlug,
        input.albumId,
        input.file,
        input.asCover,
        input.coverFocalY,
      ),
    onSuccess: async () => {
      await Promise.all([
        client.invalidateQueries({ queryKey: albumKeys.all(familySlug) }),
        client.invalidateQueries({ queryKey: photoKeys.all(familySlug) }),
        client.invalidateQueries({ queryKey: searchKeys.all(familySlug) }),
        client.invalidateQueries({ queryKey: homeKeys.detail(familySlug) }),
      ]);
    },
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
    onSuccess: async (album) => {
      client.setQueryData(albumKeys.detail(familySlug, album.id), album);
      await Promise.all([
        client.invalidateQueries({ queryKey: albumKeys.list(familySlug) }),
        client.invalidateQueries({ queryKey: searchKeys.all(familySlug) }),
        client.invalidateQueries({ queryKey: homeKeys.detail(familySlug) }),
      ]);
    },
  });
}

export function useSetAlbumCoverMutation(familySlug: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (input: { albumId: string; cover: SetAlbumCoverInput }) =>
      setAlbumCover(familySlug, input.albumId, input.cover),
    onSuccess: async (album) => {
      client.setQueryData(albumKeys.detail(familySlug, album.id), album);
      await Promise.all([
        client.invalidateQueries({ queryKey: albumKeys.list(familySlug) }),
        client.invalidateQueries({ queryKey: photoKeys.all(familySlug) }),
        client.invalidateQueries({ queryKey: searchKeys.all(familySlug) }),
        client.invalidateQueries({ queryKey: homeKeys.detail(familySlug) }),
      ]);
    },
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

export function useDeleteAlbumMutation(
  familySlug: string,
  albumId: string,
  onDeleted?: () => void,
) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: () => deleteAlbum(familySlug, albumId),
    onSuccess: async () => {
      await client.cancelQueries({
        queryKey: albumKeys.detail(familySlug, albumId),
      });
      client.removeQueries({
        queryKey: albumKeys.detail(familySlug, albumId),
        exact: true,
      });
      await Promise.all([
        client.invalidateQueries({ queryKey: albumKeys.list(familySlug) }),
        client.invalidateQueries({ queryKey: searchKeys.all(familySlug) }),
        client.invalidateQueries({ queryKey: homeKeys.detail(familySlug) }),
        client.invalidateQueries({
          queryKey: familyExportKeys.all(familySlug),
        }),
      ]);
      onDeleted?.();
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
    onSuccess: async (_data, input) => {
      await Promise.all([
        client.invalidateQueries({ queryKey: albumKeys.all(familySlug) }),
        client.invalidateQueries({ queryKey: photoKeys.all(familySlug) }),
        client.invalidateQueries({
          queryKey: photoKeys.albumHistory(familySlug, input.photoId),
        }),
      ]);
    },
  });
}

export function useRemoveAlbumPhotoMutation(familySlug: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (input: { albumId: string; photoId: string }) =>
      removePhotoFromAlbum(familySlug, input.albumId, input.photoId),
    onSuccess: async (_data, input) => {
      await Promise.all([
        client.invalidateQueries({ queryKey: albumKeys.all(familySlug) }),
        client.invalidateQueries({ queryKey: photoKeys.all(familySlug) }),
        client.invalidateQueries({
          queryKey: photoKeys.albumHistory(familySlug, input.photoId),
        }),
      ]);
    },
  });
}
