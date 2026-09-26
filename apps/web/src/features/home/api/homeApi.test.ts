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
            activity: [
              {
                id: "activity-1",
                action_type: "story_added",
                actor: { user_id: 1, name: "David", person_id: null },
                subject: {
                  type: "story",
                  id: "story-1",
                  label: "At the seaside",
                  subject_type: "person",
                  subject_id: "person-1",
                },
                contribution_batch_id: null,
                photo_ids: [],
                photo_count: 0,
                created_at: "2026-09-26T12:00:00+00:00",
                story: {
                  id: "story-1",
                  heading: "At the seaside",
                  excerpt: "A family memory.",
                  subject: {
                    type: "person",
                    id: "person-1",
                    label: "William",
                  },
                },
                engagement: {
                  love_count: 2,
                  loved_by_me: true,
                  comment_count: 1,
                },
              },
            ],
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

    const home = await getHomeReadModel("mercer-family");

    expect(home).toMatchObject({
      activity: [
        {
          story: {
            heading: "At the seaside",
            subject: { label: "William" },
          },
          engagement: { loved_by_me: true },
        },
      ],
      latest_photos: [{ alt: "At the pier" }],
      on_this_day: null,
    });
  });
});
