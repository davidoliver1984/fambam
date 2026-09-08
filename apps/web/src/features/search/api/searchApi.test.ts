import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import { getDiscovery, getSearchSuggestions, searchArchive } from "./searchApi";

describe("searchApi", () => {
  it("owns relationship filters, suggestions and discovery endpoint contracts", async () => {
    const base = "http://localhost:8082/api/families/family-archive";
    server.use(
      http.get(`${base}/search`, ({ request }) => {
        const query = new URL(request.url).searchParams;
        expect(query.get("group")).toBe("photos");
        expect(query.getAll("person_ids[]")).toEqual(["person-1", "person-2"]);
        expect(query.get("event_id")).toBe("event-1");

        return HttpResponse.json({
          data: { photos: { items: [], next_cursor: null } },
        });
      }),
      http.get(`${base}/search/suggestions`, ({ request }) => {
        const query = new URL(request.url).searchParams;
        expect(query.get("type")).toBe("people");
        expect(query.get("prefix")).toBe("Dav");

        return HttpResponse.json({
          data: [{ id: "person-1", label: "David" }],
        });
      }),
      http.get(`${base}/discover/people/person-1`, () =>
        HttpResponse.json({
          data: {
            source: { type: "people", id: "person-1" },
            related: { photos: [] },
          },
        }),
      ),
    );

    await expect(
      searchArchive(
        "family-archive",
        "photos",
        { person_ids: ["person-1", "person-2"], event_id: "event-1" },
        null,
      ),
    ).resolves.toEqual({ items: [], next_cursor: null });
    await expect(
      getSearchSuggestions("family-archive", "people", "Dav"),
    ).resolves.toEqual([{ id: "person-1", label: "David" }]);
    await expect(
      getDiscovery("family-archive", "people", "person-1"),
    ).resolves.toMatchObject({
      source: { type: "people", id: "person-1" },
    });
  });
});
