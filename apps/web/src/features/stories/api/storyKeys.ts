export const storyKeys = {
  all: (familySlug: string) => ["stories", familySlug] as const,
  detail: (familySlug: string, storyId: string) =>
    [...storyKeys.all(familySlug), "detail", storyId] as const,
};
