import { expect, test, type Page } from "@playwright/test";

const preview =
  "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 800 600'%3E%3Cdefs%3E%3ClinearGradient id='g' x2='1' y2='1'%3E%3Cstop stop-color='%238d4b32'/%3E%3Cstop offset='1' stop-color='%23d8b59e'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='800' height='600' fill='url(%23g)'/%3E%3Ccircle cx='320' cy='280' r='150' fill='%23f8f3ea' fill-opacity='.42'/%3E%3C/svg%3E";

const photograph = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLkWQAAAABJRU5ErkJggg==",
  "base64",
);

const uploadIds: Record<string, string> = {
  "blackpool-pier.jpg": "01KUPLOAD00000000000000001",
  "family-railings.jpg": "01KUPLOAD00000000000000002",
  "william-on-beach.jpg": "01KUPLOAD00000000000000003",
  "grandma-pink-coat.jpg": "01KUPLOAD00000000000000004",
};

async function mockUpload(page: Page) {
  let batchId = "01KBATCH000000000000000000";
  await page.route("**/fake-upload/**", (route) =>
    route.fulfill({ status: 200, body: "" }),
  );
  await page.route("http://localhost:8082/**", async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname;
    let data: unknown = [];
    let status = 200;

    if (path === "/sanctum/csrf-cookie") {
      await route.fulfill({ status: 204, body: "" });
      return;
    }
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
    } else if (
      path === "/api/families/mercer-family-demo/media-uploads" &&
      request.method() === "POST"
    ) {
      const body = request.postDataJSON() as {
        client_filename: string;
        upload_batch_id: string;
      };
      batchId = body.upload_batch_id;
      const id = uploadIds[body.client_filename];
      data = {
        id,
        state: "initiated",
        client_filename: body.client_filename,
        byte_size: null,
        uploaded_at: null,
        upload_batch_id: batchId,
        upload_authorization: {
          url: `http://127.0.0.1:4173/fake-upload/${id}`,
          method: "PUT",
          headers: {},
          expires_at: "2026-09-26T12:00:00Z",
        },
      };
      status = 201;
    } else if (path.endsWith("/complete")) {
      const id = path.split("/").at(-2) ?? "";
      const filename =
        Object.entries(uploadIds).find(
          ([, candidate]) => candidate === id,
        )?.[0] ?? "photograph.jpg";
      data = {
        id,
        state: "uploaded",
        client_filename: filename,
        byte_size: 1024,
        uploaded_at: "2026-09-26T10:00:00Z",
        upload_batch_id: batchId,
        upload_authorization: null,
      };
    } else if (path.includes("/media-upload-batches/")) {
      data = {
        batch_id: batchId,
        total: 4,
        active: true,
        counts: { ready: 2, processing: 1, preserved: 1 },
        items: [
          {
            id: uploadIds["blackpool-pier.jpg"],
            client_filename: "blackpool-pier.jpg",
            state: "ready",
            byte_size: 1024,
            uploaded_at: "2026-09-26T10:00:00Z",
          },
          {
            id: uploadIds["family-railings.jpg"],
            client_filename: "family-railings.jpg",
            state: "processing",
            byte_size: 1024,
            uploaded_at: "2026-09-26T10:00:00Z",
          },
          {
            id: uploadIds["william-on-beach.jpg"],
            client_filename: "william-on-beach.jpg",
            state: "preserved",
            byte_size: 1024,
            uploaded_at: "2026-09-26T10:00:00Z",
          },
          {
            id: uploadIds["grandma-pink-coat.jpg"],
            client_filename: "grandma-pink-coat.jpg",
            state: "ready",
            byte_size: 1024,
            uploaded_at: "2026-09-26T10:00:00Z",
          },
        ],
      };
    } else if (path.endsWith("/photos") && request.method() === "POST") {
      const body = request.postDataJSON() as { media_upload_id: string };
      if (body.media_upload_id === uploadIds["grandma-pink-coat.jpg"]) {
        data = {
          outcome: "duplicate_detected",
          candidates: [
            {
              id: "candidate-1",
              caption: "William on the promenade",
              visibility: "family_space",
              client_filename: "existing.jpg",
              created_at: "2026-08-01T12:00:00Z",
            },
          ],
        };
        status = 409;
      } else {
        data = { outcome: "photo_created", photo: { id: "photo-1" } };
        status = 201;
      }
    } else if (path.endsWith("/photos/candidate-1")) {
      data = {
        id: "candidate-1",
        media_upload: {
          id: "existing-upload",
          client_filename: "existing.jpg",
          uploader: null,
        },
        caption: "William on the promenade",
        visibility: "family_space",
        tags: [],
        people: [],
        permissions: {},
      };
    } else if (path.endsWith("/photos/candidate-1/versions")) {
      data = { active_photo_version_id: null, can_edit: false, versions: [] };
    } else if (path.endsWith("/media-uploads/existing-upload/variants/card")) {
      data = {
        asset: "variant",
        transform_name: "card",
        processing_version: 1,
        url: preview,
        method: "GET",
        expires_at: "2026-09-26T12:00:00Z",
      };
    }

    await route.fulfill({
      status,
      contentType: "application/json",
      body: JSON.stringify({ data }),
    });
  });
}

for (const theme of ["light", "dark"] as const) {
  test(`upload queue and duplicate dialog — ${theme}`, async ({
    page,
  }, testInfo) => {
    await mockUpload(page);
    await page.addInitScript((selectedTheme) => {
      localStorage.setItem("fambam-theme", selectedTheme);
    }, theme);
    await page.goto("/families/mercer-family-demo/uploads");
    await expect(
      page.getByRole("heading", { name: "Add photographs" }),
    ).toBeVisible();
    await page.locator("#media-file").setInputFiles(
      Object.keys(uploadIds).map((name) => ({
        name,
        mimeType: "image/png",
        buffer: photograph,
      })),
    );
    await expect(
      page.getByRole("button", { name: "Review duplicate" }),
    ).toBeVisible();
    await page.screenshot({
      path: testInfo.outputPath(`upload-queue-${theme}.png`),
      fullPage: true,
    });

    await page.getByRole("button", { name: "Review duplicate" }).click();
    const dialog = page.getByRole("dialog", {
      name: "This photo is already in Fambam",
    });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel("Use the existing Photo")).toBeChecked();
    await expect(dialog.getByLabel("Create a separate Photo")).toBeVisible();
    await page.screenshot({
      path: testInfo.outputPath(`upload-duplicate-${theme}.png`),
      fullPage: true,
    });
  });
}
