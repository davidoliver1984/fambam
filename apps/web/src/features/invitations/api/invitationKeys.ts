export const invitationKeys = {
  all: ["invitations"] as const,
  list: (familySlug: string) =>
    [...invitationKeys.all, "list", { familySlug }] as const,
  claimExchange: () => [...invitationKeys.all, "claim-exchange"] as const,
};
