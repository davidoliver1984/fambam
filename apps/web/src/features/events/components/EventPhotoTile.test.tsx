import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router";
import { afterEach, describe, expect, it, vi } from "vitest";

import {
  getPhotoConversation,
  savePhotoReaction,
} from "@/features/photos/api/photoConversationApi";

import { PhotoTileStats } from "./EventPhotoTile";

vi.mock("@/features/photos/api/photoConversationApi", () => ({
  createPhotoText: vi.fn(),
  getPhotoConversation: vi.fn(),
  removePhotoReaction: vi.fn(),
  removePhotoText: vi.fn(),
  savePhotoReaction: vi.fn(),
  updatePhotoText: vi.fn(),
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("PhotoTileStats", () => {
  it("uses an Album summary without issuing a per-Photo conversation request", async () => {
    vi.mocked(savePhotoReaction).mockResolvedValue(undefined);
    const client = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
        mutations: { retry: false },
      },
    });

    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <PhotoTileStats
            familySlug="family"
            photoId="photo-1"
            albumId="album-1"
            summary={{
              loveCount: 2,
              commentCount: 1,
              viewerHasLoved: false,
              canInteract: true,
            }}
          />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(getPhotoConversation).not.toHaveBeenCalled();
    expect(screen.getByRole("link", { name: "1 comment" })).toHaveAttribute(
      "href",
      "/families/family/photos/photo-1?albumId=album-1",
    );

    await userEvent
      .setup()
      .click(screen.getByRole("button", { name: "Love · 2" }));

    await waitFor(() => {
      expect(savePhotoReaction).toHaveBeenCalledWith(
        "family",
        "photo-1",
        "love",
        "album-1",
      );
    });
    expect(
      screen.getByRole("button", { name: "Remove love · 3" }),
    ).toBeInTheDocument();
  });
});
