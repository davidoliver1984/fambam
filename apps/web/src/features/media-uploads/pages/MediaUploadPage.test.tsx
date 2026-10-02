import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
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
import type { CreatePhotoResult } from "@/features/photos/types/photo";
import { MediaUploadPage } from "./MediaUploadPage";

const {
  createPhoto,
  getMediaUploadBatch,
  retryMediaUploadProcessing,
  uploadMediaBatch,
} = vi.hoisted(() => ({
  createPhoto:
    vi.fn<(familySlug: string, input: unknown) => Promise<CreatePhotoResult>>(),
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

vi.mock("@/features/photos/api/photoApi", () => ({ createPhoto }));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  vi.unstubAllGlobals();
});

beforeEach(() => {
  const idempotencyKeys = ["first-key", "second-key"];
  vi.stubGlobal("crypto", {
    randomUUID: () => idempotencyKeys.shift() ?? "retry-key",
    getRandomValues: (values: Uint8Array) => values.fill(1),
  });
  createPhoto.mockImplementation((_familySlug, input) => {
    const mediaUploadId = (input as { media_upload_id: string })
      .media_upload_id;
    return Promise.resolve({
      outcome: "photo_created",
      photo: { id: `photo-${mediaUploadId}` },
    } as CreatePhotoResult);
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
          id: "01KUPLOAD00000000000000001",
          client_filename: "first.jpg",
          state: "ready",
          byte_size: 5,
          uploaded_at: "2026-08-10T12:01:00+00:00",
        },
        {
          id: "01KUPLOAD00000000000000002",
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

    await waitFor(() => {
      expect(uploadMediaBatch).toHaveBeenCalledOnce();
    });
    const [familySlug, input] = uploadMediaBatch.mock.calls[0];
    expect(familySlug).toBe("oliver-family");
    expect(input.batchId).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
    expect(input.items.map(({ file }) => file)).toEqual(files);
    expect(
      (await screen.findAllByText("Ready · photograph created")).length,
    ).toBeGreaterThanOrEqual(2);
    expect(
      screen.getByRole("link", { name: "View photographs" }),
    ).toHaveAttribute("href", "/families/oliver-family/photos");
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
          id: "01KUPLOAD00000000000000001",
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
    expect(
      await screen.findByText(/Object storage rejected/),
    ).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Retry upload" }));
    expect(uploadMediaBatch).toHaveBeenCalledTimes(2);
    expect(uploadMediaBatch.mock.calls[1][1]).toBe(
      uploadMediaBatch.mock.calls[0][1],
    );
    expect(screen.getByText(/Object storage rejected/)).toBeInTheDocument();
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
    await user.click(
      await screen.findByRole("button", { name: "Retry processing" }),
    );

    expect(retryMediaUploadProcessing).toHaveBeenCalledWith(
      "oliver-family",
      "01KUPLOAD00000000000000001",
    );
  });

  it("uses the approved duplicate decision dialog before creating a separate Photo", async () => {
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
      active: false,
      counts: { ...emptyCounts, ready: 1 },
      items: [
        {
          id: "01KUPLOAD00000000000000001",
          client_filename: "first.jpg",
          state: "ready",
          byte_size: 5,
          uploaded_at: "2026-08-10T12:01:00+00:00",
        },
      ],
    });
    createPhoto
      .mockResolvedValueOnce({
        outcome: "duplicate_detected",
        candidates: [
          {
            id: "01KPHOTO0000000000000000001",
            caption: "William on the promenade",
            visibility: "family_space",
            client_filename: "existing.jpg",
            created_at: "2026-08-01T12:00:00+00:00",
          },
        ],
      })
      .mockResolvedValueOnce({
        outcome: "photo_created",
        photo: { id: "01KPHOTO0000000000000000002" },
      } as CreatePhotoResult);
    const user = userEvent.setup();
    renderPage();

    await user.upload(
      screen.getByLabelText("Photographs"),
      new File(["first"], "first.jpg", { type: "image/jpeg" }),
    );
    await user.click(
      await screen.findByRole("button", { name: "Review duplicate" }),
    );

    expect(
      screen.getByRole("heading", { name: "This photo is already in Fambam" }),
    ).toBeInTheDocument();
    expect(screen.getByLabelText("Use the existing Photo")).toBeChecked();
    await user.click(screen.getByLabelText("Create a separate Photo"));
    await user.click(
      screen.getByRole("button", { name: "Continue uploading" }),
    );

    await waitFor(() => {
      expect(createPhoto).toHaveBeenLastCalledWith(
        "oliver-family",
        expect.objectContaining({
          media_upload_id: "01KUPLOAD00000000000000001",
          duplicate_resolution: "create_new",
          disclosed_photo_ids: ["01KPHOTO0000000000000000001"],
        }),
      );
    });
  });
});
