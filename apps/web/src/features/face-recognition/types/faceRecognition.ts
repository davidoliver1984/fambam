export type FaceBounds = {
  x: number;
  y: number;
  width: number;
  height: number;
};

export type FaceObservationSummary = {
  id: string;
  face_index: number;
  bounds: FaceBounds;
  photo_id?: string | null;
  media_upload_id: string;
  image_width: number | null;
  image_height: number | null;
  image_url: string;
};

export type FacePersonSummary = {
  id: string;
  preferred_name: string;
};

export type FaceIdentityAssignment = {
  id: string;
  status: "pending" | "approved" | "rejected" | "withdrawn" | "superseded";
  proposal_source: string;
  person: FacePersonSummary;
  observation: FaceObservationSummary;
  created_at: string;
};

export type FaceIdentitySuppression = {
  id: string;
  person: FacePersonSummary;
  observation: FaceObservationSummary;
  decided_at: string;
};

export type FaceClusterMember = {
  id: string;
  observation: FaceObservationSummary;
};

export type FaceCluster = {
  id: string;
  generation_id: string;
  status: "active";
  members: FaceClusterMember[];
};

export type FaceClusterIndex = {
  clusters: FaceCluster[];
  recognition_processing_enabled: boolean;
};

export type NameFaceClusterResult = {
  cluster_id: string;
  assignment_count: number;
  status: "proposed" | "confirmed";
};

export type FaceSuggestionPreview = {
  observation_id: string;
  band: "strong" | "shortlist" | "none";
  assignment_id: string | null;
  candidates: FacePersonSummary[];
};

export type FaceReviewAnalysisState =
  "pending" | "processing" | "succeeded" | "failed";

export type FaceReviewAnalysisPresentationState =
  | "pending"
  | "processing"
  | "succeeded_with_zero_faces"
  | "succeeded_with_unresolved_faces"
  | "succeeded_with_all_faces_resolved"
  | "failed";

export type FaceReviewAssignmentPresentation = {
  id: string;
  status: "pending" | "approved";
  proposal_source: string;
  person: FacePersonSummary;
};

export type FaceReviewObservation = {
  id: string;
  face_index: number;
  bounds: FaceBounds;
  review_state:
    | "unreviewed"
    | "automatic_suggestion"
    | "human_proposal"
    | "approved_identity"
    | "left_unidentified";
  reviewed: boolean;
  suggested_people: FacePersonSummary[];
  current_proposal: FaceReviewAssignmentPresentation | null;
  current_identity: FaceReviewAssignmentPresentation | null;
  identity_assignment: FaceReviewAssignmentPresentation | null;
  permissions: {
    can_assign: boolean;
    can_change: boolean;
    can_leave_unidentified: boolean;
    can_approve: boolean;
    can_reject: boolean;
  };
};

export type FaceReviewPhoto = {
  photo_id: string;
  upload_batch_id: string | null;
  caption: string | null;
  display_label: string;
  media: {
    media_upload_id: string;
    canonical_width: number | null;
    canonical_height: number | null;
    presentation_width: number | null;
    presentation_height: number | null;
    presentation_url: string | null;
    presentation_expires_at: string | null;
    fallback_delivery_endpoint: string;
  };
  analysis: {
    state: FaceReviewAnalysisState;
    review_state: FaceReviewAnalysisPresentationState;
    succeeded_with_zero_faces: boolean;
  };
  detected_face_count: number;
  reviewed_count: number;
  remaining_count: number;
  observations: FaceReviewObservation[];
};

export type FaceReviewSession = {
  scope: { upload_batch_id: string | null; photo_id: string | null };
  summary: {
    total_photos: number;
    analysis: Record<FaceReviewAnalysisState, number> & {
      succeeded_with_zero_faces: number;
      succeeded_with_unresolved_faces: number;
      succeeded_with_all_faces_resolved: number;
    };
    total_faces: number;
    reviewed_count: number;
    remaining_count: number;
    reviewable_photo_count: number;
    current_photo_id: string | null;
    next_photo_id: string | null;
  };
  photos: FaceReviewPhoto[];
  pagination: { page: number; limit: number; has_more: boolean };
};

export type FaceReviewFilters = {
  uploadBatchId?: string;
  photoId?: string;
  limit?: number;
  page?: number;
};

export type LeaveFaceUnidentifiedResult = {
  observation_id: string;
  review_state: "left_unidentified";
  reviewed_at: string;
};
