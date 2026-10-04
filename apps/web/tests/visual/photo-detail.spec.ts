import { expect, test, type Page } from "@playwright/test";

const photograph =
  "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1448 1086'%3E%3Cdefs%3E%3ClinearGradient id='sky' y2='1'%3E%3Cstop stop-color='%238bb3c4'/%3E%3Cstop offset='.65' stop-color='%23d9c4a4'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='1448' height='1086' fill='url(%23sky)'/%3E%3Cpath d='M0 700Q250 610 500 690T950 660T1448 700V1086H0Z' fill='%23645843'/%3E%3Ccircle cx='440' cy='570' r='120' fill='%23e7d2bc'/%3E%3Ccircle cx='760' cy='550' r='140' fill='%237a4a36'/%3E%3Ccircle cx='1040' cy='590' r='115' fill='%23d5b99d'/%3E%3C/svg%3E";

const photo = {
  id: "photo-pier",
  media_upload: {
    id: "upload-pier",
    client_filename: "at-the-pier.jpg",
    uploader: { id: 1, name: "David Mercer" },
  },
  created_by: 1,
  visibility: "family_space",
  caption: "At the pier",
  description:
    "Robert managed to get everyone looking at the camera at once — almost.",
  archive_source_description: "Mercer family album",
  historical_date: { precision: "exact", value: "1986-08-14" },
  location_description: "North Pier, Blackpool",
  do_not_resurface: false,
  provenance: {
    photographer: { person: null, description: "Robert Mercer" },
    scanner: { person: null, description: "David Mercer" },
    physical_owner: { person: null, description: "Jane Mercer" },
  },
  tags: [{ id: "tag-1", label: "Blackpool" }],
  people: [
    "William Mercer",
    "Jane Mercer",
    "Robert Mercer",
    "Margaret Shaw",
    "Sarah Mercer",
  ].map((preferred_name, index) => ({
    id: `association-${String(index)}`,
    photo_id: "photo-pier",
    person: { id: `person-${String(index)}`, preferred_name },
    proposal_source: "human",
    status: "approved",
    proposed_by: 1,
    resolved_by: 1,
    resolved_at: "2026-09-12T12:00:00Z",
    created_at: "2026-09-12T12:00:00Z",
  })),
  identified_faces: [
    {
      id: "face-sarah",
      person: { id: "person-4", preferred_name: "Sarah Mercer" },
      bounds: { x: 930, y: 465, width: 220, height: 245 },
      image_width: 1448,
      image_height: 1086,
    },
  ],
  created_at: "2026-09-12T12:00:00Z",
  updated_at: "2026-09-12T12:00:00Z",
  permissions: {
    can_update: true,
    can_propose_provenance: true,
    can_resolve_provenance: false,
    can_manage_tags: true,
    can_flag_duplicate: false,
  },
};

const albumPhotos = Array.from({ length: 24 }, (_, index) => ({
  id: index === 11 ? photo.id : `photo-${String(index + 1)}`,
  media_upload_id: `upload-${String(index + 1)}`,
  caption:
    index === 11 ? photo.caption : `Blackpool memory ${String(index + 1)}`,
  client_filename: `photo-${String(index + 1)}.jpg`,
  visibility: "family_space",
  position: index,
}));

const album = {
  id: "album-blackpool",
  name: "Blackpool, 1986",
  description: "A week by the sea.",
  visibility: "family_space",
  created_by: 1,
  created_at: "2026-09-25T12:00:00Z",
  updated_at: "2026-09-25T12:00:00Z",
  is_new: true,
  guest_participation: "none",
  photos: albumPhotos,
  grants: [],
  permissions: { can_manage: true, can_contribute: true },
};

async function mockPhoto(page: Page) {
  let loved = true;
  const comments = [
    {
      id: "comment-1",
      parent_comment_id: null,
      is_deleted: false,
      body: "Mum looks so happy here.",
      body_html: "<p>Mum looks so happy here.</p>",
      author: {
        id: 2,
        name: "Sarah Mercer",
        person_id: "person-4",
        initials: "SM",
        portrait_thumbnail_url: null,
      },
      edited_at: null,
      created_at: "2026-09-25T12:00:00Z",
      permissions: { can_edit: false, can_remove: false },
      replies: [
        {
          id: "reply-1",
          parent_comment_id: "comment-1",
          is_deleted: false,
          body: "She really was — even in that wind!",
          body_html: "<p>She really was — even in that wind!</p>",
          author: {
            id: 3,
            name: "Jane Mercer",
            person_id: "person-1",
            initials: "JM",
            portrait_thumbnail_url: null,
          },
          edited_at: null,
          created_at: "2026-09-25T12:15:00Z",
          permissions: { can_edit: false, can_remove: false },
        },
      ],
    },
    {
      id: "comment-2",
      parent_comment_id: null,
      is_deleted: false,
      body: "It was absolutely freezing!",
      body_html: "<p>It was absolutely freezing!</p>",
      author: {
        id: 3,
        name: "Jane Mercer",
        person_id: "person-1",
        initials: "JM",
        portrait_thumbnail_url: null,
      },
      edited_at: null,
      created_at: "2026-09-25T12:30:00Z",
      permissions: { can_edit: false, can_remove: false },
      replies: [],
    },
  ];

  await page.addInitScript(() => {
    Object.defineProperty(navigator, "share", {
      configurable: true,
      value: () => Promise.resolve(),
    });
    Object.defineProperty(navigator, "clipboard", {
      configurable: true,
      value: {
        writeText: (value: string) => {
          sessionStorage.setItem("copied-link", value);
          return Promise.resolve();
        },
      },
    });
  });

  await page.route(
    "http://localhost:8082/sanctum/csrf-cookie",
    async (route) => {
      await route.fulfill({ status: 204 });
    },
  );

  await page.route("http://localhost:8082/api/**", async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname;
    let data: unknown = [];

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
    } else if (path.endsWith("/photos/photo-pier/album-history")) {
      data = [
        {
          event_type: "added",
          album: { id: album.id, name: album.name },
          actor: {
            display_name: "David Mercer",
            person_id: "person-david",
            initials: "DM",
            portrait_thumbnail_url: null,
          },
          created_at: "2026-09-14T12:00:00Z",
          is_current: true,
        },
        {
          event_type: "added",
          album: { id: "album-portraits", name: "Family portraits" },
          actor: {
            display_name: "Sarah Mercer",
            person_id: "person-4",
            initials: "SM",
            portrait_thumbnail_url: null,
          },
          created_at: "2026-09-12T12:00:00Z",
          is_current: true,
        },
      ];
    } else if (
      path.endsWith("/photos/photo-pier/comments") &&
      request.method() === "POST"
    ) {
      const payload = request.postDataJSON() as {
        body: string;
        parent_comment_id?: string;
      };
      const created = {
        id: `comment-${String(comments.length + 1)}`,
        parent_comment_id: null,
        is_deleted: false,
        body: payload.body,
        body_html: `<p>${payload.body}</p>`,
        author: {
          id: 1,
          name: "David Mercer",
          person_id: "person-david",
          initials: "DM",
          portrait_thumbnail_url: null,
        },
        edited_at: null,
        created_at: new Date().toISOString(),
        permissions: { can_edit: true, can_remove: true },
        replies: [],
      };
      comments.push(created);
      data = created;
    } else if (path.endsWith("/photos/photo-pier/conversation")) {
      data = {
        stories: [],
        comments,
        reactions: loved
          ? [
              { user_id: 1, name: "David Mercer", reaction: "love" },
              { user_id: 2, name: "Sarah Mercer", reaction: "love" },
            ]
          : [{ user_id: 2, name: "Sarah Mercer", reaction: "love" }],
        permissions: { can_interact: true, can_author_story: true },
        conversation_scope: "album",
        album_id: album.id,
      };
    } else if (path.endsWith("/photos/photo-pier/reaction")) {
      loved = request.method() !== "DELETE";
      data = null;
    } else if (path.endsWith("/photos/photo-pier")) {
      data = photo;
    } else if (path.endsWith(`/albums/${album.id}`)) {
      data = album;
    } else if (path.endsWith("/albums")) {
      data = {
        items: [
          album,
          {
            ...album,
            id: "album-portraits",
            name: "Family portraits",
            photos: [],
          },
        ],
        next_cursor: null,
      };
    } else if (path.endsWith("/people")) {
      data = photo.people.map((item) => ({
        ...item.person,
        alternate_names: [],
        identity_status: "confirmed",
        birth_date: { precision: "unknown", value: null },
        is_deceased: false,
        death_date: { precision: "unknown", value: null },
        biography: null,
        account_link: null,
        redirected_from_person_id: null,
        created_at: photo.created_at,
        updated_at: photo.updated_at,
        permissions: {},
      }));
    } else if (
      path.endsWith("/collections") ||
      path.endsWith("/notifications")
    ) {
      data = [];
    } else if (path.endsWith("/versions")) {
      data = { active_photo_version_id: null, can_edit: true, versions: [] };
    } else if (path.includes("/variants/") || path.endsWith("/original")) {
      data = {
        asset: path.endsWith("/original") ? "original" : "variant",
        transform_name: "display",
        processing_version: 1,
        url: photograph,
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
  test(`Photo detail — ${theme}`, async ({ page }, testInfo) => {
    await mockPhoto(page);
    await page.addInitScript((value) => {
      localStorage.setItem("fambam-theme", value);
    }, theme);
    await page.goto(
      `/families/mercer-family-demo/photos/${photo.id}?albumId=${album.id}`,
    );
    await expect(
      page.getByRole("heading", { name: "At the pier" }),
    ).toBeVisible();
    await expect(page.getByText("Album history")).toBeVisible();
    await expect(
      page.getByRole("region", { name: "People in this Photo" }),
    ).toBeVisible();
    await expect(page).toHaveScreenshot(`photo-detail-${theme}.png`, {
      fullPage: true,
    });

    if (theme === "light" && testInfo.project.name === "desktop") {
      await expect(page.locator(".photo-album-history")).toHaveScreenshot(
        "photo-detail-album-history-light.png",
      );
      await expect(page.locator(".photo-people")).toHaveScreenshot(
        "photo-detail-people-light.png",
      );
      await expect(page.locator(".photo-conversation")).toHaveScreenshot(
        "photo-detail-conversation-light.png",
      );
      await expect(page.locator(".photo-comment__replies")).toHaveScreenshot(
        "photo-detail-reply-thread-light.png",
      );

      await page
        .getByRole("region", { name: "People in this Photo" })
        .getByRole("button", { name: "1 more" })
        .click();
      await page
        .getByRole("region", { name: "People in this Photo" })
        .getByRole("link", { name: "Sarah Mercer", exact: true })
        .hover();
      await expect(
        page.getByRole("link", { name: "View Sarah Mercer" }).first(),
      ).toBeVisible();
      await expect(page.locator(".photo-detail-image")).toHaveScreenshot(
        "photo-detail-face-hover-light.png",
      );

      const menuButton = page.getByRole("button", { name: "Photo options" });
      await menuButton.click();
      await expect(
        page.getByRole("menuitem", { name: "Add to album…" }),
      ).toBeVisible();
      await expect(
        page.getByRole("menuitem", { name: "Delete Photo" }),
      ).toBeVisible();
      await expect(page).toHaveScreenshot("photo-detail-menu-light.png", {
        fullPage: true,
      });
      await page.keyboard.press("Escape");
      await expect(menuButton).toBeFocused();

      const chooseMenuItem = async (name: string) => {
        await menuButton.click();
        await page.getByRole("menuitem", { name, exact: true }).click();
      };

      await chooseMenuItem("Add to album…");
      await expect(
        page.getByRole("dialog", { name: "Add to album" }),
      ).toBeVisible();
      await page.keyboard.press("Escape");

      await chooseMenuItem("Remove from Blackpool, 1986");
      await expect(
        page.getByRole("dialog", { name: "Remove from Blackpool, 1986?" }),
      ).toBeVisible();
      await page.keyboard.press("Escape");

      await chooseMenuItem("Add to collection…");
      await expect(
        page.getByRole("dialog", { name: "Add to collection" }),
      ).toBeVisible();
      await page.keyboard.press("Escape");

      await chooseMenuItem("Edit photo");
      await expect(
        page.getByRole("dialog", { name: "Edit “At the pier”" }),
      ).toBeVisible();
      await page.keyboard.press("Escape");

      await chooseMenuItem("Edit details");
      await expect(
        page.getByRole("dialog", { name: "Edit Photo details" }),
      ).toBeVisible();
      await page.keyboard.press("Escape");

      await chooseMenuItem("Identify / Review people");
      await expect(
        page.getByRole("dialog", { name: "Identify / Review people" }),
      ).toBeVisible();
      await page.keyboard.press("Escape");

      const originalRequest = page.waitForRequest((request) =>
        request.url().endsWith("/original"),
      );
      await chooseMenuItem("Download original");
      await originalRequest;

      await chooseMenuItem("Copy Fambam link");
      await expect
        .poll(() => page.evaluate(() => sessionStorage.getItem("copied-link")))
        .toMatch(/\/families\/mercer-family-demo\/photos\/photo-pier$/);

      await chooseMenuItem("Delete Photo");
      await expect(
        page.getByRole("dialog", { name: "Delete Photo?" }),
      ).toBeVisible();
      await page.keyboard.press("Escape");

      await page.getByRole("button", { name: /love/i }).click();
      await page.getByLabel("Add a comment").fill("A wonderful memory.");
      const commentRequest = page.waitForRequest(
        (request) =>
          request.method() === "POST" && request.url().endsWith("/comments"),
      );
      await page.getByRole("button", { name: "Send comment" }).click();
      expect((await commentRequest).postDataJSON()).toMatchObject({
        body: "A wonderful memory.",
        album_id: album.id,
      });
      await page.getByRole("button", { name: "Share this Photo" }).click();
    }
  });
}
