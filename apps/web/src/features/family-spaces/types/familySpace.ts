export type FamilySpaceRole =
  "owner" | "administrator" | "member" | "contributor" | "guest";

export type FamilySpaceDefaultVisibility = "family_space" | "private";

export type FamilySpace = {
  id: string;
  slug: string;
  name: string;
  description: string | null;
  default_visibility: FamilySpaceDefaultVisibility;
  status: "active" | "deletion_requested" | "deleting" | "deleted";
  role: FamilySpaceRole;
  permissions: {
    can_update_family_settings: boolean;
    can_manage_members: boolean;
    can_manage_invitations: boolean;
    can_transfer_ownership: boolean;
    can_leave_family: boolean;
  };
  current_user_person_id?: string | null;
  deletion?: {
    requested_at: string | null;
    scheduled_at: string | null;
  };
};

export type CreateFamilySpaceInput = Pick<FamilySpace, "name" | "slug">;

export type UpdateFamilySpaceInput = Partial<
  Pick<FamilySpace, "name" | "description" | "default_visibility">
>;

export type FamilyMembership = {
  id: string;
  user: { id: number; name: string; email: string };
  role: FamilySpaceRole;
  state: "active" | "removed";
  joined_at: string;
  removed_at: string | null;
  linked_person: {
    id: string;
    display_name: string;
    portrait_thumbnail_url: string | null;
  } | null;
  is_current_user: boolean;
};
