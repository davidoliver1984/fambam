export const mediaUploadKeys = {
  all: ["media-uploads"] as const,
  variant: (
    familySlug: string,
    mediaUploadId: string,
    transform: "thumbnail" | "card" | "display",
  ) =>
    [
      ...mediaUploadKeys.all,
      "variant",
      { familySlug, mediaUploadId, transform },
    ] as const,
  batch: (familySlug: string, batchId: string) =>
    [...mediaUploadKeys.all, "batch", { familySlug, batchId }] as const,
};
