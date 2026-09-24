export type FamilySpaceRole =
  "owner" | "administrator" | "member" | "contributor" | "guest";

export type FamilySpace = {
  id: string;
  slug: string;
  name: string;
  status: "active" | "deletion_requested" | "deleting" | "deleted";
  role: FamilySpaceRole;
  current_user_person_id?: string | null;
  deletion?: {
    requested_at: string | null;
    scheduled_at: string | null;
  };
};

export type CreateFamilySpaceInput = Pick<FamilySpace, "name" | "slug">;

export type FamilyMembership = {
  id: string;
  user: { id: number; name: string; email: string };
  role: FamilySpaceRole;
  state: "active" | "removed";
  removed_at: string | null;
};
