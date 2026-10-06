import { useInfiniteQuery, useQuery } from "@tanstack/react-query";
import { useCallback, useRef } from "react";

import {
  getPhoto,
  getPhotoAlbumHistory,
  getDeletedPhotos,
  getPhotoMetadataProposals,
  getPhotoPersonProposals,
  getPhotoProvenanceProposals,
  getPhotos,
  getPromotableMediaUploads,
} from "../api/photoApi";
import { photoKeys } from "../api/photoKeys";
import type { PhotoListCriteria } from "../types/photo";

export function usePhotosQuery(
  familySlug: string,
  criteria: PhotoListCriteria = {},
  enabled = true,
) {
  const query = useInfiniteQuery({
    queryKey: photoKeys.list(familySlug, criteria),
    queryFn: ({ pageParam, signal }) =>
      getPhotos(familySlug, criteria, pageParam, signal),
    initialPageParam: null as string | null,
    getNextPageParam: (lastPage) => lastPage.next_cursor ?? undefined,
    select: (data) => data.pages.flatMap((page) => page.items),
    enabled: enabled && familySlug !== "",
    retry: false,
  });
  const nextPageRequest = useRef<ReturnType<typeof query.fetchNextPage> | null>(
    null,
  );
  const fetchQueryNextPage = query.fetchNextPage;
  const fetchNextPage = useCallback(() => {
    if (nextPageRequest.current !== null) return nextPageRequest.current;
    const request = fetchQueryNextPage({ cancelRefetch: false });
    nextPageRequest.current = request;
    void request.finally(() => {
      if (nextPageRequest.current === request) nextPageRequest.current = null;
    });

    return request;
  }, [fetchQueryNextPage]);

  return { ...query, fetchNextPage };
}

export function useDeletedPhotosQuery(familySlug: string) {
  return useQuery({
    queryKey: photoKeys.deleted(familySlug),
    queryFn: ({ signal }) => getDeletedPhotos(familySlug, signal),
    enabled: familySlug !== "",
    retry: false,
  });
}

export function usePromotableMediaUploadsQuery(familySlug: string) {
  return useQuery({
    queryKey: photoKeys.promotableUploads(familySlug),
    queryFn: ({ signal }) => getPromotableMediaUploads(familySlug, signal),
    enabled: familySlug !== "",
    retry: false,
  });
}

export function usePhotoMetadataProposalsQuery(
  familySlug: string,
  photoId: string,
  enabled: boolean,
) {
  return useQuery({
    queryKey: photoKeys.metadataProposals(familySlug, photoId),
    queryFn: ({ signal }) =>
      getPhotoMetadataProposals(familySlug, photoId, signal),
    enabled: enabled && familySlug !== "" && photoId !== "",
    retry: false,
  });
}

export function usePhotoPersonProposalsQuery(
  familySlug: string,
  photoId: string,
  enabled: boolean,
) {
  return useQuery({
    queryKey: photoKeys.personProposals(familySlug, photoId),
    queryFn: ({ signal }) =>
      getPhotoPersonProposals(familySlug, photoId, signal),
    enabled: enabled && familySlug !== "" && photoId !== "",
    retry: false,
  });
}

export function usePhotoQuery(
  familySlug: string,
  photoId: string,
  enabled = true,
) {
  return useQuery({
    queryKey: photoKeys.detail(familySlug, photoId),
    queryFn: ({ signal }) => getPhoto(familySlug, photoId, signal),
    enabled: enabled && familySlug !== "" && photoId !== "",
    retry: false,
  });
}

export function usePhotoAlbumHistoryQuery(
  familySlug: string,
  photoId: string,
  enabled = true,
) {
  return useQuery({
    queryKey: photoKeys.albumHistory(familySlug, photoId),
    queryFn: ({ signal }) => getPhotoAlbumHistory(familySlug, photoId, signal),
    enabled: enabled && familySlug !== "" && photoId !== "",
    retry: false,
  });
}

export function usePhotoProvenanceProposalsQuery(
  familySlug: string,
  photoId: string,
  enabled: boolean,
) {
  return useQuery({
    queryKey: photoKeys.proposals(familySlug, photoId),
    queryFn: ({ signal }) =>
      getPhotoProvenanceProposals(familySlug, photoId, signal),
    enabled: enabled && familySlug !== "" && photoId !== "",
    retry: false,
  });
}
