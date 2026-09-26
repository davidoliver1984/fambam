import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import {
  addCollectionPhotos,
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
  created_at: "2026-09-26T09:00:00Z",
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
