import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type { EventInput, FamilyEvent } from "../types/event";

const base = (familySlug: string) =>
  `/api/families/${encodeURIComponent(familySlug)}/events`;

export async function getEvents(
  familySlug: string,
  signal?: AbortSignal,
): Promise<FamilyEvent[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<FamilyEvent[]>>(base(familySlug), {
      signal,
    }),
  );
}

export async function getEvent(
  familySlug: string,
  eventId: string,
  signal?: AbortSignal,
): Promise<FamilyEvent> {
  return unwrap(
    await apiClient.get<ApiEnvelope<FamilyEvent>>(
      `${base(familySlug)}/${encodeURIComponent(eventId)}`,
      { signal },
    ),
  );
}

export async function getDuplicateEventCandidates(
  familySlug: string,
  eventId: string,
  signal?: AbortSignal,
): Promise<FamilyEvent[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<FamilyEvent[]>>(
      `${base(familySlug)}/${encodeURIComponent(eventId)}/duplicate-candidates`,
      { signal },
    ),
  );
}

export async function getPersonEvents(
  familySlug: string,
  personId: string,
  signal?: AbortSignal,
): Promise<FamilyEvent[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<FamilyEvent[]>>(
      `/api/families/${encodeURIComponent(familySlug)}/people/${encodeURIComponent(personId)}/events`,
      { signal },
    ),
  );
}

export async function createEvent(
  familySlug: string,
  input: EventInput,
): Promise<FamilyEvent> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<FamilyEvent>>(base(familySlug), input),
  );
}

export async function updateEvent(
  familySlug: string,
  eventId: string,
  input: Partial<EventInput>,
): Promise<FamilyEvent> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.patch<ApiEnvelope<FamilyEvent>>(
      `${base(familySlug)}/${encodeURIComponent(eventId)}`,
      input,
    ),
  );
}
