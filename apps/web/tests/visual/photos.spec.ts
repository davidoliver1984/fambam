import { expect, test, type Page } from "@playwright/test";

const palette = [
  ["8d4b32", "d8b59e"],
  ["54717c", "d8e3df"],
  ["73604d", "e6cfaa"],
  ["6d5149", "c9a18f"],
  ["405f58", "b6cec5"],
  ["72594a", "ddc7ae"],
  ["4e6273", "c8d8e0"],
  ["69494d", "d8b9bc"],
];

function image(index: number) {
  const [from, to] = palette[index % palette.length];
  return `data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1200 800'%3E%3Cdefs%3E%3ClinearGradient id='g' x2='1' y2='1'%3E%3Cstop stop-color='%23${from}'/%3E%3Cstop offset='1' stop-color='%23${to}'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='1200' height='800' fill='url(%23g)'/%3E%3Ccircle cx='380' cy='350' r='170' fill='%23f8f3ea' fill-opacity='.42'/%3E%3Ccircle cx='730' cy='410' r='230' fill='%2330261f' fill-opacity='.28'/%3E%3C/svg%3E`;
}

const names = [
  "At the pier",
  "Auntie Margaret",
  "On the promenade",
  "Family picnic",
  "The tower",
  "Christmas morning",
  "Garden tea",
  "William and Jane",
];

const photos = Array.from({ length: 52 }, (_, index) => ({
  caption: names[index] ?? `Archive photograph ${String(index + 1)}`,
  index,
})).map(({ caption, index }) => ({
  id: `photo-${String(index + 1)}`,
  media_upload: {
    id: `upload-${String(index + 1)}`,
    client_filename: `${caption.toLocaleLowerCase().replaceAll(" ", "-")}.jpg`,
    uploader: { id: 1, name: "David" },
  },
  created_by: 1,
  visibility: "family_space",
  caption,
  description: null,
  archive_source_description: null,
  historical_date: {
    precision: "exact",
    value: `1986-08-${String(12 + index).padStart(2, "0")}`,
  },
  location_description: index < 26 ? "Blackpool" : "Ashton-under-Lyne",
  do_not_resurface: false,
  love_count: 3 + index,
  comment_count: 1 + (index % 4),
  album_count: index % 3 === 1 ? 0 : 1,
  interaction_album_id: index % 3 === 1 ? null : "album-blackpool",
  viewer_has_loved: index % 5 === 0,
  interaction_can_interact: index % 3 !== 1,
  provenance: {
    photographer: { person: null, description: null },
    scanner: { person: null, description: null },
    physical_owner: { person: null, description: null },
  },
  tags: [{ id: "tag-holiday", label: "Family holiday" }],
  people: [
    {
      id: `association-${String(index + 1)}`,
      photo_id: `photo-${String(index + 1)}`,
      person: { id: "person-william", preferred_name: "William Mercer" },
      proposal_source: "manual",
      status: "approved",
      proposed_by: 1,
      resolved_by: 1,
      resolved_at: "2026-09-20T00:00:00Z",
      created_at: "2026-09-20T00:00:00Z",
    },
  ],
  created_at: `2026-09-${String(10 + index).padStart(2, "0")}T10:00:00Z`,
  updated_at: "2026-09-20T00:00:00Z",
  permissions: {
    can_update: true,
    can_propose_provenance: true,
    can_resolve_provenance: false,
    can_manage_tags: true,
    can_flag_duplicate: false,
  },
}));

async function mockPhotos(
  page: Page,
  photoData = photos,
  options: { continuationDelayMs?: number; thirdPageDelayMs?: number } = {},
) {
  await page.route("http://localhost:8082/api/**", async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname;
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
    } else if (path.endsWith("/photos")) {
      let matching =
        url.searchParams.get("without_album") === "1"
          ? photoData.filter((photo) => photo.album_count === 0)
          : photoData;
      const q = url.searchParams.get("q")?.toLocaleLowerCase();
      if (q) {
        matching = matching.filter((photo) =>
          [photo.caption, photo.location_description]
            .join(" ")
            .toLocaleLowerCase()
            .includes(q),
        );
      }
      if (url.searchParams.get("sort") === "oldest")
        matching = [...matching].reverse();
      const offset = Number(url.searchParams.get("cursor") ?? "0");
      const delay =
        offset >= 48 ? options.thirdPageDelayMs : options.continuationDelayMs;
      if (offset > 0 && delay) {
        await new Promise((resolve) => setTimeout(resolve, delay));
      }
      data = {
        items: matching.slice(offset, offset + 24),
        next_cursor: offset + 24 < matching.length ? String(offset + 24) : null,
      };
    } else if (path.endsWith("/albums")) {
      data = {
        items: [
          {
            id: "album-blackpool",
            name: "Blackpool, 1986",
            description: null,
            visibility: "family_space",
            created_by: 1,
            creator: { id: 1, name: "David" },
            created_at: "2026-09-01T00:00:00Z",
            updated_at: "2026-09-01T00:00:00Z",
            photo_count: 5,
            is_new: false,
            guest_participation: "none",
            photos: photoData
              .filter((photo) => photo.album_count > 0)
              .map((photo, position) => ({
                id: photo.id,
                media_upload_id: photo.media_upload.id,
                caption: photo.caption,
                client_filename: photo.media_upload.client_filename,
                visibility: "family_space",
                position,
              })),
            grants: [],
            permissions: { can_manage: true, can_contribute: true },
          },
        ],
        next_cursor: null,
      };
    } else if (path.endsWith("/conversation")) {
      data = {
        stories: [],
        comments: [],
        reactions: [],
        permissions: { can_interact: false, can_author_story: false },
        conversation_scope: "legacy",
        album_id: null,
      };
    } else if (/\/photos\/photo-\d+$/.test(path)) {
      const photoId = path.split("/").at(-1);
      data = photoData.find((photo) => photo.id === photoId) ?? null;
    } else if (path.endsWith("/people")) {
      data = [];
    } else if (path.endsWith("/collections")) {
      data = [
        {
          id: "collection-calendar",
          name: "Family calendar shortlist",
          description: null,
          purpose: "calendar",
          created_at: "2026-09-01T00:00:00Z",
          updated_at: "2026-09-01T00:00:00Z",
          photo_count: 4,
          preview_photo: null,
        },
        {
          id: "collection-prints",
          name: "Prints for Mum",
          description: null,
          purpose: "prints",
          created_at: "2026-09-02T00:00:00Z",
          updated_at: "2026-09-02T00:00:00Z",
          photo_count: 6,
          preview_photo: null,
        },
      ];
    } else if (path.endsWith("/album-history")) {
      data = [];
    } else if (path.includes("/photos/") && path.endsWith("/versions")) {
      data = { active_photo_version_id: null, can_edit: true, versions: [] };
    } else if (
      path.includes("/media-uploads/") &&
      path.includes("/variants/")
    ) {
      const match = path.match(/upload-(\d+)/);
      const index = Number(match?.[1] ?? "1") - 1;
      data = {
        asset: "variant",
        transform_name: "card",
        processing_version: 1,
        url: image(index),
        method: "GET",
        expires_at: "2026-09-27T00:00:00Z",
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
  test(`Photos archive — ${theme}`, async ({ page }) => {
    await mockPhotos(page);
    await page.addInitScript((value) => {
      localStorage.setItem("fambam-theme", value);
    }, theme);
    await page.goto("/families/mercer-family-demo/photos");
    await expect(
      page.getByRole("heading", { name: "Photographs" }),
    ).toBeVisible();
    await expect(page.getByRole("link", { name: "Add photos" })).toHaveCSS(
      "color",
      theme === "light" ? "rgb(255, 255, 255)" : "rgb(36, 26, 22)",
    );
    await page.waitForTimeout(200);
    await expect(
      page.getByRole("button", { name: "Remove love · 3" }),
    ).toBeVisible();
    await expect(
      page.getByRole("link", { name: "1 comment" }).first(),
    ).toBeVisible();
    await expect(page).toHaveScreenshot(`photos-archive-${theme}.png`, {
      animations: "allow",
      fullPage: true,
    });
  });
}

test("Photos toolbar, menu and unfiled state", async ({ page }) => {
  await mockPhotos(page);
  await page.goto("/families/mercer-family-demo/photos");
  await page.getByRole("button", { name: "Filters" }).click();
  await page
    .getByRole("button", { name: "Album status: Not in an album" })
    .click();
  await expect(page.getByText("17 shown")).toBeVisible();
  await expect(page).toHaveScreenshot("photos-unfiled.png", {
    animations: "allow",
    fullPage: true,
  });
  await page.getByRole("button", { name: "Photo options" }).first().click();
  await expect(page.getByRole("menu")).toBeVisible();
  await expect(
    page.getByRole("menuitem", { name: "Delete Photo" }),
  ).toHaveCount(0);
  await expect(page).toHaveScreenshot("photos-menu.png", {
    animations: "allow",
    fullPage: true,
  });
  await page.keyboard.press("Escape");
  await expect(
    page.getByRole("button", { name: "Photo options" }).first(),
  ).toBeFocused();
});

test("Photos empty Not in an album state", async ({ page }) => {
  await mockPhotos(
    page,
    photos.map((photo) => ({ ...photo, album_count: 1 })),
  );
  await page.goto("/families/mercer-family-demo/photos");
  await page.getByRole("button", { name: "Filters" }).click();
  await page
    .getByRole("button", { name: "Album status: Not in an album" })
    .click();
  await expect(
    page.getByRole("heading", { name: "No matches found" }),
  ).toBeVisible();
  await expect(page).toHaveScreenshot("photos-empty-unfiled.png", {
    animations: "allow",
    fullPage: true,
  });
});

test("Photos infinite archive states", async ({ page }) => {
  await mockPhotos(page, photos, {
    continuationDelayMs: 500,
    thirdPageDelayMs: 6_000,
  });
  await page.goto("/families/mercer-family-demo/photos");
  await expect(page.getByRole("article")).toHaveCount(24);
  await expect(page).toHaveScreenshot("photos-initial-page.png", {
    animations: "allow",
    fullPage: true,
  });

  await page.mouse.wheel(0, 100_000);
  await expect(page.getByText("Loading more photographs…")).toBeVisible();
  await expect(page.locator(".photos-pagination")).toHaveScreenshot(
    "photos-continuation-loading.png",
    { animations: "allow", maxDiffPixelRatio: 0.05 },
  );
  await expect(page.getByRole("article")).toHaveCount(48);
  await expect(page).toHaveScreenshot("photos-page-two-appended.png", {
    animations: "allow",
    fullPage: true,
    maxDiffPixelRatio: 0.03,
  });

  await page.mouse.wheel(0, 100_000);
  await expect(page.getByRole("article")).toHaveCount(52, { timeout: 10_000 });
  await expect(page.getByText("All photographs loaded")).toBeVisible();
  await expect(page).toHaveScreenshot("photos-end-of-results.png", {
    animations: "allow",
    fullPage: true,
    maxDiffPixelRatio: 0.02,
  });
});

test("Photos list, search and sort states", async ({ page }) => {
  await mockPhotos(page);
  await page.goto("/families/mercer-family-demo/photos");
  await page.getByRole("button", { name: "List view" }).click();
  await expect(page).toHaveScreenshot("photos-list.png", {
    animations: "allow",
    fullPage: true,
  });

  await page.getByPlaceholder("Search photographs…").fill("At the pier");
  await expect(page.getByText("1 shown")).toBeVisible();
  await expect(page).toHaveScreenshot("photos-search.png", {
    animations: "allow",
    fullPage: true,
  });
  await page.getByRole("button", { name: "Clear search" }).click();

  for (const sort of [
    ["Newest first", "photos-sort-newest.png"],
    ["Oldest first", "photos-sort-oldest.png"],
    ["Recently added", "photos-sort-recent.png"],
  ] as const) {
    await page.getByRole("button", { name: "Sort photographs" }).click();
    await page.getByRole("menuitemradio", { name: sort[0] }).click();
    await expect(page.getByRole("article")).toHaveCount(24);
    await expect(page).toHaveScreenshot(sort[1], {
      animations: "allow",
      fullPage: true,
    });
  }
});

test("Photos Album and Collection pickers", async ({ page }) => {
  await mockPhotos(page);
  await page.goto("/families/mercer-family-demo/photos");
  const options = page.getByRole("button", { name: "Photo options" }).first();
  await options.click();
  await page.getByRole("menuitem", { name: "Add to album…" }).click();
  await expect(
    page.getByRole("dialog", { name: "Add to album" }),
  ).toBeVisible();
  await expect(page).toHaveScreenshot("photos-album-picker.png", {
    animations: "allow",
    fullPage: true,
  });
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Cancel" })
    .click();

  await options.click();
  await page.getByRole("menuitem", { name: "Add to collection…" }).click();
  await expect(
    page.getByRole("dialog", { name: "Add to collection" }),
  ).toBeVisible();
  await expect(page).toHaveScreenshot("photos-collection-picker.png", {
    animations: "allow",
    fullPage: true,
  });
});

test("Photos browser Back retains appended pages and scroll position", async ({
  page,
}) => {
  await mockPhotos(page);
  await page.goto("/families/mercer-family-demo/photos");
  await page.mouse.wheel(0, 100_000);
  await expect(page.getByRole("article")).toHaveCount(48);
  await page.mouse.wheel(0, 100_000);
  await expect(page.getByRole("article")).toHaveCount(52);
  const scrollBefore = await page.evaluate(() => window.scrollY);

  await page
    .getByRole("link", { name: /Archive photograph 50/ })
    .first()
    .click();
  await expect(page).toHaveURL("/families/mercer-family-demo/photos/photo-50");
  await page.goBack();

  await expect(page.getByRole("article")).toHaveCount(52);
  await expect
    .poll(() => page.evaluate(() => window.scrollY))
    .toBeGreaterThan(0);
  expect(scrollBefore).toBeGreaterThan(0);
});
