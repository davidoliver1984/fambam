export const invitationKeys = {
  all: ["invitations"] as const,
  list: () => [...invitationKeys.all, "list"] as const,
  claimExchange: () => [...invitationKeys.all, "claim-exchange"] as const,
};
