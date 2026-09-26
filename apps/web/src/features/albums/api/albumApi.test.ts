import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import { deleteAlbum, updateAlbum } from "./albumApi";
import type { Album } from "../types/album";

const apiBaseUrl = "http://localhost:8082";

const album: Album = {
  id: "01K90000000000000000000000",
  name: "Summer memories",
  description: null,
  starts_on: "2026-08-01",
  ends_on: null,
  location: "Blackpool",
  tags: [],
  people: [],
  cover: null,
  cover_pending: false,
  visibility: "family_space",
  created_by: 1,
  creator: { id: 1, name: "David" },
  created_at: "2026-09-01T10:00:00+00:00",
  updated_at: "2026-09-02T10:00:00+00:00",
  photo_count: 0,
  event_id: null,
  event: null,
  guest_participation: "none",
  photos: [],
  grants: [],
  permissions: { can_manage: true, can_contribute: true },
};

describe("albumApi", () => {
  it("patches Album metadata and unwraps the updated contract", async () => {
    const path = `${apiBaseUrl}/api/families/family/albums/${album.id}`;
    server.use(
      http.patch(path, async ({ request }) => {
        expect(await request.json()).toEqual({
          location: "Glossop",
          tags: ["Family"],
          person_ids: ["01KP0000000000000000000000"],
        });
        return HttpResponse.json({
          data: { ...album, location: "Glossop" },
        });
      }),
    );

    await expect(
      updateAlbum("family", album.id, {
        location: "Glossop",
        tags: ["Family"],
        person_ids: ["01KP0000000000000000000000"],
      }),
    ).resolves.toMatchObject({ location: "Glossop" });
  });

  it("deletes an Album through the canonical endpoint", async () => {
    const path = `${apiBaseUrl}/api/families/family/albums/${album.id}`;
    server.use(
      http.delete(path, () => new HttpResponse(null, { status: 204 })),
    );

    await expect(deleteAlbum("family", album.id)).resolves.toBeUndefined();
  });

  it("surfaces update failures", async () => {
    const path = `${apiBaseUrl}/api/families/family/albums/${album.id}`;
    server.use(
      http.patch(path, () =>
        HttpResponse.json({ message: "Forbidden" }, { status: 403 }),
      ),
    );

    await expect(
      updateAlbum("family", album.id, { name: "Unavailable" }),
    ).rejects.toMatchObject({ response: { status: 403 } });
  });
});
