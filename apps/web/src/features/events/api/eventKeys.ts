export const eventKeys = {
  all: (familySlug: string) => ["families", familySlug, "events"] as const,
  list: (familySlug: string) => [...eventKeys.all(familySlug), "list"] as const,
  detail: (familySlug: string, eventId: string) =>
    [...eventKeys.all(familySlug), "detail", eventId] as const,
  duplicates: (familySlug: string, eventId: string) =>
    [...eventKeys.detail(familySlug, eventId), "duplicates"] as const,
  person: (familySlug: string, personId: string) =>
    [...eventKeys.all(familySlug), "person", personId] as const,
};
