import { expect, test, type Page } from "@playwright/test";

const cover =
  "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1200 675'%3E%3Cdefs%3E%3ClinearGradient id='g' x2='1' y2='1'%3E%3Cstop stop-color='%23648ca1'/%3E%3Cstop offset='1' stop-color='%23c7906e'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='1200' height='675' fill='url(%23g)'/%3E%3Ccircle cx='380' cy='335' r='170' fill='%23f8f3ea' fill-opacity='.42'/%3E%3Ccircle cx='790' cy='390' r='230' fill='%2330261f' fill-opacity='.25'/%3E%3C/svg%3E";

const baseAlbum = {
  description: null,
  visibility: "family_space",
  created_by: 1,
  creator: { id: 1, name: "Sarah Mercer" },
  created_at: "2026-09-01T10:00:00Z",
  updated_at: "2026-09-11T10:00:00Z",
  is_new: false,
  photo_count: 24,
  event_id: null,
  event: null as { id: string; name: string } | null,
  guest_participation: "none",
  grants: [],
  permissions: { can_manage: true, can_contribute: true },
  photos: [],
  people: [{ id: "person-1", name: "William Mercer" }],
  tags: [{ id: "tag-1", label: "Family holiday" }],
  location: "Blackpool",
};

const albums = [
  {
    ...baseAlbum,
    id: "blackpool",
    name: "Blackpool, 1986",
    starts_on: "2026-09-10",
    ends_on: "2026-09-10",
    cover: {
      photo_id: "photo-1",
      media_upload_id: "upload-1",
      focal_x: 0.5,
      focal_y: 0.46,
    },
  },
  {
    ...baseAlbum,
    id: "christmas",
    name: "Christmas at Nan’s",
    starts_on: "2026-09-04",
    ends_on: null,
    creator: null,
    photo_count: 18,
    people: [{ id: "person-2", name: "Margaret Shaw" }],
    location: "Ashton-under-Lyne",
    tags: [{ id: "tag-2", label: "Christmas" }],
    cover: {
      photo_id: "photo-2",
      media_upload_id: "upload-2",
      focal_x: 0.5,
      focal_y: 0.5,
    },
  },
  {
    ...baseAlbum,
    id: "william",
    name: "William through the years",
    starts_on: "2026-08-20",
    ends_on: null,
    creator: { id: 2, name: "David Mercer" },
    photo_count: 46,
    cover: {
      photo_id: "photo-3",
      media_upload_id: "upload-3",
      focal_x: 0.5,
      focal_y: 0.4,
    },
  },
  {
    ...baseAlbum,
    id: "garden",
    name: "Garden days",
    starts_on: "2026-08-08",
    ends_on: null,
    photo_count: 12,
    creator: null,
    location: "Glossop",
    cover: null,
  },
];

async function mockAlbums(page: Page, initialAlbums = albums) {
  let currentAlbums = structuredClone(initialAlbums);
  await page.route("http://localhost:8082/sanctum/csrf-cookie", (route) =>
    route.fulfill({ status: 204 }),
  );
  await page.route("http://localhost:8082/api/**", async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    let data: unknown = [];
    let status = 200;
    if (path === "/api/user") {
      data = {
        id: 1,
        name: "David Mercer",
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
    } else if (path.endsWith("/albums") && request.method() === "GET") {
      const params = new URL(request.url()).searchParams;
      const query = params.get("q")?.trim().toLocaleLowerCase();
      const personIds = [
        ...params.getAll("person_ids[]"),
        ...params.getAll("person_ids"),
      ];
      const matchingAlbums = currentAlbums.filter((album) => {
        const matchesQuery =
          !query ||
          [
            album.name,
            album.location,
            album.event?.name ?? "",
            album.creator?.name ?? "",
            ...album.people.map((person) => person.name),
            ...album.tags.map((tag) => tag.label),
          ]
            .join(" ")
            .toLocaleLowerCase()
            .includes(query);
        const matchesPeople =
          personIds.length === 0 ||
          personIds.every((id) =>
            album.people.some((person) => person.id === id),
          );
        return matchesQuery && matchesPeople;
      });
      data = { items: matchingAlbums, next_cursor: null };
    } else if (path.includes("/albums/") && request.method() === "PATCH") {
      const id = path.split("/").at(-1);
      data = currentAlbums.find((album) => album.id === id);
    } else if (path.includes("/albums/") && request.method() === "DELETE") {
      const id = path.split("/").at(-1);
      currentAlbums = currentAlbums.filter((album) => album.id !== id);
      status = 204;
      data = undefined;
    } else if (path.endsWith("/exports") || path.endsWith("/populate")) {
      data = { id: "export-1", name: "Prints for Mum" };
    } else if (path.endsWith("/collections")) {
      data = [
        {
          id: "collection-1",
          name: "Prints for Mum",
          description: null,
          created_at: "2026-01-01T00:00:00Z",
        },
      ];
    } else if (path.endsWith("/people")) {
      data = [
        { id: "person-1", preferred_name: "William Mercer" },
        { id: "person-2", preferred_name: "Margaret Shaw" },
      ];
    } else if (path.endsWith("/photos")) {
      data = [];
    } else if (path.includes("/photos/") && path.endsWith("/versions")) {
      data = { active_photo_version_id: null, can_edit: false, versions: [] };
    } else if (
      path.includes("/media-uploads/") &&
      path.includes("/variants/")
    ) {
      data = {
        asset: "variant",
        transform_name: "card",
        processing_version: 1,
        url: cover,
        method: "GET",
        expires_at: "2026-09-27T00:00:00Z",
      };
    }
    await route.fulfill(
      status === 204
        ? { status }
        : {
            status,
            contentType: "application/json",
            body: JSON.stringify({ data }),
          },
    );
  });
}

for (const theme of ["light", "dark"] as const) {
  test(`Albums list — ${theme}`, async ({ page }) => {
    await mockAlbums(page);
    await page.addInitScript((value) => {
      localStorage.setItem("fambam-theme", value);
    }, theme);
    await page.goto("/families/mercer-family-demo/albums");
    await expect(page.getByRole("heading", { name: "Albums" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Create album" })).toHaveCSS(
      "color",
      theme === "light" ? "rgb(255, 255, 255)" : "rgb(36, 26, 22)",
    );
    await expect(page).toHaveScreenshot(`albums-list-${theme}.png`, {
      fullPage: true,
    });
  });
}

test("Albums index interactions", async ({ page }) => {
  await mockAlbums(page);
  await page.goto("/families/mercer-family-demo/albums");
  await page.getByPlaceholder("Search albums…").fill("William");
  await page.getByRole("button", { name: /William Mercer/ }).click();
  await expect(
    page.getByRole("heading", { name: "William through the years" }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Christmas at Nan’s" }),
  ).toBeHidden();
  await page.getByRole("button", { name: /William Mercer/ }).click();
  await page.getByRole("button", { name: "List view" }).click();
  await expect(page.locator(".album-card-grid--list").first()).toBeVisible();

  await page
    .getByRole("button", { name: "Album options for Blackpool, 1986" })
    .click();
  await page.getByRole("menuitem", { name: "Manage tags" }).click();
  await page.getByRole("textbox", { name: "Tags" }).fill("Seaside, Holiday");
  await page.getByRole("button", { name: "Save changes" }).click();
  await expect(page.getByText("Album tags updated")).toBeAttached();

  await page
    .getByRole("button", { name: "Album options for Blackpool, 1986" })
    .click();
  await page.getByRole("menuitem", { name: "Delete album" }).click();
  await expect(
    page.getByRole("heading", { name: "Delete “Blackpool, 1986”?" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Delete album" }).click();
  await expect(
    page.getByRole("heading", { name: "Blackpool, 1986", exact: true }),
  ).toBeHidden();
});

test("Albums index visual states", async ({ page }) => {
  await mockAlbums(page);
  await page.goto("/families/mercer-family-demo/albums");

  await page.getByRole("button", { name: "Filters" }).click();
  await expect(page).toHaveScreenshot("albums-filter-state.png", {
    fullPage: true,
  });
  await page.getByRole("button", { name: "Filters" }).click();

  await expect(page.locator(".album-month").first()).toHaveScreenshot(
    "albums-month-group.png",
  );
  await expect(page.locator(".album-index-card").first()).toHaveScreenshot(
    "albums-card.png",
  );

  await page
    .getByRole("button", { name: "Album options for Blackpool, 1986" })
    .click();
  await expect(page.getByRole("menu")).toHaveScreenshot(
    "albums-context-menu.png",
  );
  await page.getByRole("menuitem", { name: "Delete album" }).click();
  await expect(page.getByRole("dialog")).toHaveScreenshot(
    "albums-delete-confirmation.png",
  );
  await page.getByRole("button", { name: "Cancel" }).click();

  await page.getByPlaceholder("Search albums…").fill("No such album");
  await expect(page.locator(".album-empty")).toHaveScreenshot(
    "albums-filtered-empty.png",
  );
});

test("Albums index no-Albums state", async ({ page }) => {
  await mockAlbums(page, []);
  await page.goto("/families/mercer-family-demo/albums");
  await expect(page.locator(".album-empty")).toHaveScreenshot(
    "albums-no-albums.png",
  );
});
