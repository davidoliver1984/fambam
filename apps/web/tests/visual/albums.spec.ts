import { expect, test, type Page } from "@playwright/test";

const image =
  "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1200 800'%3E%3Cdefs%3E%3ClinearGradient id='g' x2='1' y2='1'%3E%3Cstop stop-color='%238d4b32'/%3E%3Cstop offset='1' stop-color='%23d8b59e'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='1200' height='800' fill='url(%23g)'/%3E%3Ccircle cx='380' cy='350' r='170' fill='%23f8f3ea' fill-opacity='.42'/%3E%3Ccircle cx='730' cy='410' r='230' fill='%2330261f' fill-opacity='.28'/%3E%3C/svg%3E";

const photos = Array.from({ length: 6 }, (_, index) => ({
  id: `photo-${String(index + 1)}`,
  media_upload_id: `upload-${String(index + 1)}`,
  caption: [
    "On the promenade",
    "Sandcastles with Grandad",
    "The illuminations",
    "Fish and chips",
    "A windy afternoon",
    "Heading home",
  ][index],
  client_filename: `blackpool-${String(index + 1)}.jpg`,
  visibility: "family_space",
  position: index + 1,
  historical_date: {
    precision: "exact",
    value: `1986-08-${String(index + 12).padStart(2, "0")}`,
  },
  conversation: {
    love_count: index + 1,
    comment_count: index % 3,
    viewer_has_loved: index === 0,
    can_interact: true,
  },
}));

const album = {
  id: "album-1",
  name: "Blackpool, 1986",
  description:
    "For one bright, blustery week in August 1986, the Mercers squeezed into the car and headed for the coast.",
  description_document: null,
  description_html:
    "<p>For one bright, blustery week in August 1986, the Mercers squeezed into the car and headed for the coast.</p>",
  starts_on: "1986-08-12",
  ends_on: "1986-08-19",
  location: "Blackpool, Lancashire",
  tags: [
    { id: "tag-1", label: "Blackpool" },
    { id: "tag-2", label: "Seaside" },
    { id: "tag-3", label: "Family holiday" },
  ],
  people: [
    { id: "person-1", name: "William Mercer" },
    { id: "person-2", name: "Jane Mercer" },
    { id: "person-3", name: "Robert Mercer" },
    { id: "person-4", name: "Margaret Shaw" },
    { id: "person-5", name: "James Mercer" },
  ],
  cover: {
    photo_id: "photo-1",
    media_upload_id: "upload-1",
    focal_x: 0.46,
    focal_y: 0.38,
  },
  cover_pending: false,
  visibility: "family_space",
  created_by: 1,
  creator: { id: 1, name: "David" },
  created_at: "2026-09-20T10:00:00Z",
  updated_at: "2026-09-25T10:00:00Z",
  photo_count: photos.length,
  event_id: null,
  event: null,
  guest_participation: "none",
  photos,
  grants: [],
  permissions: {
    can_manage: true,
    can_contribute: true,
    can_delete: true,
  },
};

async function mockAlbum(page: Page) {
  await page.route("http://localhost:8082/api/**", async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname;
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
          role: "owner",
        },
      ];
    } else if (path === "/api/families/mercer-family-demo") {
      data = {
        id: "family-1",
        slug: "mercer-family-demo",
        name: "Mercer Family Demo",
        status: "active",
        role: "owner",
      };
    } else if (path.endsWith("/albums/album-1/love")) {
      data = { count: 12, loved_by_me: false, reactors: [] };
    } else if (path.endsWith("/albums/album-1")) {
      data = album;
    } else if (path.endsWith("/albums")) {
      data = [album];
    } else if (path.endsWith("/people")) {
      data = [];
    } else if (path.endsWith("/collections")) {
      data = [];
    } else if (path.endsWith("/search")) {
      data = { stories: { items: [], next_cursor: null } };
    } else if (path.includes("/photos/") && path.endsWith("/conversation")) {
      data = {
        stories: [],
        comments: [],
        reactions: [],
        permissions: { can_interact: true, can_author_story: true },
        conversation_scope: "album",
        album_id: "album-1",
      };
    } else if (path.includes("/photos/") && path.endsWith("/versions")) {
      data = { active_photo_version_id: null, can_edit: true, versions: [] };
    } else if (path.includes("/photos/")) {
      const id = path.split("/").at(-1) ?? "photo-1";
      const photo =
        photos.find((candidate) => candidate.id === id) ?? photos[0];
      data = {
        id,
        media_upload: {
          id: photo.media_upload_id,
          client_filename: photo.client_filename,
          uploader: { id: 1, name: "David" },
        },
        created_by: 1,
        visibility: "family_space",
        caption: photo.caption,
        description: null,
        archive_source_description: null,
        historical_date: photo.historical_date,
        location_description: null,
        do_not_resurface: false,
        love_count: 0,
        comment_count: 0,
        album_count: 1,
        provenance: {
          photographer: { person: null, description: null },
          scanner: { person: null, description: null },
          physical_owner: { person: null, description: null },
        },
        tags: [],
        people: [],
        created_at: "2026-09-20T10:00:00Z",
        updated_at: "2026-09-20T10:00:00Z",
        permissions: {
          can_update: true,
          can_propose_provenance: true,
          can_resolve_provenance: true,
          can_manage_tags: true,
          can_flag_duplicate: true,
        },
      };
    } else if (
      path.includes("/media-uploads/") &&
      path.includes("/variants/")
    ) {
      data = {
        asset: "variant",
        transform_name: path.endsWith("/display") ? "display" : "thumbnail",
        processing_version: 1,
        url: image,
        method: "GET",
        expires_at: "2026-10-01T00:00:00Z",
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
  test(`Album detail — ${theme}`, async ({ page }) => {
    await mockAlbum(page);
    await page.addInitScript((value) => {
      localStorage.setItem("fambam-theme", value);
    }, theme);
    await page.goto("/families/mercer-family-demo/albums/album-1");
    await expect(
      page.getByRole("heading", { name: "Blackpool, 1986" }),
    ).toBeVisible();
    await expect(page).toHaveScreenshot(`album-detail-${theme}.png`, {
      fullPage: true,
    });

    await page.getByRole("button", { name: "Album options" }).click();
    await expect(page.getByRole("menu")).toHaveScreenshot(
      `album-detail-menu-${theme}.png`,
    );
  });
}
