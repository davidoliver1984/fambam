export const photoKeys = {
  all: (familySlug: string) => ["photos", familySlug] as const,
  list: (familySlug: string, filters: object = {}) =>
    [...photoKeys.all(familySlug), "list", filters] as const,
  deleted: (familySlug: string) =>
    [...photoKeys.all(familySlug), "deleted"] as const,
  promotableUploads: (familySlug: string) =>
    [...photoKeys.all(familySlug), "promotable-uploads"] as const,
  duplicateHolds: (familySlug: string) =>
    [...photoKeys.all(familySlug), "duplicate-holds"] as const,
  detail: (familySlug: string, photoId: string) =>
    [...photoKeys.all(familySlug), "detail", photoId] as const,
  proposals: (familySlug: string, photoId: string) =>
    [...photoKeys.detail(familySlug, photoId), "proposals"] as const,
  metadataProposals: (familySlug: string, photoId: string) =>
    [...photoKeys.detail(familySlug, photoId), "metadata-proposals"] as const,
  personProposals: (familySlug: string, photoId: string) =>
    [...photoKeys.detail(familySlug, photoId), "person-proposals"] as const,
  conversation: (familySlug: string, photoId: string, albumId?: string) =>
    [
      ...photoKeys.detail(familySlug, photoId),
      "conversation",
      { albumId: albumId ?? null },
    ] as const,
};
