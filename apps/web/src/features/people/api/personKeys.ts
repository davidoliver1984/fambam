export const personKeys = {
  all: ["people"] as const,
  lists: () => [...personKeys.all, "list"] as const,
  list: (familySlug: string) => [...personKeys.lists(), familySlug] as const,
  details: () => [...personKeys.all, "detail"] as const,
  detail: (familySlug: string, personId: string) =>
    [...personKeys.details(), familySlug, personId] as const,
  proposals: (familySlug: string, personId: string) =>
    [...personKeys.detail(familySlug, personId), "proposals"] as const,
};
