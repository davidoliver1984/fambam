import { apiClient, apiUrl, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type {
  FaceCluster,
  FaceClusterIndex,
  FaceIdentityAssignment,
  FaceIdentitySuppression,
  FaceObservationSummary,
  FaceSuggestionPreview,
  FaceReviewFilters,
  FaceReviewSession,
  LeaveFaceUnidentifiedResult,
  NameFaceClusterResult,
} from "../types/faceRecognition";

type WireObservation = Omit<FaceObservationSummary, "image_url">;
type WireAssignment = Omit<FaceIdentityAssignment, "observation"> & {
  observation: WireObservation;
};
type WireSuppression = Omit<FaceIdentitySuppression, "observation"> & {
  observation: WireObservation;
};
type WireCluster = Omit<FaceCluster, "members"> & {
  members: Array<{
    id: string;
    observation: WireObservation;
  }>;
};

function base(familySlug: string): string {
  return `/api/families/${encodeURIComponent(familySlug)}`;
}

const observationReviewStates = new Set([
  "unreviewed",
  "automatic_suggestion",
  "human_proposal",
  "approved_identity",
  "left_unidentified",
]);
const analysisReviewStates = new Set([
  "pending",
  "processing",
  "succeeded_with_zero_faces",
  "succeeded_with_unresolved_faces",
  "succeeded_with_all_faces_resolved",
  "failed",
]);

export function parseFaceReviewSession(
  value: FaceReviewSession,
  filters: FaceReviewFilters = {},
): FaceReviewSession {
  if (filters.photoId !== undefined) {
    if (
      value.scope.photo_id !== filters.photoId ||
      value.scope.upload_batch_id !== null ||
      value.scope.person_id !== null ||
      value.photos.some((photo) => photo.photo_id !== filters.photoId) ||
      value.photos.length > 1 ||
      value.summary.next_photo_id !== null ||
      value.pagination.has_more
    ) {
      throw new Error("Photo-scoped face review escaped its requested Photo.");
    }
  }
  if (filters.uploadBatchId !== undefined) {
    if (
      value.scope.upload_batch_id !== filters.uploadBatchId ||
      value.scope.photo_id !== null ||
      value.scope.person_id !== null ||
      value.photos.some(
        (photo) => photo.upload_batch_id !== filters.uploadBatchId,
      )
    ) {
      throw new Error("Batch-scoped face review escaped its upload batch.");
    }
  }
  if (filters.personId !== undefined) {
    if (
      value.scope.person_id !== filters.personId ||
      value.scope.photo_id !== null ||
      value.scope.upload_batch_id !== null ||
      value.photos.some(
        (photo) =>
          !photo.observations.some(
            (observation) =>
              observation.review_state === "approved_identity" &&
              observation.current_identity?.person.id === filters.personId,
          ),
      )
    ) {
      throw new Error(
        "Person-scoped face review escaped its requested Person.",
      );
    }
  }
  for (const photo of value.photos) {
    if (!analysisReviewStates.has(photo.analysis.review_state)) {
      throw new Error("Unsupported face-review analysis state.");
    }
    for (const face of photo.observations) {
      if (!observationReviewStates.has(face.review_state)) {
        throw new Error("Unsupported face observation review state.");
      }
      const reviewed =
        face.review_state === "human_proposal" ||
        face.review_state === "approved_identity" ||
        face.review_state === "left_unidentified";
      if (face.reviewed !== reviewed) {
        throw new Error("Inconsistent face observation review progress.");
      }
    }
  }
  return value;
}

function observation(
  familySlug: string,
  value: WireObservation,
): FaceObservationSummary {
  return {
    ...value,
    image_url: apiUrl(
      `${base(familySlug)}/media-uploads/${encodeURIComponent(value.media_upload_id)}/canonical`,
    ),
  };
}

function assignment(
  familySlug: string,
  value: WireAssignment,
): FaceIdentityAssignment {
  return { ...value, observation: observation(familySlug, value.observation) };
}

function suppression(
  familySlug: string,
  value: WireSuppression,
): FaceIdentitySuppression {
  return { ...value, observation: observation(familySlug, value.observation) };
}

function cluster(familySlug: string, value: WireCluster): FaceCluster {
  return {
    ...value,
    members: value.members.map((member) => ({
      ...member,
      observation: observation(familySlug, member.observation),
    })),
  };
}

export async function getFaceIdentityAssignments(
  familySlug: string,
  signal?: AbortSignal,
): Promise<FaceIdentityAssignment[]> {
  const values = unwrap(
    await apiClient.get<ApiEnvelope<WireAssignment[]>>(
      `${base(familySlug)}/face-identity-assignments`,
      { signal },
    ),
  );
  return values.map((value) => assignment(familySlug, value));
}

export async function proposeFaceIdentity(
  familySlug: string,
  faceObservationId: string,
  personId: string,
): Promise<FaceIdentityAssignment> {
  await ensureCsrfCookie();
  return assignment(
    familySlug,
    unwrap(
      await apiClient.post<ApiEnvelope<WireAssignment>>(
        `${base(familySlug)}/face-observations/${encodeURIComponent(faceObservationId)}/identity-assignments`,
        { person_id: personId },
      ),
    ),
  );
}

export async function generateFaceIdentitySuggestions(
  familySlug: string,
  faceObservationId: string,
): Promise<FaceSuggestionPreview> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<FaceSuggestionPreview>>(
      `${base(familySlug)}/face-observations/${encodeURIComponent(faceObservationId)}/identity-suggestions`,
    ),
  );
}

export async function approveFaceIdentityAssignment(
  familySlug: string,
  assignmentId: string,
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.post(
    `${base(familySlug)}/face-identity-assignments/${encodeURIComponent(assignmentId)}/approve`,
  );
}

export async function rejectFaceIdentityAssignment(
  familySlug: string,
  assignmentId: string,
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.post(
    `${base(familySlug)}/face-identity-assignments/${encodeURIComponent(assignmentId)}/reject`,
  );
}

export async function getFaceIdentitySuppressions(
  familySlug: string,
  signal?: AbortSignal,
): Promise<FaceIdentitySuppression[]> {
  const values = unwrap(
    await apiClient.get<ApiEnvelope<WireSuppression[]>>(
      `${base(familySlug)}/face-identity-suppressions`,
      { signal },
    ),
  );
  return values.map((value) => suppression(familySlug, value));
}

export async function reopenFaceIdentitySuppression(
  familySlug: string,
  suppressionId: string,
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.post(
    `${base(familySlug)}/face-identity-suppressions/${encodeURIComponent(suppressionId)}/reopen`,
  );
}

export async function getFaceClusters(
  familySlug: string,
  signal?: AbortSignal,
): Promise<FaceClusterIndex> {
  const response = await apiClient.get<
    ApiEnvelope<WireCluster[]> & { recognition_processing_enabled: boolean }
  >(`${base(familySlug)}/face-clusters`, { signal });
  return {
    clusters: unwrap(response).map((value) => cluster(familySlug, value)),
    recognition_processing_enabled:
      response.data.recognition_processing_enabled,
  };
}

export async function nameFaceCluster(
  familySlug: string,
  clusterId: string,
  personId: string,
  confirm: boolean,
): Promise<NameFaceClusterResult> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<NameFaceClusterResult>>(
      `${base(familySlug)}/face-clusters/${encodeURIComponent(clusterId)}/name`,
      { person_id: personId, confirm },
    ),
  );
}

export async function mergeFaceClusters(
  familySlug: string,
  clusterIds: string[],
): Promise<FaceCluster> {
  await ensureCsrfCookie();
  return cluster(
    familySlug,
    unwrap(
      await apiClient.post<ApiEnvelope<WireCluster>>(
        `${base(familySlug)}/face-clusters/merge`,
        { cluster_ids: clusterIds },
      ),
    ),
  );
}

export async function splitFaceCluster(
  familySlug: string,
  clusterId: string,
  groups: string[][],
): Promise<FaceCluster[]> {
  await ensureCsrfCookie();
  const values = unwrap(
    await apiClient.post<ApiEnvelope<WireCluster[]>>(
      `${base(familySlug)}/face-clusters/${encodeURIComponent(clusterId)}/split`,
      { groups },
    ),
  );
  return values.map((value) => cluster(familySlug, value));
}

export async function getFaceReview(
  familySlug: string,
  filters: FaceReviewFilters = {},
  signal?: AbortSignal,
): Promise<FaceReviewSession> {
  const query = new URLSearchParams();
  if (filters.uploadBatchId !== undefined) {
    query.set("upload_batch_id", filters.uploadBatchId);
  }
  if (filters.photoId !== undefined) query.set("photo_id", filters.photoId);
  if (filters.personId !== undefined) query.set("person_id", filters.personId);
  if (filters.limit !== undefined) query.set("limit", String(filters.limit));
  if (filters.page !== undefined) query.set("page", String(filters.page));
  const suffix = query.size === 0 ? "" : `?${query.toString()}`;

  return parseFaceReviewSession(
    unwrap(
      await apiClient.get<ApiEnvelope<FaceReviewSession>>(
        `${base(familySlug)}/face-review${suffix}`,
        { signal },
      ),
    ),
    filters,
  );
}

export async function leaveFaceUnidentified(
  familySlug: string,
  faceObservationId: string,
): Promise<LeaveFaceUnidentifiedResult> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.put<ApiEnvelope<LeaveFaceUnidentifiedResult>>(
      `${base(familySlug)}/face-observations/${encodeURIComponent(faceObservationId)}/review/left-unidentified`,
    ),
  );
}
