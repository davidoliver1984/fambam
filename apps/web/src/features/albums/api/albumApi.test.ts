import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it, vi } from "vitest";

import { server } from "@/test/msw/server";

import { deleteAlbum, updateAlbum, uploadPhotoToAlbum } from "./albumApi";
import type { Album } from "../types/album";

const apiBaseUrl = "http://localhost:8082";

afterEach(() => vi.restoreAllMocks());

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

  it("stops an upload when current Album cover authority is not accepted", async () => {
    const objectUpload = vi.spyOn(globalThis, "fetch");
    server.use(
      http.get(`${apiBaseUrl}/sanctum/csrf-cookie`, () =>
        HttpResponse.json({}),
      ),
      http.post(
        `${apiBaseUrl}/api/families/mercer-family/albums/album-1/media-uploads`,
        async ({ request }) => {
          expect(await request.json()).toEqual({
            client_filename: "cover.jpg",
            client_mime_type: "image/jpeg",
            as_cover: true,
            cover_focal_x: 0.5,
            cover_focal_y: 0.5,
          });
          return HttpResponse.json(
            {
              data: {
                id: "01KUPLOAD00000000000000000",
                state: "initiated",
                client_filename: "cover.jpg",
                byte_size: null,
                uploaded_at: null,
                upload_batch_id: null,
                upload_authorization: {
                  url: "https://storage.test/staging-object",
                  method: "PUT",
                  headers: {},
                  expires_at: "2026-09-26T12:00:00Z",
                },
                target_album_id: "album-1",
                cover_intent_accepted: false,
                cover_intent_reason: "album_update_forbidden",
              },
            },
            { status: 201 },
          );
        },
      ),
    );

    await expect(
      uploadPhotoToAlbum(
        "mercer-family",
        "album-1",
        new File(["cover"], "cover.jpg", { type: "image/jpeg" }),
        true,
      ),
    ).rejects.toThrow("Album cover authority changed before upload.");
    expect(objectUpload).not.toHaveBeenCalled();
  });
});
