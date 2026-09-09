export const familyActivityKeys = {
  all: ["family-activities"] as const,
  recent: (familySlug: string) =>
    [...familyActivityKeys.all, "recent", { familySlug }] as const,
};
