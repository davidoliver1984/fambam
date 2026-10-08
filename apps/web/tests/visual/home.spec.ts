import { expect, test, type Page } from "@playwright/test";

const artwork = (from: string, to: string) =>
  `data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1200 800'%3E%3Cdefs%3E%3ClinearGradient id='g' x2='1' y2='1'%3E%3Cstop stop-color='${from.replace("#", "%23")}'/%3E%3Cstop offset='1' stop-color='${to.replace("#", "%23")}'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='1200' height='800' fill='url(%23g)'/%3E%3Ccircle cx='370' cy='330' r='170' fill='%23fff' fill-opacity='.28'/%3E%3Ccircle cx='780' cy='430' r='240' fill='%23231b17' fill-opacity='.2'/%3E%3C/svg%3E`;

const family = {
  id: "family-1",
  slug: "mercer-family-demo",
  name: "Mercer Family Demo",
  status: "active",
  role: "owner",
  current_user_person_id: "person-david",
};

const photos = Array.from({ length: 15 }, (_, index) => ({
  id: `latest-${String(index + 1)}`,
  media_upload_id: `upload-latest-${String(index + 1)}`,
  active_photo_version_id: null,
  alt: `Mercer family photograph ${String(index + 1)}`,
  presentation: {
    url: artwork(index % 2 === 0 ? "#8b503a" : "#3f6576", "#d8b193"),
    method: "GET",
    expires_at: "2026-09-27T12:00:00Z",
  },
}));

const contributionPhotos = [
  [
    "photo-pier",
    "The Mercer family together on Blackpool promenade",
    "#5f7580",
    "#d49d74",
  ],
  ["photo-tower", "Blackpool Tower", "#9b6748", "#dfba87"],
  ["photo-beach", "The family on Blackpool beach", "#496b78", "#c9a27e"],
  ["photo-tram", "Riding the Blackpool tram", "#7d4d3a", "#d9a886"],
  ["photo-coats", "Coats on the promenade", "#546d68", "#c28b6b"],
].map(([id, alt, from, to], index) => ({
  ...photos[index],
  id,
  alt,
  presentation: {
    ...photos[index].presentation,
    url: artwork(from, to),
  },
}));

const home = {
  activity: [
    {
      id: "activity-album",
      action_type: "photos_added_to_album",
      actor: { user_id: 2, name: "Sarah Mercer", person_id: "person-sarah" },
      subject: {
        type: "album",
        id: "album-blackpool",
        label: "Blackpool, 1986",
      },
      contribution_batch_id: "batch-1",
      photo_ids: contributionPhotos.map((photo) => photo.id),
      photo_count: 24,
      created_at: "2026-09-26T08:15:00Z",
      album: {
        id: "album-blackpool",
        name: "Blackpool, the wind and Grandma’s pink coat",
        description: "Dad found this envelope behind the old telephone table.",
        starts_on: "1986-08-12",
        ends_on: "1986-08-19",
        location: "Blackpool",
      },
      feature_photo: contributionPhotos[0],
      contribution_photos: contributionPhotos,
      engagement: { love_count: 8, loved_by_me: false, comment_count: 3 },
    },
    {
      id: "activity-story",
      action_type: "story_added",
      actor: { user_id: 3, name: "Jane Mercer", person_id: "person-jane" },
      subject: {
        type: "story",
        id: "story-drive",
        label: "Story",
        subject_type: "person",
        subject_id: "person-william",
      },
      contribution_batch_id: null,
      photo_ids: [],
      photo_count: 0,
      created_at: "2026-09-25T12:00:00Z",
      story: {
        id: "story-drive",
        heading: "The day William tried to drive to Scotland",
        excerpt:
          "He was six, the Mini was parked on the drive, and his provisions consisted of two digestives and a bottle of orange squash.",
        subject: {
          type: "person",
          id: "person-william",
          label: "William Mercer",
        },
      },
      engagement: { love_count: 5, loved_by_me: true, comment_count: 2 },
    },
  ],
  latest_photos: photos,
  on_this_day: {
    photo_id: "memory-1",
    media_upload_id: "upload-memory-1",
    label: "Christmas at Nan’s",
    reason: "On this day in 1994",
    location: "Ashton-under-Lyne",
    historical_date: { precision: "exact", value: "1994-09-26" },
    added_at: "2026-01-01T00:00:00Z",
    photo: {
      ...photos[1],
      id: "memory-1",
      alt: "Christmas dinner at Nan’s",
      presentation: {
        ...photos[1].presentation,
        url: artwork("#6e3f32", "#d2aa72"),
      },
    },
  },
};

async function mockHome(page: Page) {
  await page.route("http://localhost:8082/api/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    let data: unknown = [];

    if (path === "/api/user") {
      data = {
        id: 1,
        name: "David Oliver",
        email: "david@example.test",
        timezone: "Europe/London",
        email_verified_at: "2026-01-01T00:00:00Z",
        can_create_family_spaces: false,
        two_factor_enabled: false,
      };
    } else if (path === "/api/family-spaces") {
      data = [family];
    } else if (path === "/api/families/mercer-family-demo") {
      data = family;
    } else if (path.endsWith("/home")) {
      data = home;
    } else if (path.endsWith("/people")) {
      data = {
        items: [
          {
            id: "person-emma",
            preferred_name: "Emma Mercer",
            alternate_names: [],
            identity_status: "confirmed",
            birth_date: { precision: "exact", value: "2003-10-02" },
            is_deceased: false,
            death_date: { precision: "unknown", value: null },
            status: "living",
            portrait_thumbnail_url: null,
            relationship_summary: null,
            biography: null,
            account_link: null,
            redirected_from_person_id: null,
            created_at: "2026-01-01T00:00:00Z",
            updated_at: "2026-01-01T00:00:00Z",
            permissions: {},
          },
        ],
        next_cursor: null,
      };
    }

    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ data }),
    });
  });
}

for (const theme of ["light", "dark"] as const) {
  test(`Home — ${theme}`, async ({ page }, testInfo) => {
    await mockHome(page);
    await page.addInitScript((value) => {
      localStorage.setItem("fambam-theme", value);
    }, theme);
    await page.goto("/families/mercer-family-demo");
    await expect(
      page.getByRole("heading", {
        name: /Good (morning|afternoon|evening), David/,
      }),
    ).toBeVisible();
    await expect(page.getByText("24 photos")).toBeVisible();
    await expect(
      page.getByRole("heading", { name: "Latest photographs" }),
    ).toBeVisible();
    await expect(page.getByRole("link", { name: "Create album" })).toHaveCSS(
      "color",
      theme === "light" ? "rgb(255, 255, 255)" : "rgb(36, 26, 22)",
    );
    const createAlbumBox = await page
      .getByRole("link", { name: "Create album" })
      .boundingBox();
    expect(
      (createAlbumBox?.x ?? 0) + (createAlbumBox?.width ?? 0),
    ).toBeLessThanOrEqual(page.viewportSize()?.width ?? 0);
    await expect(page.getByRole("button", { name: "Love · 8" })).toHaveCSS(
      "border-top-width",
      "0px",
    );
    await expect(
      page.getByRole("button", { name: "Love · 8" }).locator("svg"),
    ).toHaveCSS(
      "color",
      theme === "light" ? "rgb(178, 61, 70)" : "rgb(212, 122, 112)",
    );
    await expect(page.locator(".home-feed-column")).toHaveCSS(
      "overflow-y",
      "visible",
    );
    await expect(page.locator(".home-aside")).toHaveCSS(
      "overflow-y",
      "visible",
    );
    await expect(
      page.getByRole("link", {
        name: "Blackpool, the wind and Grandma’s pink coat",
        exact: true,
      }),
    ).toHaveCSS("text-decoration-line", "underline");

    const width =
      testInfo.project.name === "desktop"
        ? 1440
        : testInfo.project.name === "tablet"
          ? 834
          : 390;
    await page.screenshot({
      path: testInfo.outputPath(`home-${theme}-${String(width)}.png`),
      fullPage: true,
    });

    if (theme === "light" && testInfo.project.name === "desktop") {
      await page
        .locator(".home-feed-card")
        .nth(0)
        .screenshot({
          path: testInfo.outputPath("home-contribution-card.png"),
        });
      await page
        .locator(".home-feed-card")
        .nth(1)
        .screenshot({
          path: testInfo.outputPath("home-story-card.png"),
        });
      await page.locator(".home-memory-card").screenshot({
        path: testInfo.outputPath("home-on-this-day.png"),
      });
      await page.locator('[aria-labelledby="home-latest-title"]').screenshot({
        path: testInfo.outputPath("home-latest-photos.png"),
      });
      await page
        .locator('[aria-labelledby="home-birthdays-title"]')
        .screenshot({
          path: testInfo.outputPath("home-birthdays.png"),
        });
    }
  });
}
