import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type {
  EventAdmission,
  EventExport,
  EventExportDownload,
  EventInput,
  FamilyEvent,
} from "../types/event";

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

export async function getDeletedEvents(
  familySlug: string,
  signal?: AbortSignal,
): Promise<FamilyEvent[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<FamilyEvent[]>>(
      `${base(familySlug)}/deleted`,
      { signal },
    ),
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

export async function deleteEvent(
  familySlug: string,
  eventId: string,
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.delete(`${base(familySlug)}/${encodeURIComponent(eventId)}`);
}

export async function restoreEvent(
  familySlug: string,
  eventId: string,
): Promise<FamilyEvent> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<FamilyEvent>>(
      `${base(familySlug)}/${encodeURIComponent(eventId)}/restore`,
    ),
  );
}

export async function getEventAdmissions(
  familySlug: string,
  eventId: string,
  signal?: AbortSignal,
): Promise<EventAdmission[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<EventAdmission[]>>(
      `${base(familySlug)}/${encodeURIComponent(eventId)}/admissions`,
      { signal },
    ),
  );
}

export async function admitEventMembership(
  familySlug: string,
  eventId: string,
  membershipId: string,
): Promise<EventAdmission> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<EventAdmission>>(
      `${base(familySlug)}/${encodeURIComponent(eventId)}/admissions`,
      { membership_id: membershipId },
    ),
  );
}

export async function revokeEventAdmission(
  familySlug: string,
  eventId: string,
  membershipId: string,
): Promise<EventAdmission> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.delete<ApiEnvelope<EventAdmission>>(
      `${base(familySlug)}/${encodeURIComponent(eventId)}/admissions/${encodeURIComponent(membershipId)}`,
    ),
  );
}

export async function getEventExports(
  familySlug: string,
  eventId: string,
  signal?: AbortSignal,
): Promise<EventExport[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<EventExport[]>>(
      `${base(familySlug)}/${encodeURIComponent(eventId)}/exports`,
      { signal },
    ),
  );
}

export async function requestEventExport(
  familySlug: string,
  eventId: string,
): Promise<EventExport> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<EventExport>>(
      `${base(familySlug)}/${encodeURIComponent(eventId)}/exports`,
    ),
  );
}

export async function authorizeEventExportDownload(
  familySlug: string,
  eventId: string,
  eventExportId: string,
): Promise<EventExportDownload> {
  return unwrap(
    await apiClient.get<ApiEnvelope<EventExportDownload>>(
      `${base(familySlug)}/${encodeURIComponent(eventId)}/exports/${encodeURIComponent(eventExportId)}/download`,
    ),
  );
}
