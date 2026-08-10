import { useMutation, useQueryClient } from "@tanstack/react-query";

import { uploadMediaBatch } from "../api/mediaUploadApi";
import { mediaUploadKeys } from "../api/mediaUploadKeys";
import type { MediaUploadBatchInput } from "../types/mediaUpload";

export function useMediaUploadMutation(familySlug: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: MediaUploadBatchInput) =>
      uploadMediaBatch(familySlug, input),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({
        queryKey: mediaUploadKeys.batch(familySlug, result.batch_id),
      });
    },
  });
}

export function createMediaUploadBatch(files: File[]): MediaUploadBatchInput {
  return {
    batchId: createUlid(),
    items: files.map((file) => ({ file, idempotencyKey: crypto.randomUUID() })),
  };
}

function createUlid(): string {
  const alphabet = "0123456789ABCDEFGHJKMNPQRSTVWXYZ";
  let timestamp = Date.now();
  const timeCharacters = Array.from({ length: 10 }, () => "0");
  for (let index = timeCharacters.length - 1; index >= 0; index -= 1) {
    timeCharacters[index] = alphabet[timestamp % 32];
    timestamp = Math.floor(timestamp / 32);
  }
  const randomness = crypto.getRandomValues(new Uint8Array(16));
  const randomCharacters = Array.from(
    randomness,
    (value) => alphabet[value & 31],
  );

  return [...timeCharacters, ...randomCharacters].join("");
}
