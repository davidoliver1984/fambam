import { apiClient, apiUrl, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type {
  FaceCluster,
  FaceClusterIndex,
  FaceIdentityAssignment,
  FaceIdentitySuppression,
  FaceObservationSummary,
  FaceSuggestionPreview,
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
