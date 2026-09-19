export const collectionKeys = {
  all: (familySlug: string) => ["collections", familySlug] as const,
  list: (familySlug: string) =>
    [...collectionKeys.all(familySlug), "list"] as const,
  detail: (familySlug: string, collectionId: string) =>
    [...collectionKeys.all(familySlug), "detail", collectionId] as const,
};
