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
  can_propose_relationships: boolean;
  can_manage_relationships: boolean;
  can_propose_merge: boolean;
  can_manage_merge: boolean;
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
  redirected_from_person_id: string | null;
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

export type RelationshipType =
  | "parent_of"
  | "partner_of"
  | "sibling_of"
  | "guardian_of"
  | "step_parent_of"
  | "grandparent_of"
  | "close_family_friend_of";

export type PersonRelationship = {
  id: string;
  subject_person_id: string;
  related_person_id: string;
  type: RelationshipType;
  status: "confirmed" | "disputed";
  label: string;
  other_person: { id: string; preferred_name: string };
  context: string | null;
};

export type RelationshipInput = {
  related_person_id: string;
  type: RelationshipType;
  context?: string | null;
};

export type RelationshipProposal = {
  id: string;
  action: "create" | "replace" | "remove" | "dispute";
  relationship_id: string | null;
  subject_person_id: string;
  related_person_id: string;
  type: RelationshipType | null;
  context: string | null;
  status: "pending" | "approved" | "rejected";
  created_at: string;
};

export type RelationshipProposalInput = {
  action: RelationshipProposal["action"];
  relationship_id?: string;
  related_person_id?: string;
  type?: RelationshipType;
  context?: string | null;
};

export type FamilyCircle = {
  id: string;
  name: string;
  description: string | null;
  people: Array<{ id: string; preferred_name: string }>;
};

export type FamilyCircleInput = { name: string; description?: string | null };

export type PersonMergeStatus =
  "active" | "reversed" | "manual_correction_required";

export type PersonMerge = {
  id: string;
  survivor: { id: string; preferred_name: string | null };
  absorbed: { id: string; preferred_name: string | null };
  status: PersonMergeStatus;
  merged_at: string;
  reversed_at: string | null;
};

export type PersonMergeProposal = {
  id: string;
  survivor: { id: string; preferred_name: string | null };
  absorbed: { id: string; preferred_name: string | null };
  context: string | null;
  status: "pending" | "approved" | "rejected";
  person_merge_id: string | null;
  created_at: string;
};

export type AccountLinkResolution =
  "keep_survivor" | "keep_absorbed" | "remove_both";

export type PersonMergeInput = {
  survivor_person_id: string;
  account_link_resolution?: AccountLinkResolution;
};

export type PersonMergeProposalInput = {
  survivor_person_id: string;
  context?: string | null;
};
