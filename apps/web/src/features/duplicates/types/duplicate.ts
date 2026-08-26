export type DuplicatePhotoSummary = {
  id: string;
  media_upload_id: string;
  caption: string | null;
  client_filename: string;
  visibility: "family_space" | "private";
  created_at: string;
  image_url: string;
};

export type DuplicateCandidate = {
  id: string;
  source: "exact" | "perceptual" | "member_flagged";
  algorithm: string | null;
  processing_version: number | null;
  score: number | null;
  photo: DuplicatePhotoSummary;
  candidate_photo: DuplicatePhotoSummary;
  created_at: string;
};

export type DuplicateDecision = {
  id: string;
  source:
    "exact_creation_choice" | "perceptual_review" | "member_flagged_review";
  photo: DuplicatePhotoSummary;
  candidate_photo: DuplicatePhotoSummary;
  decided_at: string;
};
