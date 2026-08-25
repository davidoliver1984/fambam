import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import {
  createEvent,
  getDuplicateEventCandidates,
  getEvent,
  getEvents,
  getPersonEvents,
  updateEvent,
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
  permissions: { can_update: true },
};

describe("eventApi", () => {
  it("owns and unwraps Event, duplicate and Person reverse endpoints", async () => {
    const base = `${apiBaseUrl}/api/families/family-archive/events`;
    const detail = `${base}/${event.id}`;
    server.use(
      http.get(base, () => HttpResponse.json({ data: [event] })),
      http.get(detail, () => HttpResponse.json({ data: event })),
      http.post(base, () =>
        HttpResponse.json({ data: event }, { status: 201 }),
      ),
      http.patch(detail, () =>
        HttpResponse.json({ data: { ...event, status: "active" } }),
      ),
      http.get(`${detail}/duplicate-candidates`, () =>
        HttpResponse.json({ data: [event] }),
      ),
      http.get(
        `${apiBaseUrl}/api/families/family-archive/people/person-1/events`,
        () => HttpResponse.json({ data: [event] }),
      ),
    );

    await expect(getEvents("family-archive")).resolves.toEqual([event]);
    await expect(getEvent("family-archive", event.id)).resolves.toEqual(event);
    await expect(
      createEvent("family-archive", { name: event.name }),
    ).resolves.toEqual(event);
    await expect(
      updateEvent("family-archive", event.id, { status: "active" }),
    ).resolves.toMatchObject({ status: "active" });
    await expect(
      getDuplicateEventCandidates("family-archive", event.id),
    ).resolves.toEqual([event]);
    await expect(
      getPersonEvents("family-archive", "person-1"),
    ).resolves.toEqual([event]);
  });
});
