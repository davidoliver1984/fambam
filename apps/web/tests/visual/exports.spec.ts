import { expect, test, type Page } from "@playwright/test";

const family = {
  id: "family-1",
  slug: "mercer-family-demo",
  name: "Mercer Family Demo",
  status: "active",
  role: "owner",
  current_user_person_id: "person-david",
};

const user = {
  id: 1,
  name: "David Oliver",
  email: "david@example.test",
  timezone: "Europe/London",
  email_verified_at: "2026-01-01T00:00:00Z",
  can_create_family_spaces: false,
  two_factor_enabled: false,
};

const exports = [
  {
    id: "export-processing",
    scope: "family_space_full",
    state: "processing",
    photo_count: null,
    byte_size: null,
    failure_reason: null,
    expires_at: null,
    created_at: "2026-10-05T07:51:00Z",
  },
  {
    id: "export-ready",
    scope: "personal",
    state: "ready",
    photo_count: 482,
    byte_size: 82_313_830,
    failure_reason: null,
    expires_at: "2099-10-06T08:54:00Z",
    created_at: "2026-10-05T07:42:00Z",
  },
  {
    id: "export-collection",
    scope: "collection",
    collection_id: "collection-1",
    state: "ready",
    photo_count: 24,
    byte_size: 8_925_184,
    failure_reason: null,
    expires_at: "2099-10-06T08:12:00Z",
    created_at: "2026-10-05T07:33:00Z",
  },
  {
    id: "export-expired",
    scope: "album",
    album_id: "album-1",
    state: "expired",
    photo_count: 86,
    byte_size: 28_410_230,
    failure_reason: null,
    expires_at: "2026-10-04T13:18:00Z",
    created_at: "2026-10-03T13:18:00Z",
  },
  {
    id: "export-failed",
    scope: "personal",
    state: "failed",
    photo_count: null,
    byte_size: null,
    failure_reason: "generation_failed",
    expires_at: null,
    created_at: "2026-10-02T10:05:00Z",
  },
];

async function mockExports(page: Page, empty = false) {
  await page.route("http://localhost:8082/api/**", async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    let data: unknown = [];

    if (path === "/api/user") data = user;
    else if (path === "/api/family-spaces") data = [family];
    else if (path === "/api/families/mercer-family-demo") data = family;
    else if (path === "/api/families/mercer-family-demo/exports") {
      data = empty ? [] : exports;
    }

    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ data }),
    });
  });
}

async function openExports(page: Page, theme: "light" | "dark" = "light") {
  await page.addInitScript((value) => {
    localStorage.setItem("fambam-theme", value);
  }, theme);
  await page.goto("/families/mercer-family-demo/exports");
  await expect(
    page.getByRole("heading", { name: "Exports", exact: true }),
  ).toBeVisible();
}

test("Exports — desktop Light", async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== "desktop");
  await mockExports(page);
  await openExports(page);
  await expect(page).toHaveScreenshot("exports-desktop-light.png", {
    fullPage: true,
  });
});

test("Exports — desktop Dark", async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== "desktop");
  await mockExports(page);
  await openExports(page, "dark");
  await expect(page).toHaveScreenshot("exports-desktop-dark.png", {
    fullPage: true,
  });
});

test("Exports — account menu", async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== "desktop");
  await mockExports(page);
  await openExports(page);
  await page.getByLabel("Open account menu for David Oliver").click();
  await expect(page.getByRole("menuitem", { name: "Exports" })).toBeVisible();
  await expect(page).toHaveScreenshot("exports-account-menu.png", {
    fullPage: false,
  });
});

test("Exports — empty state", async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== "desktop");
  await mockExports(page, true);
  await openExports(page);
  await expect(
    page.getByRole("heading", { name: "No exports yet" }),
  ).toBeVisible();
  await expect(page).toHaveScreenshot("exports-empty.png", { fullPage: true });
});

test("Exports — responsive", async ({ page }, testInfo) => {
  test.skip(testInfo.project.name === "desktop");
  await mockExports(page);
  await openExports(page);
  await expect(page).toHaveScreenshot("exports-responsive.png", {
    fullPage: true,
  });
});
