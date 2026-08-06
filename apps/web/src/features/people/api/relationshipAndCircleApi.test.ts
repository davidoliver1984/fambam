import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import {
  addPersonToCircle,
  createFamilyCircle,
  deleteFamilyCircle,
  getFamilyCircles,
  updateFamilyCircle,
} from "./circleApi";
import {
  createRelationship,
  getRelationships,
  proposeRelationship,
  replaceRelationship,
} from "./relationshipApi";

const base = "http://localhost:8082/api/families/oliver-family";
const personId = "01K30000000000000000000000";
const otherId = "01K30000000000000000000001";

describe("relationship and circle API modules", () => {
  it("unwraps typed family-scoped relationship and proposal endpoints", async () => {
    const relationship = {
      id: "01K40000000000000000000000",
      subject_person_id: personId,
      related_person_id: otherId,
      type: "parent_of",
      status: "confirmed",
      label: "parent",
      other_person: { id: otherId, preferred_name: "Beth" },
      context: null,
    } as const;
    server.use(
      http.get(`${base}/people/${personId}/relationships`, () =>
        HttpResponse.json({ data: [relationship] }),
      ),
      http.post(`${base}/people/${personId}/relationships`, () =>
        HttpResponse.json({ data: relationship }, { status: 201 }),
      ),
      http.patch(`${base}/relationships/${relationship.id}`, () =>
        HttpResponse.json({ data: { ...relationship, type: "guardian_of" } }),
      ),
      http.post(`${base}/people/${personId}/relationship-proposals`, () =>
        HttpResponse.json(
          {
            data: {
              id: "01K50000000000000000000000",
              action: "create",
              relationship_id: null,
              subject_person_id: personId,
              related_person_id: otherId,
              type: "parent_of",
              context: null,
              status: "pending",
              created_at: "2026-08-06T10:00:00Z",
            },
          },
          { status: 201 },
        ),
      ),
    );

    await expect(getRelationships("oliver-family", personId)).resolves.toEqual([
      relationship,
    ]);
    await expect(
      createRelationship("oliver-family", personId, {
        related_person_id: otherId,
        type: "parent_of",
      }),
    ).resolves.toEqual(relationship);
    await expect(
      replaceRelationship("oliver-family", relationship.id, personId, {
        related_person_id: otherId,
        type: "guardian_of",
      }),
    ).resolves.toMatchObject({ type: "guardian_of" });
    await expect(
      proposeRelationship("oliver-family", personId, {
        action: "create",
        related_person_id: otherId,
        type: "parent_of",
      }),
    ).resolves.toMatchObject({ status: "pending" });
  });

  it("unwraps typed flat-circle endpoints", async () => {
    const circle = {
      id: "01K60000000000000000000000",
      name: "Wedding Group",
      description: null,
      people: [],
    };
    server.use(
      http.get(`${base}/circles`, () => HttpResponse.json({ data: [circle] })),
      http.post(`${base}/circles`, () =>
        HttpResponse.json({ data: circle }, { status: 201 }),
      ),
      http.patch(`${base}/circles/${circle.id}`, () =>
        HttpResponse.json({ data: { ...circle, name: "Renamed" } }),
      ),
      http.delete(
        `${base}/circles/${circle.id}`,
        () => new HttpResponse(null, { status: 204 }),
      ),
      http.post(`${base}/circles/${circle.id}/people`, () =>
        HttpResponse.json(
          {
            data: {
              ...circle,
              people: [{ id: personId, preferred_name: "Ada" }],
            },
          },
          { status: 201 },
        ),
      ),
    );

    await expect(getFamilyCircles("oliver-family")).resolves.toEqual([circle]);
    await expect(
      createFamilyCircle("oliver-family", { name: circle.name }),
    ).resolves.toEqual(circle);
    await expect(
      updateFamilyCircle("oliver-family", circle.id, { name: "Renamed" }),
    ).resolves.toMatchObject({ name: "Renamed" });
    await expect(
      addPersonToCircle("oliver-family", circle.id, personId),
    ).resolves.toMatchObject({ people: [{ id: personId }] });
    await expect(
      deleteFamilyCircle("oliver-family", circle.id),
    ).resolves.toBeUndefined();
  });
});
