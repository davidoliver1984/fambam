import { expect, test, type Page } from "@playwright/test";

const family = {
  id: "family-1",
  slug: "mercer-family-demo",
  name: "Mercer family",
  status: "active",
  role: "owner",
  current_user_person_id: null,
};

const people = [
  { id: "person-william", preferred_name: "William Mercer" },
  { id: "person-jane", preferred_name: "Jane Mercer" },
  { id: "person-david", preferred_name: "David Oliver" },
  { id: "person-mary", preferred_name: "Mary Mercer" },
].map((person) => ({
  ...person,
  alternate_names: [],
  identity_status: "confirmed",
  birth_date: { precision: "unknown", value: null },
  is_deceased: false,
  death_date: { precision: "unknown", value: null },
  biography: null,
  account_link: null,
  redirected_from_person_id: null,
  created_at: "2026-01-01T00:00:00Z",
  updated_at: "2026-01-01T00:00:00Z",
  permissions: {},
}));

const photo = {
  id: "photo-pier",
  media_upload: {
    id: "upload-pier",
    client_filename: "pier.jpg",
    uploader: { id: 1, name: "David Oliver" },
  },
  created_by: 1,
  visibility: "family_space",
  caption: "At the pier",
  description: null,
  archive_source_description: null,
  historical_date: { precision: "exact", value: "1986-08-12" },
  location_description: "Blackpool, Lancashire",
  do_not_resurface: false,
  love_count: 0,
  comment_count: 0,
  album_count: 0,
  provenance: { photographer: {}, scanner: {}, physical_owner: {} },
  tags: [],
  people: [],
  created_at: "2026-01-01T00:00:00Z",
  updated_at: "2026-01-01T00:00:00Z",
  permissions: {},
};

const previewImage =
  "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='800' height='450'%3E%3Crect width='800' height='450' fill='%239bc1cf'/%3E%3C/svg%3E";

function album(name = "Blackpool, 1986") {
  return {
    id: "album-preview",
    name,
    description: "A newly discovered envelope of photographs.",
    visibility: "family_space",
    created_by: 1,
    creator: { id: 1, name: "David Oliver" },
    created_at: "2026-10-02T10:00:00Z",
    updated_at: "2026-10-02T10:00:00Z",
    photo_count: 0,
    starts_on: "1986-08-12",
    ends_on: null,
    location: "Blackpool, Lancashire",
    tags: [{ id: "tag-holiday", label: "holiday" }],
    people: [],
    cover: null,
    cover_pending: false,
    event_id: null,
    event: null,
    guest_participation: "none",
    photos: [],
    grants: [],
    permissions: { can_manage: true, can_contribute: true },
  };
}

async function mockCreateAlbum(page: Page) {
  await page.route("http://localhost:8082/**", async (route) => {
    const url = new URL(route.request().url());
    let data: unknown = [];
    if (url.pathname === "/api/user") {
      data = {
        id: 1,
        name: "David Oliver",
        email: "david@example.test",
        timezone: "Europe/London",
        email_verified_at: "2026-01-01T00:00:00Z",
        can_create_family_spaces: false,
        two_factor_enabled: false,
      };
    } else if (url.pathname === "/api/family-spaces") {
      data = [family];
    } else if (url.pathname === "/api/families/mercer-family-demo") {
      data = family;
    } else if (url.pathname.endsWith("/people")) {
      data = people;
    } else if (url.pathname.endsWith("/photos")) {
      data = [photo];
    } else if (url.pathname.endsWith("/photos/photo-pier/versions")) {
      data = { active_photo_version_id: null, can_edit: true, versions: [] };
    } else if (url.pathname.includes("/media-uploads/upload-pier/variants/")) {
      data = {
        asset: "variant",
        transform_name: url.pathname.endsWith("/thumbnail")
          ? "thumbnail"
          : "display",
        processing_version: 1,
        url: previewImage,
        method: "GET",
        expires_at: "2026-10-02T12:00:00Z",
      };
    } else if (url.pathname.endsWith("/search")) {
      data = { stories: { items: [], next_cursor: null } };
    } else if (
      url.pathname.endsWith("/albums") &&
      route.request().method() === "POST"
    ) {
      const input = route.request().postDataJSON() as { name?: string };
      data = album(input.name);
    } else if (url.pathname.endsWith("/albums/album-preview/love")) {
      data = { count: 0, loved_by_me: false, reactors: [] };
    } else if (url.pathname.endsWith("/albums/album-preview")) {
      data = album();
    }
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ data }),
    });
  });
}

for (const theme of ["light", "dark"] as const) {
  test(`Create Album source flow — ${theme}`, async ({ page }, testInfo) => {
    const pageErrors: string[] = [];
    const consoleErrors: string[] = [];
    page.on("pageerror", (error) => {
      pageErrors.push(error.message);
    });
    page.on("console", (message) => {
      if (message.type() === "error") consoleErrors.push(message.text());
    });
    await page.addInitScript((selectedTheme) => {
      window.localStorage.setItem("fambam-theme", selectedTheme);
    }, theme);
    await mockCreateAlbum(page);
    await page.goto("/families/mercer-family-demo/albums/new");
    await expect(page.locator("html")).toHaveAttribute("data-theme", theme);
    await expect(
      page.getByRole("heading", { name: "Create a new album" }),
    ).toBeVisible();

    const wizard = page.locator(".create-album-wizard");
    const basicsBox = await wizard.boundingBox();
    expect(basicsBox).not.toBeNull();
    expect((basicsBox?.x ?? 0) + (basicsBox?.width ?? 0)).toBeLessThanOrEqual(
      page.viewportSize()?.width ?? 0,
    );

    await page.getByLabel("Album title").fill("Blackpool, 1986");
    await page
      .getByLabel("What’s the story?")
      .fill("A newly discovered envelope of photographs.");
    await page.getByLabel("When was it?").fill("1986-08-12");
    await page.getByLabel("Where?").fill("Blackpool, Lancashire");
    await page.getByLabel("Album tags").fill("holiday");
    await page.getByLabel("Album tags").press("Enter");
    await page.getByRole("button", { name: "Continue" }).click();
    await expect(
      page.getByRole("heading", { name: "Who belongs in this album?" }),
    ).toBeVisible();
    await page.getByLabel("Include William Mercer").check();
    await page.getByLabel("Include Jane Mercer").check();
    await page.getByRole("button", { name: "Continue" }).click();

    await expect(page.getByText("No cover photo yet")).toBeVisible();
    await expect(page.getByText("Blackpool, Lancashire")).toBeVisible();
    const reviewBox = await wizard.boundingBox();
    expect((reviewBox?.x ?? 0) + (reviewBox?.width ?? 0)).toBeLessThanOrEqual(
      page.viewportSize()?.width ?? 0,
    );
    await page.screenshot({
      path: testInfo.outputPath(`create-album-review-${theme}.png`),
      fullPage: true,
    });

    await page.getByRole("button", { name: "Add cover" }).click();
    await expect(
      page.getByRole("heading", { name: "Choose an existing Photo" }),
    ).toBeVisible();
    await page
      .getByRole("button", { name: "Use At the pier as cover" })
      .click();
    const chosenCover = page.locator(".create-album-chosen-cover-image");
    await expect(chosenCover).toHaveAttribute("style", /--cover-y: 50%/);
    await page.getByRole("button", { name: "Reposition" }).click();
    await expect(chosenCover).toHaveAttribute("style", /--cover-y: 30%/);
    await page.getByRole("button", { name: "Remove" }).click();
    await expect(page.getByText("No cover photo yet")).toBeVisible();

    await page.getByRole("button", { name: "Add cover" }).click();
    await page.getByLabel("Upload new cover").setInputFiles({
      name: "cover.png",
      mimeType: "image/png",
      buffer: Buffer.from(
        "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=",
        "base64",
      ),
    });
    await expect(
      page.getByAltText("Selected album cover preview"),
    ).toBeVisible();
    await page.getByRole("button", { name: "Remove" }).click();

    await page.getByRole("button", { name: "Create album" }).click();
    await expect(page).toHaveURL(
      /\/families\/mercer-family-demo\/albums\/album-preview$/,
    );
    await expect(
      page.getByRole("heading", { name: "Blackpool, 1986" }),
    ).toBeVisible();
    await expect(page.getByText("No photos in this Album yet")).toBeVisible();
    expect(pageErrors).toEqual([]);
    expect(consoleErrors).toEqual([]);
  });
}
