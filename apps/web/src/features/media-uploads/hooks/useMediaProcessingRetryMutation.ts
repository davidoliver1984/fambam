import { useMutation, useQueryClient } from "@tanstack/react-query";

import { retryMediaUploadProcessing } from "../api/mediaUploadApi";
import { mediaUploadKeys } from "../api/mediaUploadKeys";

export function useMediaProcessingRetryMutation(
  familySlug: string,
  batchId: string | null,
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (mediaUploadId: string) =>
      retryMediaUploadProcessing(familySlug, mediaUploadId),
    onSuccess: async () => {
      if (batchId !== null) {
        await queryClient.invalidateQueries({
          queryKey: mediaUploadKeys.batch(familySlug, batchId),
        });
      }
    },
  });
}
