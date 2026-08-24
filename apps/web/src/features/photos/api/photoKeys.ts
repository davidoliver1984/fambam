export const photoKeys = {
  all: (familySlug: string) => ["photos", familySlug] as const,
  list: (familySlug: string, filters: object = {}) =>
    [...photoKeys.all(familySlug), "list", filters] as const,
  detail: (familySlug: string, photoId: string) =>
    [...photoKeys.all(familySlug), "detail", photoId] as const,
  proposals: (familySlug: string, photoId: string) =>
    [...photoKeys.detail(familySlug, photoId), "proposals"] as const,
  metadataProposals: (familySlug: string, photoId: string) =>
    [...photoKeys.detail(familySlug, photoId), "metadata-proposals"] as const,
  personProposals: (familySlug: string, photoId: string) =>
    [...photoKeys.detail(familySlug, photoId), "person-proposals"] as const,
  conversation: (familySlug: string, photoId: string) =>
    [...photoKeys.detail(familySlug, photoId), "conversation"] as const,
};
