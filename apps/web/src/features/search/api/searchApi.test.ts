import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import {
  createSavedSearch,
  deleteSavedSearch,
  getDiscovery,
  getSavedSearches,
  getSearchSuggestions,
  runSavedSearch,
  searchArchive,
  updateSavedSearch,
} from "./searchApi";

describe("searchApi", () => {
  it("owns relationship filters, suggestions and discovery endpoint contracts", async () => {
    const base = "http://localhost:8082/api/families/family-archive";
    server.use(
      http.get(`${base}/search`, ({ request }) => {
        const query = new URL(request.url).searchParams;
        expect(query.get("group")).toBe("photos");
        expect(query.getAll("person_ids[]")).toEqual(["person-1", "person-2"]);
        expect(query.get("event_id")).toBe("event-1");
        expect(query.get("album_id")).toBe("album-1");
        expect(query.get("tag_id")).toBe("tag-1");
        expect(query.get("visibility")).toBe("selected");

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
      http.get(`${base}/saved-searches`, () =>
        HttpResponse.json({
          data: [
            {
              id: "saved-1",
              name: "David memories",
              filters: { schema_version: 1, q: "family" },
              people: [],
            },
          ],
        }),
      ),
      http.post(`${base}/saved-searches`, () =>
        HttpResponse.json(
          {
            data: {
              id: "saved-1",
              name: "David memories",
              filters: { schema_version: 1, q: "family" },
              people: [],
            },
          },
          { status: 201 },
        ),
      ),
      http.put(`${base}/saved-searches/saved-1`, () =>
        HttpResponse.json({
          data: {
            id: "saved-1",
            name: "Renamed",
            filters: { schema_version: 1, q: "family" },
            people: [],
          },
        }),
      ),
      http.get(`${base}/saved-searches/saved-1/results`, () =>
        HttpResponse.json({
          data: { photos: { items: [], next_cursor: null } },
        }),
      ),
      http.delete(
        `${base}/saved-searches/saved-1`,
        () => new HttpResponse(null, { status: 204 }),
      ),
    );

    await expect(
      searchArchive(
        "family-archive",
        "photos",
        {
          person_ids: ["person-1", "person-2"],
          event_id: "event-1",
          album_id: "album-1",
          tag_id: "tag-1",
          visibility: "selected",
        },
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
    await expect(getSavedSearches("family-archive")).resolves.toHaveLength(1);
    await expect(
      createSavedSearch("family-archive", {
        name: "David memories",
        filters: { q: "family" },
      }),
    ).resolves.toMatchObject({ id: "saved-1" });
    await expect(
      updateSavedSearch("family-archive", "saved-1", {
        name: "Renamed",
        filters: { q: "family" },
      }),
    ).resolves.toMatchObject({ name: "Renamed" });
    await expect(
      runSavedSearch("family-archive", "saved-1", "photos", null),
    ).resolves.toEqual({ items: [], next_cursor: null });
    await expect(
      deleteSavedSearch("family-archive", "saved-1"),
    ).resolves.toBeUndefined();
  });
});
