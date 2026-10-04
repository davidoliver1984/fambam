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
    refetchInterval: (query) =>
      query.state.data?.active === true ||
      query.state.data?.face_review.analysis_pending === true
        ? 2_000
        : false,
  });
}
