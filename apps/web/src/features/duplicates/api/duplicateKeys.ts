export const duplicateKeys = {
  all: (familySlug: string) => ["duplicate-review", familySlug] as const,
  candidates: (familySlug: string) =>
    [...duplicateKeys.all(familySlug), "candidates"] as const,
  decisions: (familySlug: string) =>
    [...duplicateKeys.all(familySlug), "decisions"] as const,
};
