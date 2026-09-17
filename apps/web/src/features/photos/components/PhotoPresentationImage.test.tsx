import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, expect, it, vi } from "vitest";

import {
  getPhotoVersionDelivery,
  getPhotoVersions,
} from "../api/photoEditorApi";
import { PhotoPresentationImage } from "./PhotoPresentationImage";

vi.mock("../api/photoEditorApi", () => ({
  getPhotoVersions: vi.fn(),
  getPhotoVersionDelivery: vi.fn(),
}));
vi.mock("@/features/media-uploads/components/MediaVariantImage", () => ({
  MediaVariantImage: () => (
    <img src="https://storage.test/canonical-display" alt="Family" />
  ),
}));

function renderImage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <PhotoPresentationImage
        familySlug="family"
        photoId="photo-1"
        mediaUploadId="upload-1"
        alt="Family"
      />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(getPhotoVersions).mockResolvedValue({
    active_photo_version_id: "version-1",
    can_edit: false,
    versions: [],
  });
  vi.mocked(getPhotoVersionDelivery).mockResolvedValue({
    asset: "photo_version",
    url: "https://storage.test/signed-version",
    method: "GET",
    expires_at: "2026-09-17T12:05:00Z",
  });
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

it("shows an active version using Photo-authorized delivery", async () => {
  renderImage();
  expect(await screen.findByAltText("Family")).toHaveAttribute(
    "src",
    "https://storage.test/signed-version",
  );
  expect(getPhotoVersionDelivery).toHaveBeenCalledWith(
    "family",
    "photo-1",
    "version-1",
    expect.any(AbortSignal),
  );
});

it("falls back to canonical presentation when no edit is active", async () => {
  vi.mocked(getPhotoVersions).mockResolvedValue({
    active_photo_version_id: null,
    can_edit: false,
    versions: [],
  });
  renderImage();
  expect(await screen.findByAltText("Family")).toHaveAttribute(
    "src",
    "https://storage.test/canonical-display",
  );
  expect(getPhotoVersionDelivery).not.toHaveBeenCalled();
});

it("shows a safe error when edited delivery fails", async () => {
  vi.mocked(getPhotoVersionDelivery).mockRejectedValue(
    new Error("private storage key"),
  );
  renderImage();
  expect(await screen.findByRole("alert")).toHaveTextContent(
    "The edited photograph is unavailable.",
  );
  expect(screen.queryByText("private storage key")).not.toBeInTheDocument();
});
