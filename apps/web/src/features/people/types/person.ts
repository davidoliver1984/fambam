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
