export const familySpaceKeys = {
  all: ["family-spaces"] as const,
  list: () => [...familySpaceKeys.all, "list"] as const,
  detail: (familySlug: string) =>
    [...familySpaceKeys.all, "detail", { familySlug }] as const,
};
