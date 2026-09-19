export const familySpaceKeys = {
  all: ["family-spaces"] as const,
  list: () => [...familySpaceKeys.all, "list"] as const,
  detail: (familySlug: string) =>
    [...familySpaceKeys.all, "detail", { familySlug }] as const,
  memberships: (familySlug: string) =>
    [...familySpaceKeys.detail(familySlug), "memberships"] as const,
};
