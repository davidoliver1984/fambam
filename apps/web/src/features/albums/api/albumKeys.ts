export const albumKeys = {
  all: (familySlug: string) => ["albums", familySlug] as const,
  list: (familySlug: string) => [...albumKeys.all(familySlug), "list"] as const,
};
