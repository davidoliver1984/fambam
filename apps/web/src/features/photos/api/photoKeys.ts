export const photoKeys = {
  all: (familySlug: string) => ["photos", familySlug] as const,
  list: (familySlug: string) => [...photoKeys.all(familySlug), "list"] as const,
  detail: (familySlug: string, photoId: string) =>
    [...photoKeys.all(familySlug), "detail", photoId] as const,
  proposals: (familySlug: string, photoId: string) =>
    [...photoKeys.detail(familySlug, photoId), "proposals"] as const,
};
