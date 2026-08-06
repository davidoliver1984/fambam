export const personKeys = {
  all: ["people"] as const,
  lists: () => [...personKeys.all, "list"] as const,
  list: (familySlug: string) => [...personKeys.lists(), familySlug] as const,
  details: () => [...personKeys.all, "detail"] as const,
  detail: (familySlug: string, personId: string) =>
    [...personKeys.details(), familySlug, personId] as const,
  proposals: (familySlug: string, personId: string) =>
    [...personKeys.detail(familySlug, personId), "proposals"] as const,
  accountClaims: (familySlug: string, personId: string) =>
    [
      ...personKeys.detail(familySlug, personId),
      "account-link-claims",
    ] as const,
  memberships: (familySlug: string) =>
    [...personKeys.all, "memberships", familySlug] as const,
  relationships: (familySlug: string, personId: string) =>
    [...personKeys.detail(familySlug, personId), "relationships"] as const,
  relationshipProposals: (familySlug: string, personId: string) =>
    [...personKeys.relationships(familySlug, personId), "proposals"] as const,
  merges: (familySlug: string, personId: string) =>
    [...personKeys.detail(familySlug, personId), "merges"] as const,
  mergeProposals: (familySlug: string, personId: string) =>
    [...personKeys.merges(familySlug, personId), "proposals"] as const,
  circles: (familySlug: string) =>
    [...personKeys.all, "circles", familySlug] as const,
};
