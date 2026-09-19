import { expect, test, type Page } from "@playwright/test";

const pixel =
  "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1200 800'%3E%3Cdefs%3E%3ClinearGradient id='g' x2='1' y2='1'%3E%3Cstop stop-color='%238d4b32'/%3E%3Cstop offset='1' stop-color='%23d8b59e'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='1200' height='800' fill='url(%23g)'/%3E%3Ccircle cx='380' cy='350' r='170' fill='%23f8f3ea' fill-opacity='.42'/%3E%3Ccircle cx='730' cy='410' r='230' fill='%2330261f' fill-opacity='.28'/%3E%3C/svg%3E";

const permissions = {
  can_update: true,
  can_manage_admissions: false,
  can_review_duplicates: false,
  can_manage_exports: false,
  can_delete: false,
  can_restore: false,
  can_create_album: false,
};

const events = [
  {
    id: "christmas",
    name: "Christmas at Nan’s",
    description: "A full table, paper hats and the family together.",
    starts_on: "1994-12-25",
    ends_on: "1994-12-25",
    location: "Ashton-under-Lyne",
    status: "completed",
    created_by: 1,
    creator: { id: 1, name: "David" },
    permissions,
    presentation: {
      preview: { photo_id: "photo-1", media_upload_id: "upload-1" },
      photo_count: 18,
      album_count: 1,
      story_count: 0,
      people_count: 6,
    },
  },
  {
    id: "blackpool",
    name: "Blackpool summer holiday",
    description:
      "For one bright, blustery week in August 1986, the Mercers squeezed into the car and headed for the coast.",
    starts_on: "1986-08-12",
    ends_on: "1986-08-19",
    location: "Blackpool, Lancashire",
    status: "completed",
    created_by: 1,
    creator: { id: 1, name: "David" },
    permissions,
    albums: [
      {
        id: "album-1",
        name: "Blackpool, 1986",
        visibility: "family_space",
        guest_participation: "none",
      },
    ],
    attendees: [
      { id: "person-1", preferred_name: "William Mercer" },
      { id: "person-2", preferred_name: "Jane Mercer" },
    ],
    presentation: {
      preview: { photo_id: "photo-2", media_upload_id: "upload-2" },
      photo_count: 24,
      album_count: 1,
      story_count: 1,
      people_count: 8,
    },
  },
];

async function mockEvents(page: Page) {
  await page.route("http://localhost:8082/api/**", async (route) => {
    const path = new URL(route.request().url()).pathname;
    let data: unknown;
    if (path === "/api/user") {
      data = {
        id: 1,
        name: "David",
        email: "david@example.test",
        timezone: "Europe/London",
        email_verified_at: "2026-01-01T00:00:00Z",
        can_create_family_spaces: false,
        two_factor_enabled: false,
      };
    } else if (path === "/api/family-spaces") {
      data = [
        {
          id: "family-1",
          slug: "mercer-family-demo",
          name: "Mercer Family Demo",
          status: "active",
          role: "member",
        },
      ];
    } else if (path === "/api/families/mercer-family-demo") {
      data = {
        id: "family-1",
        slug: "mercer-family-demo",
        name: "Mercer Family Demo",
        status: "active",
        role: "member",
      };
    } else if (path.endsWith("/events/deleted")) {
      data = [];
    } else if (path.endsWith("/events/blackpool/rsvps")) {
      data = {
        going: [{ id: "admission-1", user: { id: 1, name: "David" } }],
        pending: [],
        not_attending: [],
      };
    } else if (path.endsWith("/events/blackpool/love")) {
      data = { count: 12, loved_by_me: false, reactors: [] };
    } else if (path.endsWith("/events/blackpool")) {
      data = events[1];
    } else if (path.endsWith("/events")) {
      data = events;
    } else if (path.includes("/photos/") && path.endsWith("/versions")) {
      data = { active_photo_version_id: null, can_edit: false, versions: [] };
    } else if (
      path.includes("/media-uploads/") &&
      path.includes("/variants/")
    ) {
      data = {
        asset: "variant",
        transform_name: path.endsWith("/display") ? "display" : "thumbnail",
        processing_version: 1,
        url: pixel,
        method: "GET",
        expires_at: "2026-09-20T00:00:00Z",
      };
    } else {
      data = [];
    }
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ data }),
    });
  });
}

for (const theme of ["light", "dark"] as const) {
  test(`Events list — ${theme}`, async ({ page }) => {
    await mockEvents(page);
    await page.addInitScript((value) => {
      localStorage.setItem("fambam-theme", value);
    }, theme);
    await page.goto("/families/mercer-family-demo/events");
    await expect(page.getByRole("heading", { name: "Events" })).toBeVisible();
    await expect(page).toHaveScreenshot(`events-list-${theme}.png`, {
      fullPage: true,
    });
  });

  test(`Event detail — ${theme}`, async ({ page }) => {
    await mockEvents(page);
    await page.addInitScript((value) => {
      localStorage.setItem("fambam-theme", value);
    }, theme);
    await page.goto("/families/mercer-family-demo/events/blackpool");
    await expect(
      page.getByRole("heading", { name: "Blackpool summer holiday" }),
    ).toBeVisible();
    await expect(page).toHaveScreenshot(`event-detail-${theme}.png`, {
      fullPage: true,
    });
  });
}
