import { expect, test, type Page } from "@playwright/test";

const family = {
  id: "family-1",
  slug: "mercer-family-demo",
  name: "Mercer Family Demo",
  status: "active",
  role: "owner",
  current_user_person_id: "person-david",
};

async function mockShell(page: Page) {
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
      data = [family];
    } else if (path === "/api/families/mercer-family-demo") {
      data = family;
    } else if (path.endsWith("/people")) {
      data = [
        {
          id: "person-david",
          preferred_name: "David Oliver",
          alternate_names: [],
          identity_status: "confirmed",
          birth_date: { precision: "unknown", value: null },
          is_deceased: false,
          death_date: { precision: "unknown", value: null },
          biography: null,
          account_link: {
            id: "link-1",
            account: {
              id: 1,
              name: "David Oliver",
              is_current_user: true,
            },
          },
          redirected_from_person_id: null,
          created_at: "2026-01-01T00:00:00Z",
          updated_at: "2026-01-01T00:00:00Z",
          permissions: {},
        },
      ];
    } else if (path.endsWith("/notifications")) {
      data = [
        {
          id: "notification-1",
          category: "contribution",
          album_id: "album-1",
          photo_id: null,
          story_id: null,
          person_id: null,
          comment_id: null,
          family_export_id: null,
          read_at: null,
          created_at: "2026-09-24T12:00:00Z",
          presentation: {
            actor: {
              person_id: "person-jane",
              display_name: "Jane Mercer",
              initials: "JM",
              portrait_thumbnail_url: null,
            },
            headline: "Jane Mercer added 6 Photos",
            detail: null,
            target_label: "Blackpool, 1986",
            thumbnail_url: null,
            target: { type: "album", id: "album-1" },
          },
        },
      ];
    } else if (path.endsWith("/search")) {
      const group = url.searchParams.get("group") ?? "people";
      const items = {
        people: [
          {
            id: "person-1",
            preferred_name: "William Mercer",
            relationship_to_viewer: "your brother",
            portrait_thumbnail_url: null,
          },
        ],
        albums: [
          {
            id: "album-1",
            name: "Blackpool, 1986",
            description: null,
            visibility: "family_space",
            event_id: null,
            photo_count: 24,
            cover_thumbnail_url: null,
          },
        ],
        events: [
          {
            id: "event-1",
            name: "Blackpool summer holiday",
            description: null,
            location: "Blackpool",
            starts_on: "1986-08-01",
            ends_on: "1986-08-08",
          },
        ],
      }[group];
      data = { [group]: { items, next_cursor: null } };
    }

    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ data }),
    });
  });
}

test.beforeEach(async ({ page }) => {
  await mockShell(page);
  await page.goto("/families/mercer-family-demo/people");
  await expect(
    page.getByRole("link", { name: "Mercer Family Demo home" }),
  ).toBeVisible();
});

test("reconciles the global shell in light and dark themes", async ({
  page,
}, testInfo) => {
  const contentBox = await page.locator(".shell-content").boundingBox();
  expect(contentBox).not.toBeNull();
  expect(contentBox?.width ?? 0).toBeGreaterThan(
    (page.viewportSize()?.width ?? 0) * 0.9,
  );

  await page.screenshot({
    path: testInfo.outputPath("global-shell-light.png"),
    fullPage: true,
  });

  if (testInfo.project.name === "desktop") {
    await page.getByRole("button", { name: "Search family" }).click();
    await expect(
      page.getByRole("dialog", { name: "Search suggestions" }),
    ).toBeVisible();
    const searchPanelBox = await page
      .getByRole("dialog", { name: "Search suggestions" })
      .boundingBox();
    expect(
      (searchPanelBox?.x ?? 0) + (searchPanelBox?.width ?? 0),
    ).toBeLessThanOrEqual(page.viewportSize()?.width ?? 0);
    await expect(
      page.getByRole("searchbox", {
        name: "Search people, places and albums",
      }),
    ).toHaveCSS("outline-style", "none");
    await page.screenshot({
      path: testInfo.outputPath("global-search-light.png"),
    });
    await page.keyboard.press("Escape");

    await page.getByRole("button", { name: "Notifications" }).click();
    await expect(
      page.getByRole("dialog", { name: "Notifications" }),
    ).toBeVisible();
    const notificationPanelBox = await page
      .getByRole("dialog", { name: "Notifications" })
      .boundingBox();
    expect(
      (notificationPanelBox?.x ?? 0) + (notificationPanelBox?.width ?? 0),
    ).toBeLessThanOrEqual(page.viewportSize()?.width ?? 0);
    await page.screenshot({
      path: testInfo.outputPath("notifications-light.png"),
    });
    await page.keyboard.press("Escape");

    await page.getByLabel(/Open account menu for David/).click();
    await expect(
      page.getByRole("menuitem", { name: "View my Person page" }),
    ).toBeEnabled();
    await page.screenshot({
      path: testInfo.outputPath("account-menu-light.png"),
    });
    await page.getByRole("menuitem", { name: "Dark mode" }).click();
    await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
    await page.getByRole("button", { name: "Notifications" }).click();
    await expect(
      page.getByRole("button", { name: "Mark all as read" }),
    ).toHaveCSS("color", "rgb(221, 161, 122)");
    await page.screenshot({
      path: testInfo.outputPath("global-shell-dark.png"),
      fullPage: true,
    });
  } else if (testInfo.project.name === "mobile") {
    const menu = page.getByRole("button", { name: "Show navigation" });
    await menu.click();
    await expect(
      page.getByRole("navigation", { name: "Main navigation" }),
    ).toHaveClass(/mobile-open/);
    await page.screenshot({
      path: testInfo.outputPath("global-shell-mobile-nav.png"),
    });
    await page.getByRole("button", { name: "Hide navigation" }).click();
    await page.getByRole("button", { name: "Search family" }).click();
    const searchPanel = page.getByRole("dialog", {
      name: "Search suggestions",
    });
    await expect(searchPanel).toBeVisible();
    const searchBox = await searchPanel.boundingBox();
    expect(searchBox?.x).toBe(12);
    expect(searchBox?.width).toBe((page.viewportSize()?.width ?? 24) - 24);
    await page.screenshot({
      path: testInfo.outputPath("global-search-mobile.png"),
    });
  }
});
