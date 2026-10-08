import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type {
  Person,
  PersonDetailsInput,
  PersonListCriteria,
  PersonListPage,
  PersonProposal,
  PersonProposalResolution,
} from "../types/person";

function peoplePath(familySlug: string): string {
  return `/api/families/${encodeURIComponent(familySlug)}/people`;
}

export async function getPeople(
  familySlug: string,
  criteria: PersonListCriteria = {},
  cursor: string | null = null,
  signal?: AbortSignal,
): Promise<PersonListPage> {
  return unwrap(
    await apiClient.get<ApiEnvelope<PersonListPage>>(peoplePath(familySlug), {
      params: {
        ...criteria,
        ...(cursor === null ? {} : { cursor }),
      },
      signal,
    }),
  );
}

export async function getPerson(
  familySlug: string,
  personId: string,
  signal?: AbortSignal,
): Promise<Person> {
  return unwrap(
    await apiClient.get<ApiEnvelope<Person>>(
      `${peoplePath(familySlug)}/${encodeURIComponent(personId)}`,
      { signal },
    ),
  );
}

export async function createPerson(
  familySlug: string,
  input: PersonDetailsInput,
): Promise<Person> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<Person>>(peoplePath(familySlug), input),
  );
}

export async function updatePerson(
  familySlug: string,
  personId: string,
  input: PersonDetailsInput,
): Promise<Person> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.patch<ApiEnvelope<Person>>(
      `${peoplePath(familySlug)}/${encodeURIComponent(personId)}`,
      input,
    ),
  );
}

export async function proposePersonDetails(
  familySlug: string,
  personId: string,
  input: PersonDetailsInput,
): Promise<PersonProposal> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<PersonProposal>>(
      `${peoplePath(familySlug)}/${encodeURIComponent(personId)}/proposals`,
      input,
    ),
  );
}

export async function getPersonProposals(
  familySlug: string,
  personId: string,
  signal?: AbortSignal,
): Promise<PersonProposal[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<PersonProposal[]>>(
      `${peoplePath(familySlug)}/${encodeURIComponent(personId)}/proposals`,
      { signal },
    ),
  );
}

export async function resolvePersonProposal(
  familySlug: string,
  personId: string,
  proposalId: string,
  resolution: PersonProposalResolution,
): Promise<PersonProposal> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<PersonProposal>>(
      `${peoplePath(familySlug)}/${encodeURIComponent(personId)}/proposals/${encodeURIComponent(proposalId)}/${resolution}`,
    ),
  );
}
