import { expect, test, type Page } from "@playwright/test";

const hero =
  "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1200 760'%3E%3Cdefs%3E%3ClinearGradient id='sky' y2='1'%3E%3Cstop stop-color='%2388b8c8'/%3E%3Cstop offset='.58' stop-color='%23d9d2b7'/%3E%3Cstop offset='.59' stop-color='%2369939d'/%3E%3Cstop offset='1' stop-color='%233c6d78'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='1200' height='760' fill='url(%23sky)'/%3E%3Cpath d='M0 520 Q180 470 350 525T720 510T1200 500V760H0Z' fill='%23e7d9bb'/%3E%3Ccircle cx='955' cy='135' r='68' fill='%23f6e5b1' fill-opacity='.8'/%3E%3C/svg%3E";

const family = {
  id: "family-1",
  slug: "mercer-family-demo",
  name: "Mercer Family Demo",
  status: "active",
  role: "owner",
  current_user_person_id: "person-david",
};

const document = {
  schema_version: 1 as const,
  blocks: [
    {
      type: "heading_2" as const,
      content: [{ type: "text" as const, text: "The windiest afternoon" }],
    },
    {
      type: "paragraph" as const,
      content: [
        {
          type: "text" as const,
          text: "Dad insisted his hat was perfectly secure, right until it sailed over the railings. ",
        },
        {
          type: "mention" as const,
          mention_id: "mention-william",
          person_id: "person-william",
          label: "William Mercer",
        },
        {
          type: "text" as const,
          text: " ran after it with the kind of determination normally reserved for the last chip in the paper.",
          marks: ["italic" as const],
        },
      ],
    },
    { type: "horizontal_rule" as const },
    {
      type: "paragraph" as const,
      content: [
        {
          type: "text" as const,
          text: "We never got the hat back, but the story followed us home.",
          marks: ["bold" as const],
        },
      ],
    },
  ],
};

async function mockStory(page: Page) {
  let loved = false;
  let savedBody: unknown = null;
  let postedComment: unknown = null;
  let comments = [
    {
      id: "comment-1",
      body: {
        schema_version: 1,
        blocks: [
          {
            type: "paragraph",
            content: [
              { type: "text", text: "I remember " },
              {
                type: "mention",
                mention_id: "mention-william-comment",
                person_id: "person-william",
                label: "William Mercer",
              },
              { type: "text", text: " pretending he meant to throw it in!" },
            ],
          },
        ],
      },
      body_html:
        '<p>I remember <a href="/families/mercer-family-demo/people/person-william">William Mercer</a> pretending he meant to throw it in!</p>',
      author: {
        id: 2,
        display_name: "Sarah Mercer",
        person_id: "person-sarah",
        initials: "SM",
        portrait_thumbnail_url: null,
      },
      created_at: "2026-09-25T11:00:00Z",
      permissions: { can_remove: true },
    },
    {
      id: "comment-2",
      body: {
        schema_version: 1,
        blocks: [
          {
            type: "paragraph",
            content: [
              {
                type: "text",
                text: "William came back with somebody else’s cap and looked terribly pleased with himself.",
              },
            ],
          },
        ],
      },
      body_html:
        "<p>William came back with somebody else’s cap and looked terribly pleased with himself.</p>",
      author: {
        id: 3,
        display_name: "Jane Mercer",
        person_id: "person-jane",
        initials: "JM",
        portrait_thumbnail_url: null,
      },
      created_at: "2026-09-25T14:00:00Z",
      permissions: { can_remove: false },
    },
  ];

  const story = () => ({
    id: "sea-stole-hat",
    heading: "The day the sea stole Dad’s hat",
    body: (savedBody ?? document) as typeof document,
    body_html:
      savedBody === null
        ? '<h2>The windiest afternoon</h2><p>Dad insisted his hat was perfectly secure, right until it sailed over the railings. <a href="/families/mercer-family-demo/people/person-william">William Mercer</a> <em>ran after it with the kind of determination normally reserved for the last chip in the paper.</em></p><hr><p><strong>We never got the hat back, but the story followed us home.</strong></p>'
        : '<h3><strong>The windiest afternoon</strong></h3><p>Dad insisted his hat was perfectly secure, right until it sailed over the railings. <a href="/families/mercer-family-demo/people/person-william">William Mercer</a> <em>ran after it with the kind of determination normally reserved for the last chip in the paper.</em></p><hr><p><strong>We never got the hat back, but the story followed us home.</strong> <a href="/families/mercer-family-demo/people/person-william">William Mercer</a></p><hr>',
    body_plain_text:
      "The windiest afternoon\n\nDad insisted his hat was perfectly secure, right until it sailed over the railings. William Mercer ran after it with the kind of determination normally reserved for the last chip in the paper.\n\nWe never got the hat back, but the story followed us home.",
    subject: {
      type: "event",
      id: "blackpool",
      label: "Blackpool summer holiday",
    },
    hero: {
      source_type: "event_preview",
      photo_id: "photo-blackpool",
      url: hero,
      method: "GET",
      expires_at: null,
    },
    author: {
      id: 1,
      display_name: "David Mercer",
      person_id: "person-david",
      initials: "DM",
      portrait_thumbnail_url: null,
    },
    comments,
    created_at: "2026-09-26T09:00:00Z",
    edited_at: null,
    permissions: { can_edit: true, can_remove: true },
  });

  await page.route("http://localhost:8082/**", async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname;
    const method = request.method();

    if (path === "/sanctum/csrf-cookie") {
      await route.fulfill({ status: 204 });
      return;
    }

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
      data = [family];
    } else if (path === "/api/families/mercer-family-demo") {
      data = family;
    } else if (path.endsWith("/notifications")) {
      data = [];
    } else if (path.endsWith("/search")) {
      const group = url.searchParams.get("group") ?? "people";
      data = { [group]: { items: [], next_cursor: null } };
    } else if (path.endsWith("/mention-suggestions")) {
      data = [{ id: "person-william", label: "William Mercer" }];
    } else if (path.endsWith("/love") && method === "GET") {
      data = { count: loved ? 8 : 7, loved_by_me: loved, reactors: [] };
    } else if (path.endsWith("/love") && method === "PUT") {
      loved = true;
      data = { count: 8, loved_by_me: true, reactors: [] };
    } else if (path.endsWith("/love") && method === "DELETE") {
      loved = false;
      status = 204;
    } else if (path.endsWith("/comments") && method === "POST") {
      const payload = (await request.postDataJSON()) as { body: unknown };
      postedComment = payload.body;
      comments = [
        ...comments,
        {
          id: "comment-3",
          body: payload.body as (typeof comments)[number]["body"],
          body_html:
            '<p>A new memory with <a href="/families/mercer-family-demo/people/person-william">William Mercer</a>.</p>',
          author: {
            id: 1,
            display_name: "David Mercer",
            person_id: "person-david",
            initials: "DM",
            portrait_thumbnail_url: null,
          },
          created_at: "2026-09-26T12:00:00Z",
          permissions: { can_remove: true },
        },
      ];
      data = comments.at(-1);
      status = 201;
    } else if (path.includes("/comments/") && method === "DELETE") {
      const id = path.split("/").at(-1);
      comments = comments.filter((comment) => comment.id !== id);
      status = 204;
    } else if (path.endsWith("/stories/sea-stole-hat") && method === "PATCH") {
      const payload = (await request.postDataJSON()) as { body: unknown };
      savedBody = payload.body;
      data = story();
    } else if (path.endsWith("/stories/sea-stole-hat") && method === "DELETE") {
      status = 204;
    } else if (path.endsWith("/stories/sea-stole-hat")) {
      data = story();
    }

    if (status === 204) {
      await route.fulfill({ status });
      return;
    }
    await route.fulfill({
      status,
      contentType: "application/json",
      body: JSON.stringify({ data }),
    });
  });

  return {
    getSavedBody: () => savedBody,
    getPostedComment: () => postedComment,
  };
}

for (const theme of ["light", "dark"] as const) {
  test(`Story detail — ${theme}`, async ({ page }, testInfo) => {
    await mockStory(page);
    await page.addInitScript((value) => {
      localStorage.setItem("fambam-theme", value);
    }, theme);
    await page.goto("/families/mercer-family-demo/stories/sea-stole-hat");
    await expect(
      page.getByRole("heading", { name: "The day the sea stole Dad’s hat" }),
    ).toBeVisible();
    await expect(page.getByText("Family conversation")).toBeVisible();

    const article = await page.locator(".story-article").boundingBox();
    const conversation = await page
      .locator(".story-conversation")
      .boundingBox();
    if (testInfo.project.name === "desktop") {
      expect(conversation?.x ?? 0).toBeGreaterThan(
        (article?.x ?? 0) + (article?.width ?? 0),
      );
    } else {
      expect(conversation?.y ?? 0).toBeGreaterThan(
        (article?.y ?? 0) + (article?.height ?? 0),
      );
    }

    await page.screenshot({
      path: testInfo.outputPath(`story-detail-${theme}.png`),
      fullPage: true,
    });
  });
}

test("Story detail interactions preserve canonical behavior", async ({
  page,
}, testInfo) => {
  const state = await mockStory(page);
  await page.context().grantPermissions(["clipboard-read", "clipboard-write"]);
  await page.goto("/families/mercer-family-demo/stories/sea-stole-hat");

  await expect(
    page.getByRole("link", { name: "Blackpool summer holiday" }),
  ).toHaveAttribute("href", "/families/mercer-family-demo/events/blackpool");
  await expect(
    page.getByRole("link", { name: "David Mercer" }).first(),
  ).toHaveAttribute("href", "/families/mercer-family-demo/people/person-david");
  await expect(
    page.getByRole("link", { name: "@William Mercer" }).first(),
  ).toHaveAttribute(
    "href",
    "/families/mercer-family-demo/people/person-william",
  );

  await page.getByRole("button", { name: "Love · 7" }).click();
  await expect(
    page.getByRole("button", { name: "Remove love · 8" }),
  ).toBeVisible();

  const commentEditor = page.getByLabel("Block 1 text 1").last();
  await commentEditor.fill("Remember @Wil");
  await expect(
    page.getByRole("listbox", { name: "Mention a person" }),
  ).toBeVisible();
  await page.getByRole("option", { name: "William Mercer" }).click();
  await page.getByLabel("Send comment").click();
  await expect(page.getByRole("heading", { name: /Comments 3/ })).toBeVisible();
  expect(state.getPostedComment()).toMatchObject({
    blocks: [
      {
        content: expect.arrayContaining([
          expect.objectContaining({
            type: "mention",
            person_id: "person-william",
          }),
        ]),
      },
    ],
  });

  await page
    .locator(".story-comment")
    .first()
    .getByRole("button", { name: "Remove", exact: true })
    .click();
  await expect(page.getByRole("heading", { name: /Comments 2/ })).toBeVisible();

  await page.getByRole("button", { name: "Edit Story" }).click();
  await expect(
    page.getByRole("toolbar", { name: "Edit Story formatting" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Save Story" }).click();
  await expect.poll(state.getSavedBody).toEqual(document);

  const menuTrigger = page.getByRole("button", { name: "Story options" });
  await menuTrigger.click();
  const menu = page.getByRole("menu");
  await expect(menu.getByRole("menuitem")).toHaveText([
    "Open story",
    "Edit story",
    "Copy Fambam link",
    "Delete story",
  ]);
  await page.keyboard.press("Escape");
  await expect(menuTrigger).toBeFocused();

  await menuTrigger.click();
  await menu.getByRole("menuitem", { name: "Copy Fambam link" }).click();
  await expect(page.getByText("Fambam link copied")).toBeVisible();

  await menuTrigger.click();
  await menu.getByRole("menuitem", { name: "Delete story" }).click();
  await expect(
    page.getByRole("dialog", {
      name: "Delete “The day the sea stole Dad’s hat”?",
    }),
  ).toBeVisible();
  await page.screenshot({
    path: testInfo.outputPath("story-delete-confirmation.png"),
  });
  await page.keyboard.press("Escape");
  await expect(page.getByRole("dialog")).toHaveCount(0);

  await menuTrigger.click();
  await menu.getByRole("menuitem", { name: "Delete story" }).click();
  await page
    .getByRole("dialog", {
      name: "Delete “The day the sea stole Dad’s hat”?",
    })
    .getByRole("button", { name: "Delete story" })
    .click();
  await expect(page).toHaveURL("/families/mercer-family-demo/stories");
});

test("continuous Story editor preserves canonical structure", async ({
  page,
}, testInfo) => {
  const state = await mockStory(page);
  await page.addInitScript(() => {
    localStorage.setItem("fambam-theme", "light");
  });
  await page.goto("/families/mercer-family-demo/stories/sea-stole-hat");
  await page.getByRole("button", { name: "Edit Story" }).click();

  const editor = page.getByRole("textbox", { name: "Edit Story" });
  await expect(editor).toBeVisible();
  await expect(
    page.getByRole("toolbar", { name: "Edit Story formatting" }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Continue writing" }),
  ).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Remove block" })).toHaveCount(
    0,
  );
  await page.screenshot({
    path: testInfo.outputPath(`editor-rich-${testInfo.project.name}.png`),
    fullPage: true,
  });

  if (testInfo.project.name !== "desktop") return;

  await editor.locator("h2").evaluate((heading) => {
    const surface = heading.closest<HTMLElement>("[contenteditable='true']");
    surface?.focus();
    const text = heading.firstChild;
    if (text === null) throw new Error("Heading text is missing");
    const range = globalThis.document.createRange();
    range.selectNodeContents(text);
    const selection = window.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(range);
    globalThis.document.dispatchEvent(new Event("selectionchange"));
  });
  const boldButton = page.getByRole("button", { name: "Bold" });
  await boldButton.click();
  await expect(editor.locator("h2 strong, h2 b")).toHaveCount(1);
  await expect(boldButton).toHaveAttribute("aria-pressed", "true");
  await boldButton.click();
  await expect(editor.locator("h2 strong, h2 b")).toHaveCount(0);
  await expect(boldButton).toHaveAttribute("aria-pressed", "false");
  await boldButton.click();
  await expect(editor.locator("h2 strong, h2 b")).toHaveCount(1);

  await editor
    .locator("p")
    .first()
    .evaluate((paragraph) => {
      const text = paragraph.firstChild;
      if (!(text instanceof Text)) throw new Error("Paragraph text is missing");
      const start = text.data.indexOf("his hat");
      const range = globalThis.document.createRange();
      range.setStart(text, start);
      range.setEnd(text, start + "his hat".length);
      const selection = window.getSelection();
      selection?.removeAllRanges();
      selection?.addRange(range);
      globalThis.document.dispatchEvent(new Event("selectionchange"));
    });
  await boldButton.click();
  await expect(boldButton).toHaveAttribute("aria-pressed", "true");
  await expect(editor.locator("p").first().locator("strong")).toHaveText(
    "his hat",
  );
  await boldButton.click();
  await expect(boldButton).toHaveAttribute("aria-pressed", "false");
  await expect(editor.locator("p").first().locator("strong")).toHaveCount(0);

  await editor.locator("h2").evaluate((heading) => {
    const range = globalThis.document.createRange();
    range.selectNodeContents(heading);
    const selection = window.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(range);
    globalThis.document.dispatchEvent(new Event("selectionchange"));
  });
  await page
    .getByRole("combobox", { name: "Text style" })
    .selectOption("heading_3");

  await editor
    .locator("p")
    .last()
    .evaluate((paragraph) => {
      const range = globalThis.document.createRange();
      range.selectNodeContents(paragraph);
      range.collapse(false);
      const selection = window.getSelection();
      selection?.removeAllRanges();
      selection?.addRange(range);
    });
  await editor.pressSequentially(" @Will");
  await page.getByRole("option", { name: "William Mercer" }).click();
  await expect(editor.getByText("@William Mercer")).toHaveCount(2);
  await page.screenshot({
    path: testInfo.outputPath("editor-inserted-mention.png"),
    fullPage: true,
  });

  await page.getByRole("button", { name: "Insert divider" }).click();
  await expect(editor.locator("hr")).toHaveCount(2);
  await page.screenshot({
    path: testInfo.outputPath("editor-divider-light.png"),
    fullPage: true,
  });

  await page.getByRole("button", { name: "Mention a person" }).click();
  await expect(
    page.getByRole("dialog", { name: "Mention a person" }),
  ).toBeVisible();
  await editor.click();
  await expect(
    page.getByRole("dialog", { name: "Mention a person" }),
  ).toHaveCount(0);
  await page.getByRole("button", { name: "Mention a person" }).click();
  await page.getByRole("searchbox", { name: "Find a person" }).fill("Will");
  await expect(
    page.getByRole("dialog", { name: "Mention a person" }),
  ).toBeVisible();
  await page.screenshot({
    path: testInfo.outputPath("editor-mention-picker.png"),
    fullPage: true,
  });
  await page.getByRole("option", { name: "William Mercer" }).click();

  await page.evaluate(() => {
    globalThis.document.documentElement.dataset.theme = "dark";
    localStorage.setItem("fambam-theme", "dark");
  });
  await page.screenshot({
    path: testInfo.outputPath("editor-rich-dark.png"),
    fullPage: true,
  });

  await page.getByRole("button", { name: "Save Story" }).click();
  await expect.poll(state.getSavedBody).not.toBeNull();
  await expect(page.locator(".story-body")).toContainText("William Mercer");
  await expect(page.locator(".story-body hr")).toHaveCount(2);
  const saved = state.getSavedBody() as typeof document;
  expect(saved.blocks[0]).toMatchObject({
    type: "heading_3",
    content: [{ type: "text", marks: ["bold"] }],
  });
  expect(
    saved.blocks.some(
      (block) =>
        block.type !== "horizontal_rule" &&
        block.content.some(
          (node) =>
            node.type === "mention" && node.person_id === "person-william",
        ),
    ),
  ).toBe(true);
  expect(
    saved.blocks.filter((block) => block.type === "horizontal_rule"),
  ).toHaveLength(2);

  await page.getByRole("button", { name: "Edit Story" }).click();
  await editor.evaluate((surface) => {
    surface.innerHTML = "";
    surface.dispatchEvent(new InputEvent("input", { bubbles: true }));
  });
  await page.evaluate(() => {
    globalThis.document.documentElement.dataset.theme = "light";
    localStorage.setItem("fambam-theme", "light");
  });
  await page.screenshot({
    path: testInfo.outputPath("editor-empty-light.png"),
    fullPage: true,
  });
});
