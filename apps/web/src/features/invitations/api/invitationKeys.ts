export const invitationKeys = {
  all: ["invitations"] as const,
  list: (familySpaceId: string) =>
    [...invitationKeys.all, "list", familySpaceId] as const,
  claimExchange: () => [...invitationKeys.all, "claim-exchange"] as const,
};
