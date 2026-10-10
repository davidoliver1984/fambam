import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  render,
  screen,
  waitFor,
  within,
} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import type { Person } from "@/features/people/types/person";
import type { FaceReviewSession } from "../types/faceRecognition";
import { FaceReviewPage } from "./FaceReviewPage";

const api = vi.hoisted(() => ({
  getFaceReview: vi.fn(),
  generateFaceIdentitySuggestions: vi.fn(),
  proposeFaceIdentity: vi.fn(),
  leaveFaceUnidentified: vi.fn(),
  getFaceClusters: vi.fn(),
  getFaceIdentityAssignments: vi.fn(),
  getFaceIdentitySuppressions: vi.fn(),
  approveFaceIdentityAssignment: vi.fn(),
  rejectFaceIdentityAssignment: vi.fn(),
  reopenFaceIdentitySuppression: vi.fn(),
  mergeFaceClusters: vi.fn(),
  nameFaceCluster: vi.fn(),
  splitFaceCluster: vi.fn(),
}));

const peopleApi = vi.hoisted(() => ({
  getPeople: vi.fn(),
  createPerson: vi.fn(),
}));

vi.mock("../api/faceRecognitionApi", () => api);
vi.mock("@/features/people/api/personApi", () => peopleApi);

const person: Person = {
  id: "person-william",
  preferred_name: "William Mercer",
  alternate_names: [],
  identity_status: "confirmed",
  birth_date: { precision: "unknown", value: null },
  birth_place: null,
  is_deceased: false,
  death_date: { precision: "unknown", value: null },
  death_place: null,
  residence_place: null,
  biography: null,
  profile_quote: null,
  profile_quote_attribution: null,
  known_for: [],
  featured_albums: [],
  relationships: [],
  recognition_summary: {
    recognised_photo_count: 0,
    viewer_identification_count: 0,
    review_destination: null,
  },
  account_link: null,
  redirected_from_person_id: null,
  created_at: "2026-10-02T12:00:00Z",
  updated_at: "2026-10-02T12:00:00Z",
  permissions: {
    can_update_authoritatively: true,
    can_propose_changes: true,
    can_resolve_proposals: true,
    can_propose_account_link: true,
    can_manage_account_link: true,
    can_propose_relationships: true,
    can_manage_relationships: true,
    can_propose_merge: true,
    can_manage_merge: true,
  },
};

const reviewPermissions = {
  can_assign: true,
  can_change: true,
  can_leave_unidentified: true,
  can_approve: false,
  can_reject: false,
};

const session: FaceReviewSession = {
  scope: { upload_batch_id: "batch-one", photo_id: null, person_id: null },
  summary: {
    total_photos: 2,
    analysis: {
      pending: 0,
      processing: 0,
      succeeded: 2,
      failed: 0,
      succeeded_with_zero_faces: 0,
      succeeded_with_unresolved_faces: 2,
      succeeded_with_all_faces_resolved: 0,
    },
    total_faces: 3,
    reviewed_count: 1,
    remaining_count: 2,
    reviewable_photo_count: 2,
    current_photo_id: "photo-one",
    next_photo_id: "photo-two",
  },
  photos: [
    {
      photo_id: "photo-one",
      upload_batch_id: "batch-one",
      caption: "At the pier",
      display_label: "At the pier",
      media: {
        media_upload_id: "upload-one",
        canonical_width: 1200,
        canonical_height: 800,
        presentation_width: 900,
        presentation_height: 600,
        presentation_url: "https://storage.test/pier",
        presentation_expires_at: "2026-10-02T13:00:00Z",
        fallback_delivery_endpoint: "/api/fallback-one",
      },
      analysis: {
        state: "succeeded",
        review_state: "succeeded_with_unresolved_faces",
        succeeded_with_zero_faces: false,
      },
      detected_face_count: 2,
      reviewed_count: 1,
      remaining_count: 1,
      observations: [
        {
          id: "face-one",
          face_index: 0,
          bounds: { x: 120, y: 80, width: 240, height: 320 },
          review_state: "unreviewed",
          reviewed: false,
          suggested_people: [],
          current_proposal: null,
          current_identity: null,
          identity_assignment: null,
          permissions: reviewPermissions,
        },
        {
          id: "face-two",
          face_index: 1,
          bounds: { x: 720, y: 120, width: 180, height: 240 },
          review_state: "human_proposal",
          reviewed: true,
          suggested_people: [],
          current_proposal: {
            id: "assignment-existing",
            status: "pending",
            proposal_source: "human",
            person: { id: "person-sarah", preferred_name: "Sarah Mercer" },
          },
          current_identity: null,
          identity_assignment: {
            id: "assignment-existing",
            status: "pending",
            proposal_source: "human",
            person: { id: "person-sarah", preferred_name: "Sarah Mercer" },
          },
          permissions: reviewPermissions,
        },
      ],
    },
    {
      photo_id: "photo-two",
      upload_batch_id: "batch-one",
      caption: null,
      display_label: "promenade.jpg",
      media: {
        media_upload_id: "upload-two",
        canonical_width: 1000,
        canonical_height: 1000,
        presentation_width: 800,
        presentation_height: 800,
        presentation_url: null,
        presentation_expires_at: null,
        fallback_delivery_endpoint: "/api/fallback-two",
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
          id: "face-three",
          face_index: 0,
          bounds: { x: 200, y: 200, width: 300, height: 300 },
          review_state: "unreviewed",
          reviewed: false,
          suggested_people: [],
          current_proposal: null,
          current_identity: null,
          identity_assignment: null,
          permissions: reviewPermissions,
        },
      ],
    },
  ],
  pagination: { page: 1, limit: 100, has_more: false },
};

function renderPage(
  entry = "/families/mercer/photos/review-people?upload_batch_id=batch-one&return_to=%2Ffamilies%2Fmercer%2Fuploads",
) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const router = createMemoryRouter(
    [
      {
        path: "/families/:familySlug/photos/review-people",
        element: <FaceReviewPage />,
      },
      { path: "/families/:familySlug/uploads", element: <p>Uploads</p> },
      {
        path: "/families/:familySlug/photos/:photoId",
        element: <p>Photo detail</p>,
      },
    ],
    { initialEntries: [entry] },
  );
  return {
    router,
    ...render(
      <QueryClientProvider client={queryClient}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    ),
  };
}

beforeEach(() => {
  api.getFaceReview.mockResolvedValue(session);
  api.generateFaceIdentitySuggestions.mockResolvedValue({
    observation_id: "face-one",
    band: "shortlist",
    assignment_id: null,
    candidates: [{ id: person.id, preferred_name: person.preferred_name }],
  });
  api.proposeFaceIdentity.mockResolvedValue({
    id: "assignment-new",
    status: "pending",
    proposal_source: "human",
    person: { id: person.id, preferred_name: person.preferred_name },
    observation: {
      id: "face-one",
      face_index: 0,
      bounds: { x: 120, y: 80, width: 240, height: 320 },
      media_upload_id: "upload-one",
      image_width: 1200,
      image_height: 800,
      image_url: "https://storage.test/pier",
    },
    created_at: "2026-10-02T12:00:00Z",
  });
  api.leaveFaceUnidentified.mockResolvedValue({
    observation_id: "face-one",
    review_state: "left_unidentified",
    reviewed_at: "2026-10-02T12:00:00Z",
  });
  peopleApi.getPeople.mockResolvedValue({ items: [person], next_cursor: null });
  peopleApi.createPerson.mockResolvedValue({
    ...person,
    id: "person-new",
    preferred_name: "Jane Mercer",
  });
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("FaceReviewPage", () => {
  it("renders the frozen workspace from the bounded batch review model", async () => {
    renderPage();

    expect(await screen.findByText("People to identify")).toBeInTheDocument();
    expect(screen.getByText("1 of 3 faces reviewed")).toBeInTheDocument();
    expect(screen.getByRole("img", { name: "At the pier" })).toHaveAttribute(
      "src",
      "https://storage.test/pier",
    );
    const face = screen.getByRole("button", { name: "Identify face 1" });
    expect(face.parentElement).toHaveStyle({
      left: "10%",
      top: "10%",
      width: "20%",
      height: "40%",
    });
    expect(
      screen.getByRole("button", {
        name: "Change proposed identity for face 2, currently Sarah Mercer",
      }),
    ).toBeEnabled();
    expect(screen.getByText("Sarah Mercer")).toBeInTheDocument();
    expect(
      screen.getByText("2 faces detected · 1 still to identify"),
    ).toBeInTheDocument();
    expect(api.getFaceReview).toHaveBeenCalledWith(
      "mercer",
      { uploadBatchId: "batch-one", limit: 100, page: 1 },
      expect.any(AbortSignal),
    );
  });

  it("loads suggestions on face selection and records a human proposal", async () => {
    const user = userEvent.setup();
    renderPage();
    await user.click(
      await screen.findByRole("button", { name: "Identify face 1" }),
    );

    expect(
      await screen.findByRole("dialog", { name: "Identify this face" }),
    ).toBeInTheDocument();
    expect(api.generateFaceIdentitySuggestions).toHaveBeenCalledWith(
      "mercer",
      "face-one",
    );
    await user.type(screen.getByPlaceholderText("Search people…"), "William");
    await user.click(screen.getByRole("button", { name: /William Mercer/ }));

    await waitFor(() => {
      expect(api.proposeFaceIdentity).toHaveBeenCalledWith(
        "mercer",
        "face-one",
        "person-william",
      );
    });
    expect(await screen.findByRole("status")).toHaveTextContent(
      "William Mercer proposed for identity review.",
    );
  });

  it("turns an explicit selection of a machine suggestion into a human proposal", async () => {
    api.getFaceReview.mockResolvedValue({
      ...session,
      photos: [
        {
          ...session.photos[0],
          observations: [
            {
              ...session.photos[0].observations[0],
              review_state: "automatic_suggestion",
              reviewed: false,
              suggested_people: [
                { id: person.id, preferred_name: person.preferred_name },
              ],
              identity_assignment: {
                id: "automatic-assignment",
                status: "pending",
                proposal_source: "automatic_suggestion",
                person: {
                  id: person.id,
                  preferred_name: person.preferred_name,
                },
              },
            },
            session.photos[0].observations[1],
          ],
        },
        session.photos[1],
      ],
    });
    const user = userEvent.setup();
    renderPage();
    await user.click(
      await screen.findByRole("button", {
        name: "Review suggested identity for face 1",
      }),
    );
    await screen.findByText("Suggested");
    const suggestedPerson = await screen.findByRole("button", {
      name: /William Mercer/,
    });
    expect(suggestedPerson.querySelector("svg")).toBeNull();
    await user.click(suggestedPerson);

    expect(api.generateFaceIdentitySuggestions).not.toHaveBeenCalled();
    expect(api.proposeFaceIdentity).toHaveBeenCalledWith(
      "mercer",
      "face-one",
      "person-william",
    );
    expect(await screen.findByRole("status")).toHaveTextContent(
      "William Mercer proposed for identity review.",
    );
  });

  it("rejects a selection response that bypasses a pending human proposal", async () => {
    api.proposeFaceIdentity.mockResolvedValue({
      id: "assignment-invalid",
      status: "approved",
      proposal_source: "automatic_suggestion",
      person: { id: person.id, preferred_name: person.preferred_name },
      observation: {
        id: "face-one",
        face_index: 0,
        bounds: { x: 120, y: 80, width: 240, height: 320 },
        media_upload_id: "upload-one",
        image_width: 1200,
        image_height: 800,
        image_url: "https://storage.test/pier",
      },
      created_at: "2026-10-02T12:00:00Z",
    });
    const user = userEvent.setup();
    renderPage();
    await user.click(
      await screen.findByRole("button", { name: "Identify face 1" }),
    );
    await user.click(
      await screen.findByRole("button", { name: /William Mercer/ }),
    );

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Something went wrong. Please try again.",
    );
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  it("closes the picker with Escape from any picker control and restores face focus", async () => {
    const user = userEvent.setup();
    renderPage();
    const face = await screen.findByRole("button", { name: "Identify face 1" });
    await user.click(face);
    await screen.findByRole("dialog", { name: "Identify this face" });
    await user.tab();
    await user.keyboard("{Escape}");

    expect(
      screen.queryByRole("dialog", { name: "Identify this face" }),
    ).not.toBeInTheDocument();
    await waitFor(() => {
      expect(face).toHaveFocus();
    });
  });

  it("creates a real Person before proposing them and can leave a face unidentified", async () => {
    const user = userEvent.setup();
    renderPage();
    await user.click(
      await screen.findByRole("button", { name: "Identify face 1" }),
    );
    await user.click(
      screen.getByRole("button", { name: "Create a new Person" }),
    );
    await user.type(screen.getByLabelText("New Person name"), "Jane Mercer");
    await user.click(screen.getByRole("button", { name: "Create" }));

    await waitFor(() => {
      expect(peopleApi.createPerson).toHaveBeenCalledWith(
        "mercer",
        expect.objectContaining({ preferred_name: "Jane Mercer" }),
      );
      expect(api.proposeFaceIdentity).toHaveBeenCalledWith(
        "mercer",
        "face-one",
        "person-new",
      );
    });

    await user.click(screen.getByRole("button", { name: "Identify face 1" }));
    await user.click(
      screen.getByRole("button", { name: "Leave unidentified" }),
    );
    expect(api.leaveFaceUnidentified).toHaveBeenCalledWith(
      "mercer",
      "face-one",
    );
  });

  it("skips without writing, advances in canonical order, and finishes to context", async () => {
    const user = userEvent.setup();
    const { router } = renderPage();
    await screen.findByRole("img", { name: "At the pier" });
    await user.click(screen.getByRole("button", { name: "Skip photograph" }));

    expect(await screen.findByText("promenade.jpg")).toBeInTheDocument();
    expect(api.proposeFaceIdentity).not.toHaveBeenCalled();
    expect(api.leaveFaceUnidentified).not.toHaveBeenCalled();
    await user.click(screen.getByRole("button", { name: "Finish later" }));
    expect(router.state.location.pathname).toBe("/families/mercer/uploads");
  });

  it("continues through bounded batch pages without family-wide aggregation", async () => {
    api.getFaceReview
      .mockResolvedValueOnce({
        ...session,
        photos: [session.photos[0]],
        pagination: { page: 1, limit: 100, has_more: true },
      })
      .mockResolvedValueOnce({
        ...session,
        photos: [session.photos[1]],
        pagination: { page: 2, limit: 100, has_more: false },
      });
    const user = userEvent.setup();
    renderPage();
    await screen.findByRole("img", { name: "At the pier" });
    await user.click(screen.getByRole("button", { name: "Next photograph" }));

    expect(await screen.findByText("promenade.jpg")).toBeInTheDocument();
    expect(api.getFaceReview).toHaveBeenCalledWith(
      "mercer",
      { uploadBatchId: "batch-one", limit: 100, page: 2 },
      expect.any(AbortSignal),
    );
  });

  it("keeps Photo-scoped review on one Photo and returns Done to its detail", async () => {
    api.getFaceReview.mockResolvedValue({
      ...session,
      scope: {
        upload_batch_id: null,
        photo_id: "photo-one",
        person_id: null,
      },
      summary: {
        ...session.summary,
        total_photos: 1,
        current_photo_id: "photo-one",
        next_photo_id: null,
      },
      photos: [session.photos[0]],
    });
    const user = userEvent.setup();
    const { router } = renderPage(
      "/families/mercer/photos/review-people?photo_id=photo-one&return_to=%2Ffamilies%2Fmercer%2Fphotos%2Fphoto-one",
    );

    await screen.findByRole("img", { name: "At the pier" });
    expect(
      screen.queryByRole("button", { name: "Skip photograph" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Next photograph" }),
    ).not.toBeInTheDocument();
    expect(api.getFaceReview).toHaveBeenCalledWith(
      "mercer",
      { photoId: "photo-one", limit: 100, page: 1 },
      expect.any(AbortSignal),
    );
    await user.click(screen.getByRole("button", { name: /Done/ }));
    expect(router.state.location.pathname).toBe(
      "/families/mercer/photos/photo-one",
    );
  });

  it("replaces batch navigation with Done after every face is reviewed", async () => {
    api.getFaceReview.mockResolvedValue({
      ...session,
      summary: {
        ...session.summary,
        reviewed_count: session.summary.total_faces,
        remaining_count: 0,
        reviewable_photo_count: 0,
        next_photo_id: null,
      },
      photos: session.photos.map((photo) => ({
        ...photo,
        reviewed_count: photo.detected_face_count,
        remaining_count: 0,
      })),
      pagination: { page: 1, limit: 100, has_more: false },
    });
    const user = userEvent.setup();
    const { router } = renderPage();

    await screen.findByRole("img", { name: "At the pier" });
    expect(
      screen.queryByRole("button", { name: "Skip photograph" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Next photograph" }),
    ).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /Done/ }));
    expect(router.state.location.pathname).toBe("/families/mercer/uploads");
  });

  it("uses explicit permission fields for approved-identity correction", async () => {
    api.getFaceReview.mockResolvedValue({
      ...session,
      photos: [
        {
          ...session.photos[0],
          observations: [
            {
              ...session.photos[0].observations[0],
              review_state: "approved_identity",
              reviewed: true,
              current_identity: {
                id: "approved-assignment",
                status: "approved",
                proposal_source: "human",
                person: {
                  id: person.id,
                  preferred_name: person.preferred_name,
                },
              },
              identity_assignment: {
                id: "approved-assignment",
                status: "approved",
                proposal_source: "human",
                person: {
                  id: person.id,
                  preferred_name: person.preferred_name,
                },
              },
              permissions: {
                ...reviewPermissions,
                can_assign: false,
                can_change: false,
                can_leave_unidentified: false,
              },
            },
          ],
        },
      ],
    });
    renderPage("/families/mercer/photos/review-people?photo_id=photo-one");

    expect(
      await screen.findByRole("button", {
        name: "Face 1 identified as William Mercer",
      }),
    ).toBeDisabled();
  });

  it("allows an authorized approved identity to become a new human proposal", async () => {
    api.getFaceReview.mockResolvedValue({
      ...session,
      photos: [
        {
          ...session.photos[0],
          observations: [
            {
              ...session.photos[0].observations[0],
              review_state: "approved_identity",
              reviewed: true,
              current_identity: {
                id: "approved-assignment",
                status: "approved",
                proposal_source: "human",
                person: {
                  id: person.id,
                  preferred_name: person.preferred_name,
                },
              },
              identity_assignment: {
                id: "approved-assignment",
                status: "approved",
                proposal_source: "human",
                person: {
                  id: person.id,
                  preferred_name: person.preferred_name,
                },
              },
              permissions: {
                ...reviewPermissions,
                can_assign: false,
                can_leave_unidentified: false,
              },
            },
          ],
        },
      ],
    });
    const user = userEvent.setup();
    renderPage("/families/mercer/photos/review-people?photo_id=photo-one");

    await user.click(
      await screen.findByRole("button", {
        name: "Change identity for face 1, currently William Mercer",
      }),
    );
    expect(await screen.findByText("Current identity")).toBeInTheDocument();
    await user.click(
      within(
        screen.getByRole("dialog", { name: "Identify this face" }),
      ).getByRole("button", { name: /William Mercer/ }),
    );
    expect(api.proposeFaceIdentity).toHaveBeenCalledWith(
      "mercer",
      "face-one",
      "person-william",
    );
  });

  it.each([
    ["pending", "Face analysis has not started yet."],
    ["processing", "Face analysis is still processing."],
    ["failed", "Face analysis is unavailable for this photograph."],
  ] as const)("shows the real %s analysis state", async (state, copy) => {
    api.getFaceReview.mockResolvedValue({
      ...session,
      summary: {
        ...session.summary,
        total_faces: 0,
        reviewed_count: 0,
        remaining_count: 0,
        current_photo_id: null,
        next_photo_id: null,
        analysis: {
          pending: state === "pending" ? 1 : 0,
          processing: state === "processing" ? 1 : 0,
          succeeded: 0,
          failed: state === "failed" ? 1 : 0,
          succeeded_with_zero_faces: 0,
          succeeded_with_unresolved_faces: 0,
          succeeded_with_all_faces_resolved: 0,
        },
      },
      photos: [
        {
          ...session.photos[0],
          analysis: {
            state,
            review_state: state,
            succeeded_with_zero_faces: false,
          },
          detected_face_count: 0,
          reviewed_count: 0,
          remaining_count: 0,
          observations: [],
        },
      ],
    });
    renderPage("/families/mercer/photos/review-people?photo_id=photo-one");
    expect(await screen.findByText(copy)).toBeInTheDocument();
  });

  it("shows a truthful zero-face result", async () => {
    api.getFaceReview.mockResolvedValue({
      ...session,
      summary: {
        ...session.summary,
        total_faces: 0,
        reviewed_count: 0,
        remaining_count: 0,
        current_photo_id: null,
        next_photo_id: null,
        analysis: {
          pending: 0,
          processing: 0,
          succeeded: 1,
          failed: 0,
          succeeded_with_zero_faces: 1,
          succeeded_with_unresolved_faces: 0,
          succeeded_with_all_faces_resolved: 0,
        },
      },
      photos: [
        {
          ...session.photos[0],
          analysis: {
            state: "succeeded",
            review_state: "succeeded_with_zero_faces",
            succeeded_with_zero_faces: true,
          },
          detected_face_count: 0,
          reviewed_count: 0,
          remaining_count: 0,
          observations: [],
        },
      ],
    });
    renderPage("/families/mercer/photos/review-people?photo_id=photo-one");
    expect(
      await screen.findByText("No faces were detected in this photograph."),
    ).toBeInTheDocument();
  });

  it("shows when every detected face is already resolved", async () => {
    api.getFaceReview.mockResolvedValue({
      ...session,
      summary: {
        ...session.summary,
        total_photos: 1,
        total_faces: 1,
        reviewed_count: 1,
        remaining_count: 0,
        reviewable_photo_count: 0,
        current_photo_id: null,
        next_photo_id: null,
      },
      photos: [
        {
          ...session.photos[0],
          analysis: {
            ...session.photos[0].analysis,
            review_state: "succeeded_with_all_faces_resolved",
          },
          detected_face_count: 1,
          reviewed_count: 1,
          remaining_count: 0,
          observations: [session.photos[0].observations[1]],
        },
      ],
    });
    renderPage("/families/mercer/photos/review-people?photo_id=photo-one");
    expect(
      await screen.findByText(
        "All faces in this photograph have been reviewed.",
      ),
    ).toBeInTheDocument();
  });
});
