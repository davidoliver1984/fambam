import "@testing-library/jest-dom/vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { FaceIdentityAssignment } from "../types/faceRecognition";
import { FaceSuggestionCard } from "./FaceSuggestionCard";

const assignment: FaceIdentityAssignment = {
  id: "assignment-1",
  status: "pending",
  proposal_source: "automatic_suggestion",
  person: { id: "person-1", preferred_name: "Alex" },
  observation: {
    id: "observation-1",
    face_index: 0,
    bounds: { x: 0.1, y: 0.2, width: 0.3, height: 0.4 },
    photo_id: "photo-1",
    media_upload_id: "upload-1",
    image_width: 1,
    image_height: 1,
    image_url: "http://localhost:8082/canonical",
  },
  created_at: "2026-09-07T10:00:00Z",
};

describe("FaceSuggestionCard", () => {
  it("lets an authoritative reviewer confirm or reject a suggestion", async () => {
    const approve = vi.fn();
    const reject = vi.fn();
    const user = userEvent.setup();
    render(
      <FaceSuggestionCard
        assignment={assignment}
        canResolve
        pending={false}
        onApprove={approve}
        onReject={reject}
      />,
    );

    await user.click(screen.getByRole("button", { name: "Confirm identity" }));
    await user.click(screen.getByRole("button", { name: "Reject identity" }));
    expect(
      document.querySelector<HTMLElement>(".face-observation-image span"),
    ).toHaveStyle({ left: "10%", top: "20%", width: "30%", height: "40%" });
    expect(approve).toHaveBeenCalledOnce();
    expect(reject).toHaveBeenCalledOnce();
  });

  it("shows Members the suggestion without authoritative actions", () => {
    const view = render(
      <FaceSuggestionCard
        assignment={assignment}
        canResolve={false}
        pending={false}
        onApprove={vi.fn()}
        onReject={vi.fn()}
      />,
    );

    expect(
      within(view.container).getByText(/must resolve this suggestion/i),
    ).toBeVisible();
    expect(
      within(view.container).queryByRole("button"),
    ).not.toBeInTheDocument();
  });
});
