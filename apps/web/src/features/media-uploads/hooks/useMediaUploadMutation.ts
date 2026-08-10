import { useMutation } from "@tanstack/react-query";

import { uploadMediaFile } from "../api/mediaUploadApi";

export function useMediaUploadMutation(familySlug: string) {
  return useMutation({
    mutationFn: ({ file, idempotencyKey }: UploadVariables) =>
      uploadMediaFile(familySlug, file, idempotencyKey),
  });
}

type UploadVariables = {
  file: File;
  idempotencyKey: string;
};
