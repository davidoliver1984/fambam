import { useQuery } from "@tanstack/react-query";

import { getMediaVariantDelivery } from "../api/mediaUploadApi";
import { mediaUploadKeys } from "../api/mediaUploadKeys";
import type { MediaVariantTransform } from "../types/mediaUpload";

export function useMediaVariantQuery(
  familySlug: string,
  mediaUploadId: string,
  transform: MediaVariantTransform,
) {
  return useQuery({
    queryKey: mediaUploadKeys.variant(familySlug, mediaUploadId, transform),
    queryFn: ({ signal }) =>
      getMediaVariantDelivery(familySlug, mediaUploadId, transform, signal),
    enabled: familySlug !== "" && mediaUploadId !== "",
    retry: false,
    staleTime: 0,
  });
}
