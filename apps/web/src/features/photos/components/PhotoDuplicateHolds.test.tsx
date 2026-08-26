import "@testing-library/jest-dom/vitest";
import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import {
  usePhotoDuplicateHoldsQuery,
  useResolvePhotoDuplicateHoldMutation,
} from "../hooks/usePhotoDuplicateHolds";
import { PhotoDuplicateHolds } from "./PhotoDuplicateHolds";

vi.mock("../hooks/usePhotoDuplicateHolds", () => ({
  usePhotoDuplicateHoldsQuery: vi.fn(),
  useResolvePhotoDuplicateHoldMutation: vi.fn(),
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("PhotoDuplicateHolds", () => {
  it("requires explicit visibility-widening confirmation before reusing a private Photo", async () => {
    const mutate = vi.fn();
    vi.mocked(usePhotoDuplicateHoldsQuery).mockReturnValue({
      isPending: false,
      isError: false,
      data: [
        {
          id: "hold-1",
          media_upload: { id: "upload-1", client_filename: "new.jpg" },
          target_album: {
            id: "album-1",
            name: "Family Album",
            visibility: "family_space",
          },
          detected_at: "2026-08-26T10:00:00Z",
          candidates: [
            {
              id: "photo-1",
              caption: "Existing memory",
              visibility: "private",
              client_filename: "existing.jpg",
              created_at: "2026-08-25T10:00:00Z",
            },
          ],
        },
      ],
    } as ReturnType<typeof usePhotoDuplicateHoldsQuery>);
    vi.mocked(useResolvePhotoDuplicateHoldMutation).mockReturnValue({
      isPending: false,
      mutate,
    } as unknown as ReturnType<typeof useResolvePhotoDuplicateHoldMutation>);

    const user = userEvent.setup();
    render(<PhotoDuplicateHolds familySlug="family-archive" />);
    const useExisting = screen.getByRole("button", {
      name: "Use existing Photo",
    });
    expect(useExisting).toBeDisabled();
    await user.click(
      screen.getByLabelText(/adding this private Photo will widen/i),
    );
    expect(useExisting).toBeEnabled();
    await user.click(useExisting);
    expect(mutate).toHaveBeenCalledWith({
      holdId: "hold-1",
      resolution: "use_existing",
      existing_photo_id: "photo-1",
      confirm_visibility_widening: true,
    });
  });
});
