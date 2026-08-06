import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import {
  createPerson,
  getPeople,
  getPerson,
  getPersonProposals,
  proposePersonDetails,
  resolvePersonProposal,
  updatePerson,
} from "./personApi";
import type { Person, PersonDetailsInput } from "../types/person";

const apiBaseUrl = "http://localhost:8082";
const person: Person = {
  id: "01K30000000000000000000000",
  preferred_name: "Ada Oliver",
  alternate_names: ["Ada Smith"],
  identity_status: "confirmed",
  birth_date: { precision: "year", value: "1948" },
  is_deceased: false,
  death_date: { precision: "unknown", value: null },
  biography: null,
  account_link: null,
  created_at: "2026-08-06T10:00:00Z",
  updated_at: "2026-08-06T10:00:00Z",
  permissions: {
    can_update_authoritatively: true,
    can_propose_changes: true,
    can_resolve_proposals: true,
    can_propose_account_link: true,
    can_manage_account_link: true,
    can_propose_relationships: true,
    can_manage_relationships: true,
  },
};
const input: PersonDetailsInput = {
  preferred_name: person.preferred_name,
  alternate_names: person.alternate_names,
  birth_date: person.birth_date,
  is_deceased: person.is_deceased,
  death_date: person.death_date,
  biography: person.biography,
};

describe("personApi", () => {
  it("uses family-scoped typed endpoints for Person records", async () => {
    const requests: string[] = [];
    server.use(
      http.get(`${apiBaseUrl}/api/families/oliver-family/people`, () => {
        requests.push("list");
        return HttpResponse.json({ data: [person] });
      }),
      http.get(
        `${apiBaseUrl}/api/families/oliver-family/people/${person.id}`,
        () => {
          requests.push("show");
          return HttpResponse.json({ data: person });
        },
      ),
      http.post(`${apiBaseUrl}/api/families/oliver-family/people`, () => {
        requests.push("create");
        return HttpResponse.json({ data: person }, { status: 201 });
      }),
      http.patch(
        `${apiBaseUrl}/api/families/oliver-family/people/${person.id}`,
        () => {
          requests.push("update");
          return HttpResponse.json({ data: person });
        },
      ),
      http.post(
        `${apiBaseUrl}/api/families/oliver-family/people/${person.id}/proposals`,
        () => {
          requests.push("propose");
          return HttpResponse.json(
            {
              data: {
                id: "01K40000000000000000000000",
                person_id: person.id,
                changes: input,
                status: "pending",
                proposed_by: 2,
                resolved_by: null,
                resolved_at: null,
                created_at: "2026-08-06T10:00:00Z",
              },
            },
            { status: 201 },
          );
        },
      ),
      http.get(
        `${apiBaseUrl}/api/families/oliver-family/people/${person.id}/proposals`,
        () => {
          requests.push("proposals");
          return HttpResponse.json({ data: [] });
        },
      ),
      http.post(
        `${apiBaseUrl}/api/families/oliver-family/people/${person.id}/proposals/01K40000000000000000000000/approve`,
        () => {
          requests.push("approve");
          return HttpResponse.json({
            data: {
              id: "01K40000000000000000000000",
              person_id: person.id,
              changes: input,
              status: "approved",
              proposed_by: 2,
              resolved_by: 1,
              resolved_at: "2026-08-06T11:00:00Z",
              created_at: "2026-08-06T10:00:00Z",
            },
          });
        },
      ),
    );

    await expect(getPeople("oliver-family")).resolves.toEqual([person]);
    await expect(getPerson("oliver-family", person.id)).resolves.toEqual(
      person,
    );
    await expect(createPerson("oliver-family", input)).resolves.toEqual(person);
    await expect(
      updatePerson("oliver-family", person.id, input),
    ).resolves.toEqual(person);
    await expect(
      proposePersonDetails("oliver-family", person.id, input),
    ).resolves.toMatchObject({
      status: "pending",
    });
    await expect(
      getPersonProposals("oliver-family", person.id),
    ).resolves.toEqual([]);
    await expect(
      resolvePersonProposal(
        "oliver-family",
        person.id,
        "01K40000000000000000000000",
        "approve",
      ),
    ).resolves.toMatchObject({ status: "approved" });
    expect(requests).toEqual([
      "list",
      "show",
      "create",
      "update",
      "propose",
      "proposals",
      "approve",
    ]);
  });
});
