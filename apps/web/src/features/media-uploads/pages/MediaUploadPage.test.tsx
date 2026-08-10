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

import type {
  MediaUpload,
  MediaUploadBatchInput,
  MediaUploadBatchResult,
  MediaUploadBatchStatus,
  MediaUploadState,
} from "../types/mediaUpload";
import { MediaUploadPage } from "./MediaUploadPage";

const { getMediaUploadBatch, retryMediaUploadProcessing, uploadMediaBatch } =
  vi.hoisted(() => ({
    getMediaUploadBatch:
      vi.fn<
        (
          familySlug: string,
          batchId: string,
          signal?: AbortSignal,
        ) => Promise<MediaUploadBatchStatus>
      >(),
    retryMediaUploadProcessing:
      vi.fn<
        (familySlug: string, mediaUploadId: string) => Promise<MediaUpload>
      >(),
    uploadMediaBatch:
      vi.fn<
        (
          familySlug: string,
          input: MediaUploadBatchInput,
        ) => Promise<MediaUploadBatchResult>
      >(),
  }));

vi.mock("../api/mediaUploadApi", () => ({
  getMediaUploadBatch,
  retryMediaUploadProcessing,
  uploadMediaBatch,
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  vi.unstubAllGlobals();
});

beforeEach(() => {
  vi.stubGlobal("crypto", {
    randomUUID: () => "00000000-0000-4000-8000-000000000001",
    getRandomValues: (values: Uint8Array) => values.fill(1),
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
    .getByRole("button", { name: "Upload photographs" })
    .closest("form");
  if (form === null) throw new Error("Upload form was not rendered.");
  fireEvent.submit(form);
}

const emptyCounts: Record<MediaUploadState, number> = {
  initiated: 0,
  uploaded: 0,
  verifying: 0,
  preserved: 0,
  processing: 0,
  ready: 0,
  quarantined: 0,
  abandoned: 0,
  degraded: 0,
};

function uploadedMedia(id: string, filename: string) {
  return {
    id,
    state: "uploaded" as const,
    client_filename: filename,
    byte_size: 5,
    uploaded_at: "2026-08-10T12:01:00+00:00",
    upload_batch_id: "01KBATCH000000000000000000",
    upload_authorization: null,
  };
}

describe("MediaUploadPage", () => {
  it("submits multiple files as one independent batch and reports server progress", async () => {
    uploadMediaBatch.mockResolvedValue({
      batch_id: "01KBATCH000000000000000000",
      outcomes: [
        {
          status: "uploaded",
          item_key: "first-key",
          client_filename: "first.jpg",
          upload: uploadedMedia("01KUPLOAD00000000000000001", "first.jpg"),
        },
        {
          status: "uploaded",
          item_key: "second-key",
          client_filename: "second.jpg",
          upload: uploadedMedia("01KUPLOAD00000000000000002", "second.jpg"),
        },
      ],
    });
    getMediaUploadBatch.mockResolvedValue({
      batch_id: "01KBATCH000000000000000000",
      total: 2,
      active: false,
      counts: { ...emptyCounts, ready: 2 },
      items: [
        {
          id: "1",
          client_filename: "first.jpg",
          state: "ready",
          byte_size: 5,
          uploaded_at: "2026-08-10T12:01:00+00:00",
        },
        {
          id: "2",
          client_filename: "second.jpg",
          state: "ready",
          byte_size: 6,
          uploaded_at: "2026-08-10T12:01:00+00:00",
        },
      ],
    });
    const user = userEvent.setup();
    renderPage();
    const files = [
      new File(["first"], "first.jpg", { type: "image/jpeg" }),
      new File(["second"], "second.jpg", { type: "image/jpeg" }),
    ];

    await user.upload(screen.getByLabelText("Photographs"), files);
    submitUploadForm();

    await waitFor(() => {
      expect(uploadMediaBatch).toHaveBeenCalledOnce();
    });
    const [familySlug, input] = uploadMediaBatch.mock.calls[0];
    expect(familySlug).toBe("oliver-family");
    expect(input.batchId).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
    expect(input.items.map(({ file }) => file)).toEqual(files);
    expect(
      await screen.findByText(
        "2 of 2 files completed the direct upload hand-off.",
      ),
    ).toBeInTheDocument();
    expect(await screen.findByText("first.jpg: ready")).toBeInTheDocument();
  });

  it("keeps partial failures visible and offers a duplicate-safe retry", async () => {
    uploadMediaBatch
      .mockResolvedValueOnce({
        batch_id: "01KBATCH000000000000000000",
        outcomes: [
          {
            status: "uploaded",
            item_key: "first-key",
            client_filename: "first.jpg",
            upload: uploadedMedia("01KUPLOAD00000000000000001", "first.jpg"),
          },
          {
            status: "failed",
            item_key: "second-key",
            client_filename: "second.jpg",
            message: "Object storage rejected the upload (503).",
          },
        ],
      })
      .mockImplementationOnce(() => new Promise(() => undefined));
    getMediaUploadBatch.mockResolvedValue({
      batch_id: "01KBATCH000000000000000000",
      total: 1,
      active: true,
      counts: { ...emptyCounts, uploaded: 1 },
      items: [
        {
          id: "1",
          client_filename: "first.jpg",
          state: "uploaded",
          byte_size: 5,
          uploaded_at: "2026-08-10T12:01:00+00:00",
        },
      ],
    });
    const user = userEvent.setup();
    renderPage();

    await user.upload(screen.getByLabelText("Photographs"), [
      new File(["first"], "first.jpg", { type: "image/jpeg" }),
      new File(["second"], "second.jpg", { type: "image/jpeg" }),
    ]);
    submitUploadForm();

    expect(
      await screen.findByText(
        "1 of 2 files completed the direct upload hand-off.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/second.jpg: Object storage rejected/),
    ).toBeInTheDocument();
    await user.click(
      screen.getByRole("button", { name: "Retry incomplete files" }),
    );
    expect(uploadMediaBatch).toHaveBeenCalledTimes(2);
    expect(uploadMediaBatch.mock.calls[1][1]).toBe(
      uploadMediaBatch.mock.calls[0][1],
    );
    expect(
      screen.getByText(/second.jpg: Object storage rejected/),
    ).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retrying…" })).toBeDisabled();
  });

  it("offers recovery for a degraded server item", async () => {
    uploadMediaBatch.mockResolvedValue({
      batch_id: "01KBATCH000000000000000000",
      outcomes: [
        {
          status: "uploaded",
          item_key: "first-key",
          client_filename: "first.jpg",
          upload: uploadedMedia("01KUPLOAD00000000000000001", "first.jpg"),
        },
      ],
    });
    getMediaUploadBatch.mockResolvedValue({
      batch_id: "01KBATCH000000000000000000",
      total: 1,
      active: true,
      counts: { ...emptyCounts, degraded: 1 },
      items: [
        {
          id: "01KUPLOAD00000000000000001",
          client_filename: "first.jpg",
          state: "degraded",
          byte_size: 5,
          uploaded_at: "2026-08-10T12:01:00+00:00",
        },
      ],
    });
    retryMediaUploadProcessing.mockResolvedValue(
      uploadedMedia("01KUPLOAD00000000000000001", "first.jpg"),
    );
    const user = userEvent.setup();
    renderPage();
    await user.upload(
      screen.getByLabelText("Photographs"),
      new File(["first"], "first.jpg", { type: "image/jpeg" }),
    );
    submitUploadForm();

    await user.click(
      await screen.findByRole("button", { name: "Retry processing" }),
    );

    expect(retryMediaUploadProcessing).toHaveBeenCalledWith(
      "oliver-family",
      "01KUPLOAD00000000000000001",
    );
  });
});
