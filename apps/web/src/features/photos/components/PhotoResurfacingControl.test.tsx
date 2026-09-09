import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import { memoryKeys } from "@/features/memories/api/memoryKeys";
import { updatePhoto } from "@/features/photos/api/photoApi";
import { PhotoResurfacingControl } from "@/features/photos/components/PhotoResurfacingControl";
import type { Photo } from "@/features/photos/types/photo";

vi.mock("@/features/photos/api/photoApi", () => ({ updatePhoto: vi.fn() }));

function renderControl(excluded = false) {
  const queryClient = new QueryClient({
    defaultOptions: { mutations: { retry: false } },
  });
  const invalidate = vi.spyOn(queryClient, "invalidateQueries");

  render(
    <QueryClientProvider client={queryClient}>
      <PhotoResurfacingControl
        familySlug="mercer-family"
        photoId="photo-1"
        excluded={excluded}
      />
    </QueryClientProvider>,
  );

  return { invalidate };
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("PhotoResurfacingControl", () => {
  it("excludes a Photo and refreshes both memory queries", async () => {
    vi.mocked(updatePhoto).mockResolvedValue({
      id: "photo-1",
      do_not_resurface: true,
    } as Photo);
    const { invalidate } = renderControl();

    await userEvent.click(
      screen.getByRole("button", { name: "Exclude from memories" }),
    );

    await waitFor(() => {
      expect(updatePhoto).toHaveBeenCalledWith("mercer-family", "photo-1", {
        do_not_resurface: true,
      });
    });
    expect(await screen.findByRole("status")).toHaveTextContent(
      /has been saved/i,
    );
    expect(invalidate).toHaveBeenCalledWith({
      queryKey: memoryKeys.dateBased("mercer-family"),
    });
    expect(invalidate).toHaveBeenCalledWith({
      queryKey: memoryKeys.homepage("mercer-family"),
    });
  });

  it("offers to restore resurfacing and reports a failed save safely", async () => {
    vi.mocked(updatePhoto).mockRejectedValue(new Error("denied"));
    renderControl(true);

    expect(
      screen.getByText(
        /stays in the archive but will not appear automatically/i,
      ),
    ).toBeInTheDocument();
    await userEvent.click(
      screen.getByRole("button", { name: "Allow in memories" }),
    );

    expect(await screen.findByRole("alert")).toHaveTextContent(
      /could not be saved/i,
    );
    expect(updatePhoto).toHaveBeenCalledWith("mercer-family", "photo-1", {
      do_not_resurface: false,
    });
  });
});
