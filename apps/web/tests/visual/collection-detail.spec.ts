import { expect, test, type Page } from "@playwright/test";

const family = {
  id: "family-1",
  slug: "mercer-family-demo",
  name: "Mercer Family Demo",
  status: "active",
  role: "owner",
  current_user_person_id: "person-david",
};

const photos = [
  {
    id: "photo-1",
    caption: "At the pier",
    media_upload_id: "upload-1",
    historical_date: { precision: "exact", value: "1986-08-14" },
    location_description: "Blackpool",
    people: [
      { id: "person-william", preferred_name: "William" },
      { id: "person-jane", preferred_name: "Jane" },
      { id: "person-robert", preferred_name: "Robert" },
    ],
    position: 0,
  },
  {
    id: "photo-2",
    caption: "Christmas dinner",
    media_upload_id: "upload-2",
    historical_date: { precision: "exact", value: "1994-12-25" },
    location_description: "Ashton-under-Lyne",
    people: [{ id: "person-jane", preferred_name: "Jane" }],
    position: 1,
  },
  {
    id: "photo-3",
    caption: "William in the garden",
    media_upload_id: "upload-3",
    historical_date: { precision: "exact", value: "2024-06-08" },
    location_description: "Glossop",
    people: [{ id: "person-william", preferred_name: "William" }],
    position: 2,
  },
  {
    id: "photo-4",
    caption: "On the promenade",
    media_upload_id: "upload-1",
    historical_date: { precision: "exact", value: "1986-08-16" },
    location_description: "Blackpool",
    people: [],
    position: 3,
  },
  {
    id: "photo-5",
    caption: "Pudding at Nan’s",
    media_upload_id: "upload-2",
    historical_date: { precision: "year", value: "1994" },
    location_description: "Ashton-under-Lyne",
    people: [],
    position: 4,
  },
  {
    id: "photo-6",
    caption: "Grandma’s pink coat",
    media_upload_id: "upload-1",
    historical_date: { precision: "month", value: "1986-08" },
    location_description: "Blackpool",
    people: [{ id: "person-margaret", preferred_name: "Margaret" }],
    position: 5,
  },
];

const candidates = [
  {
    id: "candidate-1",
    media_upload: {
      id: "upload-3",
      client_filename: "quiet-afternoon.jpg",
      uploader: null,
    },
    caption: "A quiet afternoon",
    visibility: "family_space",
    historical_date: { precision: "exact", value: "2024-06-09" },
    location_description: "Glossop",
    people: [],
    permissions: {},
    provenance: {},
    tags: [],
  },
  {
    id: "candidate-2",
    media_upload: {
      id: "upload-2",
      client_filename: "paper-hats.jpg",
      uploader: null,
    },
    caption: "Paper hats",
    visibility: "family_space",
    historical_date: { precision: "exact", value: "1994-12-25" },
    location_description: "Ashton-under-Lyne",
    people: [],
    permissions: {},
    provenance: {},
    tags: [],
  },
];

const image = (from: string, to: string) =>
  `data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1200 800'%3E%3Cdefs%3E%3ClinearGradient id='g' x2='1' y2='1'%3E%3Cstop stop-color='${from}'/%3E%3Cstop offset='1' stop-color='${to}'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='1200' height='800' fill='url(%23g)'/%3E%3Ccircle cx='390' cy='350' r='170' fill='%23fff' fill-opacity='.28'/%3E%3Ccircle cx='760' cy='430' r='220' fill='%23000' fill-opacity='.18'/%3E%3C/svg%3E`;

async function mockCollection(page: Page, empty = false) {
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
    } else if (path.endsWith("/collections/collection-1")) {
      data = {
        id: "collection-1",
        name: "William’s 50th birthday",
        description: "The final photographs for William’s birthday book.",
        created_at: "2026-09-26T09:00:00Z",
        photos: empty ? [] : photos,
      };
    } else if (path.endsWith("/photos")) {
      data = candidates;
    } else if (path.endsWith("/exports")) {
      data = [];
    } else if (path.endsWith("/versions")) {
      data = { active_photo_version_id: null, can_edit: false, versions: [] };
    } else if (
      path.includes("/media-uploads/") &&
      path.includes("/variants/")
    ) {
      const url = path.includes("upload-1")
        ? image("%238d4b32", "%23d8b59e")
        : path.includes("upload-2")
          ? image("%235a726c", "%23c8b27e")
          : image("%234e6687", "%23c69c9c");
      data = {
        asset: "variant",
        transform_name: "thumbnail",
        processing_version: 1,
        url,
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
  test(`Collection detail — ${theme}`, async ({ page }) => {
    await mockCollection(page);
    await page.addInitScript((value) => {
      localStorage.setItem("fambam-theme", value);
    }, theme);
    await page.goto("/families/mercer-family-demo/collections/collection-1");
    await expect(
      page.getByRole("heading", { name: "William’s 50th birthday" }),
    ).toBeVisible();
    await expect(page).toHaveScreenshot(`collection-detail-${theme}.png`, {
      fullPage: true,
    });
  });
}

test("Collection detail interaction states", async ({ page }) => {
  await mockCollection(page);
  await page.goto("/families/mercer-family-demo/collections/collection-1");
  await expect(
    page.getByRole("heading", { name: "William’s 50th birthday" }),
  ).toBeVisible();

  await page.getByRole("button", { name: "Rename & describe" }).click();
  const titleInput = page.getByLabel("Collection title");
  await expect(titleInput).toBeFocused();
  await expect
    .poll(() =>
      titleInput.evaluate((element: HTMLInputElement) => ({
        selectionStart: element.selectionStart,
        scrollLeft: element.scrollLeft,
      })),
    )
    .toEqual({ selectionStart: 0, scrollLeft: 0 });
  await expect(page).toHaveScreenshot("collection-detail-rename.png", {
    fullPage: true,
  });

  await page.reload();
  await page.getByRole("button", { name: "Add Photos" }).click();
  await expect(page).toHaveScreenshot("collection-detail-add-photos.png", {
    fullPage: true,
  });

  await page.getByRole("button", { name: "Close" }).click();
  await page.getByRole("button", { name: "Collection options" }).click();
  await page.getByRole("menuitem", { name: "Delete collection" }).click();
  await expect(page).toHaveScreenshot("collection-detail-delete.png", {
    fullPage: true,
  });
});

test("Empty Collection detail", async ({ page }) => {
  await mockCollection(page, true);
  await page.goto("/families/mercer-family-demo/collections/collection-1");
  await expect(
    page.getByRole("heading", { name: "This Collection is empty" }),
  ).toBeVisible();
  await expect(page).toHaveScreenshot("collection-detail-empty.png", {
    fullPage: true,
  });
});
