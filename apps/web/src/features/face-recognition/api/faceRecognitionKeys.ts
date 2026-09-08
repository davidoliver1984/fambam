export const faceRecognitionKeys = {
  all: (familySlug: string) => ["face-recognition", familySlug] as const,
  assignments: (familySlug: string) =>
    [...faceRecognitionKeys.all(familySlug), "assignments"] as const,
  suppressions: (familySlug: string) =>
    [...faceRecognitionKeys.all(familySlug), "suppressions"] as const,
  clusters: (familySlug: string) =>
    [...faceRecognitionKeys.all(familySlug), "clusters"] as const,
};
