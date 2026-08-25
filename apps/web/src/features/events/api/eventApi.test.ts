import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import {
  createEvent,
  admitEventMembership,
  deleteEvent,
  getDuplicateEventCandidates,
  getEvent,
  getEventAdmissions,
  getDeletedEvents,
  getEvents,
  getPersonEvents,
  updateEvent,
  revokeEventAdmission,
  restoreEvent,
} from "./eventApi";
import type { FamilyEvent } from "../types/event";

const apiBaseUrl = "http://localhost:8082";
const event: FamilyEvent = {
  id: "01K90000000000000000000000",
  name: "Summer picnic",
  description: null,
  starts_on: "2026-08-25",
  ends_on: null,
  location: "The park",
  status: "planned",
  created_by: 1,
  creator: { id: 1, name: "David" },
  permissions: {
    can_update: true,
    can_manage_admissions: true,
    can_review_duplicates: true,
    can_delete: true,
    can_restore: false,
    can_create_album: true,
  },
};

describe("eventApi", () => {
  it("owns and unwraps Event, duplicate and Person reverse endpoints", async () => {
    const base = `${apiBaseUrl}/api/families/family-archive/events`;
    const detail = `${base}/${event.id}`;
    const admission = {
      id: "01KC0000000000000000000000",
      membership_id: "01KD0000000000000000000000",
      user: { id: 2, name: "Guest", email: "guest@example.test" },
      role: "guest" as const,
      admitted_at: "2026-08-25T10:00:00Z",
      revoked_at: null,
      valid_until: "2026-09-24T10:00:00Z",
    };
    server.use(
      http.get(base, () => HttpResponse.json({ data: [event] })),
      http.get(detail, () => HttpResponse.json({ data: event })),
      http.get(`${base}/deleted`, () =>
        HttpResponse.json({
          data: [
            {
              ...event,
              permissions: { ...event.permissions, can_restore: true },
            },
          ],
        }),
      ),
      http.post(base, () =>
        HttpResponse.json({ data: event }, { status: 201 }),
      ),
      http.patch(detail, () =>
        HttpResponse.json({ data: { ...event, status: "active" } }),
      ),
      http.delete(detail, () => new HttpResponse(null, { status: 204 })),
      http.post(`${detail}/restore`, () => HttpResponse.json({ data: event })),
      http.get(`${detail}/duplicate-candidates`, () =>
        HttpResponse.json({ data: [event] }),
      ),
      http.get(`${detail}/admissions`, () =>
        HttpResponse.json({ data: [admission] }),
      ),
      http.post(`${detail}/admissions`, () =>
        HttpResponse.json({ data: admission }, { status: 201 }),
      ),
      http.delete(`${detail}/admissions/${admission.membership_id}`, () =>
        HttpResponse.json({
          data: { ...admission, revoked_at: "2026-08-25T11:00:00Z" },
        }),
      ),
      http.get(
        `${apiBaseUrl}/api/families/family-archive/people/person-1/events`,
        () => HttpResponse.json({ data: [event] }),
      ),
    );

    await expect(getEvents("family-archive")).resolves.toEqual([event]);
    await expect(getEvent("family-archive", event.id)).resolves.toEqual(event);
    await expect(getDeletedEvents("family-archive")).resolves.toHaveLength(1);
    await expect(
      createEvent("family-archive", { name: event.name }),
    ).resolves.toEqual(event);
    await expect(
      updateEvent("family-archive", event.id, { status: "active" }),
    ).resolves.toMatchObject({ status: "active" });
    await expect(
      deleteEvent("family-archive", event.id),
    ).resolves.toBeUndefined();
    await expect(restoreEvent("family-archive", event.id)).resolves.toEqual(
      event,
    );
    await expect(
      getDuplicateEventCandidates("family-archive", event.id),
    ).resolves.toEqual([event]);
    await expect(
      getPersonEvents("family-archive", "person-1"),
    ).resolves.toEqual([event]);
    await expect(
      getEventAdmissions("family-archive", event.id),
    ).resolves.toEqual([admission]);
    await expect(
      admitEventMembership("family-archive", event.id, admission.membership_id),
    ).resolves.toEqual(admission);
    await expect(
      revokeEventAdmission("family-archive", event.id, admission.membership_id),
    ).resolves.toMatchObject({ revoked_at: "2026-08-25T11:00:00Z" });
  });
});
