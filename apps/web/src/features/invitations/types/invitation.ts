export type Invitation = {
  id: number;
  email: string;
  status: string;
  expires_at: string;
  accepted_at: string | null;
  revoked_at: string | null;
  acceptable: boolean;
};

export type AcceptanceClaim = {
  claim_token: string;
  email: string;
  expires_at: string;
};

export type AcceptInvitationInput = {
  claim_token: string;
  name: string;
  password: string;
  password_confirmation: string;
  timezone: string;
};

export type AcceptedAccount = {
  id: number;
  email: string;
};

export type InvitationTransition = "resend" | "revoke";
