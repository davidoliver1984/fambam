import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import { getHomeReadModel } from "./homeApi";

const apiBaseUrl = "http://localhost:8082";

describe("homeApi", () => {
  it("loads the bounded typed Home presentation contract", async () => {
    server.use(
      http.get(`${apiBaseUrl}/api/families/mercer-family/home`, () =>
        HttpResponse.json({
          data: {
            activity: [],
            latest_photos: [
              {
                id: "01M00000000000000000000001",
                media_upload_id: "01M00000000000000000000002",
                active_photo_version_id: null,
                alt: "At the pier",
                presentation: {
                  url: "https://storage.test/presentation",
                  method: "GET",
                  expires_at: "2026-09-26T12:15:00+00:00",
                },
              },
            ],
            on_this_day: null,
          },
        }),
      ),
    );

    await expect(getHomeReadModel("mercer-family")).resolves.toMatchObject({
      activity: [],
      latest_photos: [{ alt: "At the pier" }],
      on_this_day: null,
    });
  });
});
