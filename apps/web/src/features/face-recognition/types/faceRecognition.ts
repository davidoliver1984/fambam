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
  status: "pending" | "approved" | "rejected" | "withdrawn";
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
