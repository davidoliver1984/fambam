export const memoryKeys = {
  all: ["memories"] as const,
  dateBased: (familySlug: string) =>
    [...memoryKeys.all, "date-based", { familySlug }] as const,
  homepage: (familySlug: string) =>
    [...memoryKeys.all, "homepage", { familySlug }] as const,
};
