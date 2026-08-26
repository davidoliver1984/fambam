import { useQuery } from "@tanstack/react-query";

import {
  getPhoto,
  getDeletedPhotos,
  getPhotoMetadataProposals,
  getPhotoPersonProposals,
  getPhotoProvenanceProposals,
  getPhotos,
} from "../api/photoApi";
import { photoKeys } from "../api/photoKeys";
import type { PhotoFilters } from "../types/photo";

export function usePhotosQuery(
  familySlug: string,
  filters: PhotoFilters = {},
  enabled = true,
) {
  return useQuery({
    queryKey: photoKeys.list(familySlug, filters),
    queryFn: ({ signal }) => getPhotos(familySlug, filters, signal),
    enabled: enabled && familySlug !== "",
    retry: false,
  });
}

export function useDeletedPhotosQuery(familySlug: string) {
  return useQuery({
    queryKey: photoKeys.deleted(familySlug),
    queryFn: ({ signal }) => getDeletedPhotos(familySlug, signal),
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

export function usePhotoQuery(familySlug: string, photoId: string) {
  return useQuery({
    queryKey: photoKeys.detail(familySlug, photoId),
    queryFn: ({ signal }) => getPhoto(familySlug, photoId, signal),
    enabled: familySlug !== "" && photoId !== "",
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
