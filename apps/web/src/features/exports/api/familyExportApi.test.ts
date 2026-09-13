import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import {
  authorizeFamilyExportDownload,
  getFamilyExports,
  requestFullFamilyExport,
  requestPersonalFamilyExport,
} from "./familyExportApi";

const apiBaseUrl = "http://localhost:8082";
const base = `${apiBaseUrl}/api/families/oliver-family/exports`;
const familyExport = {
  id: "01KEXPORT00000000000000000",
  scope: "family_space_full",
  state: "ready",
  photo_count: 12,
  byte_size: 4096,
  failure_reason: null,
  expires_at: "2026-09-11T12:00:00Z",
  created_at: "2026-09-10T12:00:00Z",
};

describe("familyExportApi", () => {
  it("lists, requests and authorises a full Family Space export", async () => {
    server.use(
      http.get(base, () => HttpResponse.json({ data: [familyExport] })),
      http.post(`${base}/full`, () =>
        HttpResponse.json({ data: familyExport }, { status: 202 }),
      ),
      http.post(`${base}/personal`, () =>
        HttpResponse.json(
          {
            data: { ...familyExport, scope: "personal" },
          },
          { status: 202 },
        ),
      ),
      http.get(`${base}/${familyExport.id}/download`, () =>
        HttpResponse.json({
          data: {
            url: "https://private.example/archive",
            expires_at: "2026-09-10T12:05:00Z",
          },
        }),
      ),
    );

    await expect(getFamilyExports("oliver-family")).resolves.toEqual([
      familyExport,
    ]);
    await expect(requestFullFamilyExport("oliver-family")).resolves.toEqual(
      familyExport,
    );
    await expect(
      requestPersonalFamilyExport("oliver-family"),
    ).resolves.toMatchObject({ scope: "personal" });
    await expect(
      authorizeFamilyExportDownload("oliver-family", familyExport.id),
    ).resolves.toMatchObject({
      url: "https://private.example/archive",
    });
  });
});
