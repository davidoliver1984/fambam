export type DatePrecision =
  "exact" | "month" | "year" | "decade" | "approximate" | "unknown";

export type UncertainDate = {
  precision: DatePrecision;
  value: string | null;
};

export type PersonPermissions = {
  can_update_authoritatively: boolean;
  can_propose_changes: boolean;
  can_resolve_proposals: boolean;
  can_propose_account_link: boolean;
  can_manage_account_link: boolean;
};

export type PersonAccountLink = {
  id: string;
  person_id?: string;
  account: {
    id: number;
    name: string;
    is_current_user: boolean;
  };
  created_at?: string;
};

export type PersonAccountClaim = {
  id: string;
  person_id: string;
  account: { id: number; name: string };
  status: "pending" | "approved" | "rejected";
  resolved_at: string | null;
  created_at: string;
};

export type FamilyMembership = {
  id: string;
  user: { id: number; name: string; email: string };
  role: "owner" | "administrator" | "member" | "contributor" | "guest";
  state: "active" | "removed";
  removed_at: string | null;
};

export type Person = {
  id: string;
  preferred_name: string;
  alternate_names: string[];
  identity_status: "confirmed" | "provisional";
  birth_date: UncertainDate;
  is_deceased: boolean;
  death_date: UncertainDate;
  biography: string | null;
  account_link: PersonAccountLink | null;
  created_at: string;
  updated_at: string;
  permissions: PersonPermissions;
};

export type PersonDetailsInput = {
  preferred_name: string;
  alternate_names: string[];
  birth_date: UncertainDate;
  is_deceased: boolean;
  death_date: UncertainDate;
  biography: string | null;
};

export type PersonProposal = {
  id: string;
  person_id: string;
  changes: Partial<PersonDetailsInput>;
  status: "pending" | "approved" | "rejected";
  proposed_by: number | null;
  resolved_by: number | null;
  resolved_at: string | null;
  created_at: string;
};

export type PersonProposalResolution = "approve" | "reject";
