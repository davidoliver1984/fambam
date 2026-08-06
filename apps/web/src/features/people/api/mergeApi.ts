import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type {
  AccountLinkResolution,
  PersonMerge,
  PersonMergeInput,
  PersonMergeProposal,
  PersonMergeProposalInput,
} from "../types/person";

function personPath(familySlug: string, personId: string): string {
  return `/api/families/${encodeURIComponent(familySlug)}/people/${encodeURIComponent(personId)}`;
}

export async function getPersonMerges(
  familySlug: string,
  personId: string,
  signal?: AbortSignal,
): Promise<PersonMerge[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<PersonMerge[]>>(
      `${personPath(familySlug, personId)}/merges`,
      { signal },
    ),
  );
}

export async function getPersonMergeProposals(
  familySlug: string,
  personId: string,
  signal?: AbortSignal,
): Promise<PersonMergeProposal[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<PersonMergeProposal[]>>(
      `${personPath(familySlug, personId)}/merge-proposals`,
      { signal },
    ),
  );
}

export async function mergePerson(
  familySlug: string,
  absorbedPersonId: string,
  input: PersonMergeInput,
): Promise<PersonMerge> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<PersonMerge>>(
      `${personPath(familySlug, absorbedPersonId)}/merge`,
      input,
    ),
  );
}

export async function proposePersonMerge(
  familySlug: string,
  absorbedPersonId: string,
  input: PersonMergeProposalInput,
): Promise<PersonMergeProposal> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<PersonMergeProposal>>(
      `${personPath(familySlug, absorbedPersonId)}/merge-proposals`,
      input,
    ),
  );
}

export async function resolvePersonMergeProposal(
  familySlug: string,
  personId: string,
  proposalId: string,
  resolution: "approve" | "reject",
  accountLinkResolution?: AccountLinkResolution,
): Promise<PersonMergeProposal> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<PersonMergeProposal>>(
      `${personPath(familySlug, personId)}/merge-proposals/${encodeURIComponent(proposalId)}/${resolution}`,
      accountLinkResolution
        ? { account_link_resolution: accountLinkResolution }
        : {},
    ),
  );
}

export async function reversePersonMerge(
  familySlug: string,
  mergeId: string,
): Promise<PersonMerge> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<PersonMerge>>(
      `/api/families/${encodeURIComponent(familySlug)}/person-merges/${encodeURIComponent(mergeId)}/reverse`,
    ),
  );
}
