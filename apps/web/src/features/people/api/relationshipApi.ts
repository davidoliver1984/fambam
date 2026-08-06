import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type {
  PersonRelationship,
  RelationshipInput,
  RelationshipProposal,
  RelationshipProposalInput,
} from "../types/person";

function personRelationshipPath(familySlug: string, personId: string) {
  return `/api/families/${encodeURIComponent(familySlug)}/people/${encodeURIComponent(personId)}`;
}

export async function getRelationships(
  familySlug: string,
  personId: string,
  signal?: AbortSignal,
): Promise<PersonRelationship[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<PersonRelationship[]>>(
      `${personRelationshipPath(familySlug, personId)}/relationships`,
      { signal },
    ),
  );
}

export async function createRelationship(
  familySlug: string,
  personId: string,
  input: RelationshipInput,
): Promise<PersonRelationship> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<PersonRelationship>>(
      `${personRelationshipPath(familySlug, personId)}/relationships`,
      input,
    ),
  );
}

export async function replaceRelationship(
  familySlug: string,
  relationshipId: string,
  personId: string,
  input: RelationshipInput,
): Promise<PersonRelationship> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.patch<ApiEnvelope<PersonRelationship>>(
      `/api/families/${encodeURIComponent(familySlug)}/relationships/${encodeURIComponent(relationshipId)}`,
      { subject_person_id: personId, ...input },
    ),
  );
}

export async function proposeRelationship(
  familySlug: string,
  personId: string,
  input: RelationshipProposalInput,
): Promise<RelationshipProposal> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<RelationshipProposal>>(
      `${personRelationshipPath(familySlug, personId)}/relationship-proposals`,
      input,
    ),
  );
}

export async function getRelationshipProposals(
  familySlug: string,
  personId: string,
  signal?: AbortSignal,
): Promise<RelationshipProposal[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<RelationshipProposal[]>>(
      `${personRelationshipPath(familySlug, personId)}/relationship-proposals`,
      { signal },
    ),
  );
}

export async function resolveRelationshipProposal(
  familySlug: string,
  personId: string,
  proposalId: string,
  resolution: "approve" | "reject",
): Promise<RelationshipProposal> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<RelationshipProposal>>(
      `${personRelationshipPath(familySlug, personId)}/relationship-proposals/${encodeURIComponent(proposalId)}/${resolution}`,
    ),
  );
}

export async function removeRelationship(
  familySlug: string,
  relationshipId: string,
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.delete(
    `/api/families/${encodeURIComponent(familySlug)}/relationships/${encodeURIComponent(relationshipId)}`,
  );
}

export async function disputeRelationship(
  familySlug: string,
  relationshipId: string,
): Promise<PersonRelationship> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<PersonRelationship>>(
      `/api/families/${encodeURIComponent(familySlug)}/relationships/${encodeURIComponent(relationshipId)}/dispute`,
    ),
  );
}
