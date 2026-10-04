import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import {
  addCollectionPhotos,
  getCollections,
  reorderCollectionPhotos,
  requestCollectionExport,
  updateCollection,
} from "./collectionApi";

const apiBaseUrl = "http://localhost:8082";
const base = `${apiBaseUrl}/api/families/oliver-family/collections/collection-1`;
const collection = {
  id: "collection-1",
  name: "Family favourites",
  description: "Private notes",
  purpose: null,
  created_at: "2026-09-26T09:00:00Z",
  updated_at: "2026-09-26T10:00:00Z",
  photo_count: 0,
  preview_photo: null,
  photos: [],
};
const familyExport = {
  id: "01KEXPORT00000000000000000",
  scope: "collection",
  collection_id: collection.id,
  state: "pending",
  photo_count: null,
  byte_size: null,
  failure_reason: null,
  expires_at: null,
  created_at: "2026-09-26T10:00:00Z",
};

describe("collectionApi", () => {
  it("parses the canonical Collection index presentation", async () => {
    const indexed = {
      ...collection,
      purpose: "prints" as const,
      photo_count: 3,
      preview_photo: {
        photo_id: "photo-1",
        media_upload_id: "upload-1",
      },
    };
    server.use(
      http.get(`${apiBaseUrl}/api/families/oliver-family/collections`, () =>
        HttpResponse.json({ data: [indexed] }),
      ),
    );

    await expect(getCollections("oliver-family")).resolves.toEqual([indexed]);
  });

  it("sends canonical Collection index filters", async () => {
    let query = new URLSearchParams();
    server.use(
      http.get(
        `${apiBaseUrl}/api/families/oliver-family/collections`,
        ({ request }) => {
          query = new URL(request.url).searchParams;
          return HttpResponse.json({ data: [] });
        },
      ),
    );

    await getCollections("oliver-family", {
      q: "William",
      sort: "name",
      collection_id: "01KCOLLECTION00000000000000",
      purpose: ["prints", "calendar"],
    });

    expect(query.get("q")).toBe("William");
    expect(query.get("sort")).toBe("name");
    expect(query.get("collection_id")).toBe("01KCOLLECTION00000000000000");
    expect([...query.values()]).toEqual(
      expect.arrayContaining(["prints", "calendar"]),
    );
  });

  it("rejects malformed Collection preview payloads at runtime", async () => {
    server.use(
      http.get(`${apiBaseUrl}/api/families/oliver-family/collections`, () =>
        HttpResponse.json({
          data: [
            {
              ...collection,
              photo_count: 1,
              preview_photo: { photo_id: "photo-1" },
            },
          ],
        }),
      ),
    );

    await expect(getCollections("oliver-family")).rejects.toThrow();
  });

  it("rejects non-canonical Collection purposes at runtime", async () => {
    server.use(
      http.get(`${apiBaseUrl}/api/families/oliver-family/collections`, () =>
        HttpResponse.json({ data: [{ ...collection, purpose: "book" }] }),
      ),
    );

    await expect(getCollections("oliver-family")).rejects.toThrow();
  });

  it("uses the canonical update, reorder and atomic batch contracts", async () => {
    const requests: Array<{ method: string; path: string; body: unknown }> = [];
    const record = async ({ request }: { request: Request }) => {
      requests.push({
        method: request.method,
        path: new URL(request.url).pathname,
        body: await request.json(),
      });
      return HttpResponse.json({ data: collection });
    };
    server.use(
      http.get(`${apiBaseUrl}/sanctum/csrf-cookie`, () =>
        HttpResponse.json(null, { status: 204 }),
      ),
      http.patch(base, record),
      http.put(`${base}/order`, record),
      http.post(`${base}/photos/batch`, record),
    );

    await expect(
      updateCollection("oliver-family", collection.id, {
        name: "Family favourites",
        description: "Private notes",
      }),
    ).resolves.toEqual(collection);
    await expect(
      reorderCollectionPhotos("oliver-family", collection.id, [
        "photo-2",
        "photo-1",
      ]),
    ).resolves.toEqual(collection);
    await expect(
      addCollectionPhotos("oliver-family", collection.id, [
        "photo-1",
        "photo-2",
      ]),
    ).resolves.toEqual(collection);

    expect(requests).toEqual([
      {
        method: "PATCH",
        path: "/api/families/oliver-family/collections/collection-1",
        body: { name: "Family favourites", description: "Private notes" },
      },
      {
        method: "PUT",
        path: "/api/families/oliver-family/collections/collection-1/order",
        body: { photo_ids: ["photo-2", "photo-1"] },
      },
      {
        method: "POST",
        path: "/api/families/oliver-family/collections/collection-1/photos/batch",
        body: { photo_ids: ["photo-1", "photo-2"] },
      },
    ]);
  });

  it("returns the canonical FamilyExport lifecycle record", async () => {
    server.use(
      http.get(`${apiBaseUrl}/sanctum/csrf-cookie`, () =>
        HttpResponse.json(null, { status: 204 }),
      ),
      http.post(`${base}/exports`, () =>
        HttpResponse.json({ data: familyExport }, { status: 202 }),
      ),
    );

    await expect(
      requestCollectionExport("oliver-family", collection.id),
    ).resolves.toEqual(familyExport);
  });
});
