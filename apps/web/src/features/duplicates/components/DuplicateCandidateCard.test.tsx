import "@testing-library/jest-dom/vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router";
import { describe, expect, it, vi } from "vitest";

import type { DuplicateCandidate } from "../types/duplicate";
import { DuplicateCandidateCard } from "./DuplicateCandidateCard";

const summary = {
  id: "photo-1",
  media_upload_id: "upload-1",
  caption: "Family lunch",
  client_filename: "lunch.jpg",
  visibility: "family_space" as const,
  created_at: "2026-08-26T10:00:00Z",
  image_url: "http://localhost:8082/canonical-1",
};

describe("DuplicateCandidateCard", () => {
  it("offers only leave-for-later and not-a-duplicate actions", async () => {
    const onIgnore = vi.fn();
    const onDismiss = vi.fn();
    const candidate: DuplicateCandidate = {
      id: "candidate-1",
      source: "perceptual",
      algorithm: "dhash-luma-64",
      processing_version: 1,
      score: 8,
      photo: summary,
      candidate_photo: {
        ...summary,
        id: "photo-2",
        media_upload_id: "upload-2",
        image_url: "http://localhost:8082/canonical-2",
      },
      created_at: "2026-08-26T10:00:00Z",
    };
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <DuplicateCandidateCard
          candidate={candidate}
          familySlug="family-archive"
          pending={false}
          onIgnore={onIgnore}
          onDismiss={onDismiss}
        />
      </MemoryRouter>,
    );

    expect(screen.getAllByRole("img")).toHaveLength(2);
    expect(
      screen.queryByRole("button", { name: /merge|consolidate|delete/i }),
    ).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Leave for later" }));
    await user.click(screen.getByRole("button", { name: "Not a duplicate" }));
    expect(onIgnore).toHaveBeenCalledOnce();
    expect(onDismiss).toHaveBeenCalledOnce();
  });
});
