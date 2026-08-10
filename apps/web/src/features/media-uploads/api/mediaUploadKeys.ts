export const mediaUploadKeys = {
  all: ["media-uploads"] as const,
  batch: (familySlug: string, batchId: string) =>
    [...mediaUploadKeys.all, "batch", { familySlug, batchId }] as const,
};
