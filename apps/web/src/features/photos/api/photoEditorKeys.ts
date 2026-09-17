export const photoEditorKeys = {
  all: (familySlug: string, photoId: string) =>
    ["photo-editor", familySlug, photoId] as const,
  versions: (familySlug: string, photoId: string) =>
    [...photoEditorKeys.all(familySlug, photoId), "versions"] as const,
  versionDelivery: (familySlug: string, photoId: string, versionId: string) =>
    [
      ...photoEditorKeys.all(familySlug, photoId),
      "version",
      versionId,
    ] as const,
  previewDelivery: (familySlug: string, photoId: string, previewId: string) =>
    [
      ...photoEditorKeys.all(familySlug, photoId),
      "preview",
      previewId,
    ] as const,
};
