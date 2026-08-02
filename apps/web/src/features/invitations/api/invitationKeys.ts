export const invitationKeys = {
  all: ["invitations"] as const,
  list: () => [...invitationKeys.all, "list"] as const,
  claim: () => [...invitationKeys.all, "claim"] as const,
};
