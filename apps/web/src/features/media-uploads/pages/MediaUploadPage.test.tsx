import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { MediaUploadPage } from "./MediaUploadPage";

const { uploadMediaFile } = vi.hoisted(() => ({
  uploadMediaFile: vi.fn<
    (
      familySlug: string,
      file: File,
      idempotencyKey: string,
    ) => Promise<{
      id: string;
      state: string;
      client_filename: string;
      byte_size: number | null;
      uploaded_at: string | null;
      upload_authorization: null;
    }>
  >(),
}));

vi.mock("../api/mediaUploadApi", () => ({
  uploadMediaFile: (...arguments_: Parameters<typeof uploadMediaFile>) =>
    uploadMediaFile(...arguments_),
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  vi.unstubAllGlobals();
});

beforeEach(() => {
  vi.stubGlobal("crypto", {
    randomUUID: () => "00000000-0000-4000-8000-000000000001",
  });
});

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const router = createMemoryRouter(
    [{ path: "/families/:familySlug/uploads", element: <MediaUploadPage /> }],
    { initialEntries: ["/families/oliver-family/uploads"] },
  );

  return render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

function submitUploadForm() {
  const form = screen
    .getByRole("button", { name: "Upload photograph" })
    .closest("form");
  if (form === null) throw new Error("Upload form was not rendered.");
  fireEvent.submit(form);
}

describe("MediaUploadPage", () => {
  it("submits the selected file through the feature mutation and reports success", async () => {
    uploadMediaFile.mockResolvedValue({
      id: "01KUPLOAD00000000000000000",
      state: "uploaded",
      client_filename: "family.jpg",
      byte_size: 4,
      uploaded_at: "2026-08-10T12:01:00+00:00",
      upload_authorization: null,
    });
    const user = userEvent.setup();
    renderPage();
    const file = new File(["data"], "family.jpg", { type: "image/jpeg" });

    await user.upload(screen.getByLabelText("Photograph"), file);
    submitUploadForm();

    await waitFor(() => {
      expect(uploadMediaFile).toHaveBeenCalledWith(
        "oliver-family",
        file,
        expect.any(String),
      );
    });
    expect(
      await screen.findByText(/family.jpg arrived safely/i),
    ).toBeInTheDocument();
  });

  it("exposes a clear failure state", async () => {
    uploadMediaFile.mockRejectedValue(new Error("storage failed"));
    const user = userEvent.setup();
    renderPage();

    await user.upload(
      screen.getByLabelText("Photograph"),
      new File(["data"], "family.jpg", { type: "image/jpeg" }),
    );
    submitUploadForm();

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Something went wrong. Please try again.",
    );
  });
});
