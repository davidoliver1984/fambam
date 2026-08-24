export type PhotoVisibility = "family_space" | "private";
export type PhotoProvenanceRole = "photographer" | "scanner" | "physical_owner";

export type PhotoClaim = {
  person: { id: string; preferred_name: string } | null;
  description: string | null;
};

export type Photo = {
  id: string;
  media_upload: {
    id: string;
    client_filename: string;
    uploader: { id: number; name: string } | null;
  };
  created_by: number | null;
  visibility: PhotoVisibility;
  caption: string | null;
  description: string | null;
  archive_source_description: string | null;
  provenance: {
    photographer: PhotoClaim;
    scanner: PhotoClaim;
    physical_owner: PhotoClaim;
  };
  tags: Array<{ id: string; label: string }>;
  created_at: string;
  updated_at: string;
  permissions: {
    can_update: boolean;
    can_propose_provenance: boolean;
    can_resolve_provenance: boolean;
    can_manage_tags: boolean;
  };
};

export type CreatePhotoInput = {
  media_upload_id: string;
  visibility: PhotoVisibility;
  caption: string | null;
  description: string | null;
  archive_source_description: string | null;
  tags: string[];
};

export type UpdatePhotoInput = Omit<
  CreatePhotoInput,
  "media_upload_id" | "tags"
>;

export type PhotoProvenanceInput = {
  role: PhotoProvenanceRole;
  person_id?: string | null;
  description?: string | null;
  clears_claim?: boolean;
};

export type PhotoProvenanceProposal = {
  id: string;
  photo_id: string;
  role: PhotoProvenanceRole;
  person: { id: string; preferred_name: string } | null;
  description: string | null;
  clears_claim: boolean;
  status: "pending" | "approved" | "rejected";
  proposed_by: number | null;
  resolved_by: number | null;
  resolved_at: string | null;
  created_at: string;
};

export type PhotoProposalResolution = "approve" | "reject";
