import { expect, test, type Page } from "@playwright/test";

import type { FaceReviewSession } from "../../src/features/face-recognition/types/faceRecognition.js";

const photograph =
  "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1200 800'%3E%3Cdefs%3E%3ClinearGradient id='sky' y2='1'%3E%3Cstop stop-color='%23aecbd8'/%3E%3Cstop offset='.62' stop-color='%23e6d8c7'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='1200' height='800' fill='url(%23sky)'/%3E%3Cpath d='M0 510Q250 460 520 520T1200 500V800H0Z' fill='%23685a4c'/%3E%3Ccircle cx='300' cy='370' r='105' fill='%23e1c3aa'/%3E%3Ccircle cx='570' cy='350' r='110' fill='%238f5a43'/%3E%3Ccircle cx='835' cy='390' r='100' fill='%23d8b99e'/%3E%3Ccircle cx='1010' cy='300' r='90' fill='%23b38d76'/%3E%3C/svg%3E";

const people = ["William Mercer", "Sarah Mercer", "Jane Mercer"].map(
  (preferred_name, index) => ({
    id: `person-${String(index + 1)}`,
    preferred_name,
    alternate_names: [],
    identity_status: "confirmed",
    birth_date: { precision: "unknown", value: null },
    is_deceased: false,
    death_date: { precision: "unknown", value: null },
    biography: null,
    account_link: null,
    redirected_from_person_id: null,
    created_at: "2026-10-02T12:00:00Z",
    updated_at: "2026-10-02T12:00:00Z",
    permissions: {},
  }),
);

async function mockFaceReview(
  page: Page,
  options: {
    photoScoped?: boolean;
    semanticStates?: boolean;
    completed?: boolean;
  } = {},
) {
  await page.route("http://localhost:8082/**", async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
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
      data = [];
    } else if (path === "/api/families/mercer-family-demo") {
      data = {
        id: "family-1",
        slug: "mercer-family-demo",
        name: "Mercer Family Demo",
        status: "active",
        role: "owner",
        current_user_person_id: "person-1",
      };
    } else if (path.endsWith("/people")) {
      data = people;
    } else if (path.endsWith("/face-review")) {
      const session = {
        scope: { upload_batch_id: "batch-blackpool", photo_id: null },
        summary: {
          total_photos: 8,
          analysis: {
            pending: 0,
            processing: 0,
            succeeded: 8,
            failed: 0,
            succeeded_with_zero_faces: 0,
            succeeded_with_unresolved_faces: 3,
            succeeded_with_all_faces_resolved: 5,
          },
          total_faces: 12,
          reviewed_count: 7,
          remaining_count: 5,
          reviewable_photo_count: 3,
          current_photo_id: "photo-pier",
          next_photo_id: "photo-promenade",
        },
        photos: [
          {
            photo_id: "photo-pier",
            upload_batch_id: "batch-blackpool",
            caption: "At the pier",
            display_label: "At the pier",
            media: {
              media_upload_id: "upload-pier",
              canonical_width: 1200,
              canonical_height: 800,
              presentation_width: 1200,
              presentation_height: 800,
              presentation_url: photograph,
              presentation_expires_at: "2026-10-02T13:00:00Z",
              fallback_delivery_endpoint: "/api/fallback",
            },
            analysis: {
              state: "succeeded",
              review_state: "succeeded_with_unresolved_faces",
              succeeded_with_zero_faces: false,
            },
            detected_face_count: 4,
            reviewed_count: 2,
            remaining_count: 2,
            observations: (
              [
                ["face-1", 0, 220, 280, 155, 210, null],
                ["face-2", 1, 480, 270, 150, 205, people[1]],
                ["face-3", 2, 735, 285, 150, 205, null],
                ["face-4", 3, 930, 205, 140, 195, people[2]],
              ] satisfies Array<
                [
                  string,
                  number,
                  number,
                  number,
                  number,
                  number,
                  (typeof people)[number] | null,
                ]
              >
            ).map(([id, face_index, x, y, width, height, person]) => ({
              id,
              face_index,
              bounds: { x, y, width, height },
              review_state:
                person === null ? "unreviewed" : "approved_identity",
              reviewed: person !== null,
              suggested_people: [],
              current_proposal: null,
              current_identity:
                person === null
                  ? null
                  : {
                      id: `assignment-${String(face_index)}`,
                      status: "approved",
                      proposal_source: "human",
                      person,
                    },
              identity_assignment:
                person === null
                  ? null
                  : {
                      id: `assignment-${String(face_index)}`,
                      status: "approved",
                      proposal_source: "human",
                      person,
                    },
              permissions: {
                can_assign: person === null,
                can_change: true,
                can_leave_unidentified: person === null,
                can_approve: false,
                can_reject: false,
              },
            })),
          },
          {
            photo_id: "photo-promenade",
            upload_batch_id: "batch-blackpool",
            caption: "On the promenade",
            display_label: "On the promenade",
            media: {
              media_upload_id: "upload-promenade",
              canonical_width: 1200,
              canonical_height: 800,
              presentation_width: 1200,
              presentation_height: 800,
              presentation_url: photograph,
              presentation_expires_at: "2026-10-02T13:00:00Z",
              fallback_delivery_endpoint: "/api/fallback",
            },
            analysis: {
              state: "succeeded",
              review_state: "succeeded_with_unresolved_faces",
              succeeded_with_zero_faces: false,
            },
            detected_face_count: 1,
            reviewed_count: 0,
            remaining_count: 1,
            observations: [
              {
                id: "face-5",
                face_index: 0,
                bounds: { x: 420, y: 230, width: 180, height: 240 },
                review_state: "unreviewed",
                reviewed: false,
                suggested_people: [],
                current_proposal: null,
                current_identity: null,
                identity_assignment: null,
                permissions: {
                  can_assign: true,
                  can_change: true,
                  can_leave_unidentified: true,
                  can_approve: false,
                  can_reject: false,
                },
              },
            ],
          },
        ],
        pagination: { page: 1, limit: 100, has_more: false },
      } as FaceReviewSession;
      const firstPhoto = session.photos[0];
      if (options.semanticStates === true) {
        const [automatic, human, approved, leftUnidentified] =
          firstPhoto.observations;
        Object.assign(automatic, {
          review_state: "automatic_suggestion",
          reviewed: false,
          suggested_people: [people[0]],
          current_proposal: null,
          current_identity: null,
          identity_assignment: {
            id: "assignment-automatic",
            status: "pending",
            proposal_source: "automatic_suggestion",
            person: people[0],
          },
        });
        Object.assign(human, {
          review_state: "human_proposal",
          reviewed: true,
          suggested_people: [],
          current_proposal: {
            id: "assignment-human",
            status: "pending",
            proposal_source: "human",
            person: people[1],
          },
          current_identity: null,
          identity_assignment: {
            id: "assignment-human",
            status: "pending",
            proposal_source: "human",
            person: people[1],
          },
        });
        Object.assign(approved, {
          review_state: "approved_identity",
          reviewed: true,
          suggested_people: [],
          current_proposal: null,
          current_identity: {
            id: "assignment-approved",
            status: "approved",
            proposal_source: "human",
            person: people[0],
          },
          identity_assignment: {
            id: "assignment-approved",
            status: "approved",
            proposal_source: "human",
            person: people[0],
          },
        });
        Object.assign(leftUnidentified, {
          review_state: "left_unidentified",
          reviewed: true,
          suggested_people: [],
          current_proposal: null,
          current_identity: null,
          identity_assignment: null,
        });
        firstPhoto.reviewed_count = 3;
        firstPhoto.remaining_count = 1;
      }
      if (options.completed === true) {
        for (const observation of firstPhoto.observations) {
          if (observation.review_state === "unreviewed") {
            observation.review_state = "left_unidentified";
            observation.reviewed = true;
          }
        }
        firstPhoto.reviewed_count = firstPhoto.detected_face_count;
        firstPhoto.remaining_count = 0;
        firstPhoto.analysis.review_state = "succeeded_with_all_faces_resolved";
      }
      if (options.photoScoped === true) {
        session.scope = {
          upload_batch_id: null,
          photo_id: firstPhoto.photo_id,
        };
        session.summary = {
          total_photos: 1,
          analysis: {
            pending: 0,
            processing: 0,
            succeeded: 1,
            failed: 0,
            succeeded_with_zero_faces: 0,
            succeeded_with_unresolved_faces:
              firstPhoto.remaining_count > 0 ? 1 : 0,
            succeeded_with_all_faces_resolved:
              firstPhoto.remaining_count === 0 ? 1 : 0,
          },
          total_faces: firstPhoto.detected_face_count,
          reviewed_count: firstPhoto.reviewed_count,
          remaining_count: firstPhoto.remaining_count,
          reviewable_photo_count: firstPhoto.remaining_count > 0 ? 1 : 0,
          current_photo_id:
            firstPhoto.remaining_count > 0 ? firstPhoto.photo_id : null,
          next_photo_id: null,
        };
        session.photos = [firstPhoto];
      }
      data = session;
    } else if (path.endsWith("/identity-suggestions")) {
      data = {
        observation_id: "face-1",
        band: "shortlist",
        assignment_id: null,
        candidates: people.slice(0, 3).map(({ id, preferred_name }) => ({
          id,
          preferred_name,
        })),
      };
    } else if (path.endsWith("/identity-assignments")) {
      data = {};
      status = 201;
    }
    await route.fulfill({
      status,
      contentType: "application/json",
      body: JSON.stringify({ data }),
    });
  });
}

async function mockUploadHandoff(page: Page) {
  const uploadId = "01KUPLOAD00000000000000001";
  let batchId = "01KBATCH000000000000000000";
  await page.route("**/fake-upload", (route) =>
    route.fulfill({ status: 200, body: "" }),
  );
  await page.route("http://localhost:8082/**", async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
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
      data = [];
    } else if (path === "/api/families/mercer-family-demo") {
      data = {
        id: "family-1",
        slug: "mercer-family-demo",
        name: "Mercer Family Demo",
        status: "active",
        role: "owner",
        current_user_person_id: "person-1",
      };
    } else if (path.endsWith("/media-uploads") && request.method() === "POST") {
      const body = request.postDataJSON() as { upload_batch_id: string };
      batchId = body.upload_batch_id;
      data = {
        id: uploadId,
        state: "initiated",
        client_filename: "blackpool-pier.jpg",
        byte_size: null,
        uploaded_at: null,
        upload_batch_id: batchId,
        upload_authorization: {
          url: "http://127.0.0.1:4173/fake-upload",
          method: "PUT",
          headers: {},
          expires_at: "2026-10-02T13:00:00Z",
        },
      };
      status = 201;
    } else if (path.endsWith("/complete")) {
      data = {
        id: uploadId,
        state: "uploaded",
        client_filename: "blackpool-pier.jpg",
        byte_size: 1024,
        uploaded_at: "2026-10-02T12:00:00Z",
        upload_batch_id: batchId,
        upload_authorization: null,
      };
    } else if (path.includes("/media-upload-batches/")) {
      data = {
        batch_id: batchId,
        total: 1,
        active: false,
        counts: { ready: 1 },
        face_review: {
          analysis_pending: false,
          analysis: {
            pending: 0,
            processing: 0,
            succeeded: 1,
            failed: 0,
            succeeded_with_zero_faces: 0,
          },
          has_reviewable_faces: true,
          reviewable_face_count: 1,
          affected_photo_count: 1,
          zero_detected_faces: false,
        },
        items: [
          {
            id: uploadId,
            state: "ready",
            client_filename: "blackpool-pier.jpg",
            byte_size: 1024,
            uploaded_at: "2026-10-02T12:00:00Z",
          },
        ],
      };
    } else if (path.endsWith("/photos") && request.method() === "POST") {
      data = { outcome: "photo_created", photo: { id: "photo-pier" } };
      status = 201;
    }
    await route.fulfill({
      status,
      contentType: "application/json",
      body: JSON.stringify({ data }),
    });
  });
}

test("face review frozen-reference layout and picker", async ({
  page,
}, testInfo) => {
  await mockFaceReview(page);
  await page.goto(
    "/families/mercer-family-demo/photos/review-people?upload_batch_id=batch-blackpool",
  );
  await expect(page.getByText("7 of 12 faces reviewed")).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Identify face 1" }),
  ).toBeVisible();
  await page.screenshot({
    path: testInfo.outputPath("face-review-initial.png"),
    fullPage: true,
  });

  await page.getByRole("button", { name: "Identify face 1" }).click();
  await expect(
    page.getByRole("dialog", { name: "Identify this face" }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: /William Mercer/ }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: /William Mercer/ }).locator("b"),
  ).toHaveCSS("color", "rgb(47, 36, 30)");
  await page.screenshot({
    path: testInfo.outputPath("face-review-picker.png"),
    fullPage: true,
  });
});

test("photo-scoped review shows truthful semantic states and stays on one Photo", async ({
  page,
}, testInfo) => {
  await mockFaceReview(page, { photoScoped: true, semanticStates: true });
  await page.goto(
    "/families/mercer-family-demo/photos/review-people?photo_id=photo-pier&return_to=%2Ffamilies%2Fmercer-family-demo%2Fphotos%2Fphoto-pier",
  );
  await expect(page.getByText("3 of 4 faces reviewed")).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Review suggested identity for face 1" }),
  ).toBeVisible();
  await expect(page.getByText("Pending review")).toBeVisible();
  await expect(page.getByText("William Mercer")).toBeVisible();
  await expect(page.getByText("Unidentified")).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Next photograph" }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("button", { name: "Skip photograph" }),
  ).toHaveCount(0);
  await page.screenshot({
    path: testInfo.outputPath("photo-scoped-semantic-states.png"),
    fullPage: true,
  });

  await page
    .getByRole("button", { name: "Review suggested identity for face 1" })
    .click();
  await expect(
    page.getByRole("dialog", { name: "Identify this face" }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: /William Mercer/ }).locator("svg"),
  ).toHaveCount(0);
  await page.screenshot({
    path: testInfo.outputPath("photo-scoped-picker.png"),
    fullPage: true,
  });
});

test("photo-scoped completion remains on the requested Photo", async ({
  page,
}, testInfo) => {
  await mockFaceReview(page, { photoScoped: true, completed: true });
  await page.goto(
    "/families/mercer-family-demo/photos/review-people?photo_id=photo-pier&return_to=%2Ffamilies%2Fmercer-family-demo%2Fphotos%2Fphoto-pier",
  );
  await expect(
    page.getByText("All faces in this photograph have been reviewed."),
  ).toBeVisible();
  await expect(page.getByRole("button", { name: /Done/ })).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Next photograph" }),
  ).toHaveCount(0);
  await page.screenshot({
    path: testInfo.outputPath("photo-scoped-complete.png"),
    fullPage: true,
  });
});

test("ready uploads show the approved People review handoff", async ({
  page,
}, testInfo) => {
  await mockUploadHandoff(page);
  await page.goto("/families/mercer-family-demo/uploads");
  await page.locator("#media-file").setInputFiles({
    name: "blackpool-pier.jpg",
    mimeType: "image/jpeg",
    buffer: Buffer.from(
      "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLkWQAAAABJRU5ErkJggg==",
      "base64",
    ),
  });
  await expect(
    page.getByText("Faces found in your ready photographs"),
  ).toBeVisible();
  await expect(page.getByRole("link", { name: "Review people" })).toBeVisible();
  await page.screenshot({
    path: testInfo.outputPath("upload-review-handoff.png"),
    fullPage: true,
  });
});
