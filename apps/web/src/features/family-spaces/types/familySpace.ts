export type FamilySpaceRole =
  "owner" | "administrator" | "member" | "contributor" | "guest";

export type FamilySpace = {
  id: string;
  slug: string;
  name: string;
  status: "active" | "deletion_requested" | "deleting" | "deleted";
  role: FamilySpaceRole;
  deletion?: {
    requested_at: string | null;
    scheduled_at: string | null;
  };
};

export type CreateFamilySpaceInput = Pick<FamilySpace, "name" | "slug">;
