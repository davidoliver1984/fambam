import { useQuery } from "@tanstack/react-query";

import { getMediaUploadBatch } from "../api/mediaUploadApi";
import { mediaUploadKeys } from "../api/mediaUploadKeys";

export function useMediaUploadBatchQuery(
  familySlug: string,
  batchId: string | null,
) {
  return useQuery({
    queryKey: mediaUploadKeys.batch(familySlug, batchId ?? "pending"),
    queryFn: ({ signal }) =>
      getMediaUploadBatch(familySlug, batchId ?? "", signal),
    enabled: batchId !== null,
    retry: false,
    refetchInterval: (query) => (query.state.data?.active ? 2_000 : false),
  });
}
