export type PhotoVisibility = "family_space" | "private";
export type PhotoFilters = {
  person_id?: string;
  tag?: string;
  location?: string;
  historical_year?: string;
  without_confirmed_date?: boolean;
};
export type DeletedPhoto = {
  id: string;
  caption: string | null;
  client_filename: string;
  deleted_at: string;
  permissions: { can_restore: boolean };
};
export type PromotableMediaUpload = {
  id: string;
  client_filename: string;
  byte_size: number | null;
  uploaded_at: string | null;
};
export type PhotoProvenanceRole = "photographer" | "scanner" | "physical_owner";
export type DatePrecision =
  "exact" | "month" | "year" | "decade" | "approximate" | "unknown";
export type UncertainDate = { precision: DatePrecision; value: string | null };

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
  primary_event_id?: string | null;
  primary_event?: { id: string; name: string; starts_on: string | null } | null;
  historical_date: UncertainDate | null;
  location_description: string | null;
  provenance: {
    photographer: PhotoClaim;
    scanner: PhotoClaim;
    physical_owner: PhotoClaim;
  };
  tags: Array<{ id: string; label: string }>;
  people: PhotoPerson[];
  created_at: string;
  updated_at: string;
  permissions: {
    can_update: boolean;
    can_propose_provenance: boolean;
    can_resolve_provenance: boolean;
    can_manage_tags: boolean;
    can_flag_duplicate: boolean;
  };
};

export type CreatePhotoInput = {
  media_upload_id: string;
  visibility: PhotoVisibility;
  caption: string | null;
  description: string | null;
  archive_source_description: string | null;
  tags: string[];
  duplicate_resolution?: "use_existing" | "create_new" | "cancel";
  existing_photo_id?: string;
  disclosed_photo_ids?: string[];
};

export type DuplicatePhotoCandidate = {
  id: string;
  caption: string | null;
  visibility: PhotoVisibility;
  client_filename: string;
  created_at: string;
};

export type CreatePhotoResult =
  | { outcome: "duplicate_detected"; candidates: DuplicatePhotoCandidate[] }
  | { outcome: "photo_created" | "existing_photo"; photo: Photo }
  | { outcome: "cancelled" };

export type MediaUploadDuplicateHold = {
  id: string;
  media_upload: { id: string; client_filename: string };
  target_album: {
    id: string;
    name: string;
    visibility: "private" | "selected" | "family_space";
  };
  detected_at: string;
  candidates: DuplicatePhotoCandidate[];
};

export type ResolveDuplicateHoldInput = {
  holdId: string;
  resolution: "use_existing" | "create_new" | "cancel";
  existing_photo_id?: string;
  disclosed_photo_ids?: string[];
  confirm_visibility_widening?: boolean;
};

export type UpdatePhotoInput = Omit<
  CreatePhotoInput,
  "media_upload_id" | "tags"
> & { primary_event_id?: string | null };

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

export type PhotoMetadataInput = {
  field: "historical_date" | "location";
  date?: UncertainDate;
  location_description?: string;
  clears_claim?: boolean;
};

export type PhotoMetadataProposal = {
  id: string;
  photo_id: string;
  field: "historical_date" | "location";
  date: UncertainDate | null;
  location_description: string | null;
  clears_claim: boolean;
  status: "pending" | "approved" | "rejected";
  proposed_by: number | null;
  resolved_by: number | null;
  resolved_at: string | null;
  created_at: string;
};

export type PhotoPerson = {
  id: string;
  photo_id: string;
  person: { id: string; preferred_name: string };
  proposal_source: string;
  status: "pending" | "approved" | "rejected";
  proposed_by: number | null;
  resolved_by: number | null;
  resolved_at: string | null;
  created_at: string;
};
