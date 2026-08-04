export type Invitation = {
  id: number;
  family_space_id: string;
  email: string;
  role: "administrator" | "member" | "contributor" | "guest";
  status: string;
  expires_at: string;
  accepted_at: string | null;
  revoked_at: string | null;
  acceptable: boolean;
};

export type AcceptanceClaim = {
  claim_token: string;
  email: string;
  family_space_name: string;
  role: Invitation["role"];
  existing_account: boolean;
  expires_at: string;
};

export type NewAccountInvitationInput = {
  claim_token: string;
  name: string;
  password: string;
  password_confirmation: string;
  timezone: string;
};

export type AcceptInvitationInput =
  NewAccountInvitationInput | { claim_token: string };

export type IssueInvitationInput = {
  email: string;
  role: Invitation["role"];
};

export type AcceptedAccount = {
  id: number;
  email: string;
};

export type InvitationTransition = "resend" | "revoke";
