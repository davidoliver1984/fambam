import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { getMediaVariantDelivery } from "../api/mediaUploadApi";
import { MediaVariantImage } from "./MediaVariantImage";

vi.mock("../api/mediaUploadApi", () => ({
  getMediaVariantDelivery: vi.fn(),
}));

function renderImage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return render(
    <QueryClientProvider client={client}>
      <MediaVariantImage
        familySlug="oliver-family"
        mediaUploadId="upload-1"
        transform="thumbnail"
        alt="Family picnic"
      />
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("MediaVariantImage", () => {
  it("renders only the URL returned by the authorised media-delivery query", async () => {
    vi.mocked(getMediaVariantDelivery).mockResolvedValue({
      asset: "variant",
      transform_name: "thumbnail",
      processing_version: 1,
      url: "https://storage.test/signed-thumbnail",
      method: "GET",
      expires_at: "2026-08-10T12:05:00+00:00",
    });

    renderImage();

    expect(
      await screen.findByRole("img", { name: "Family picnic" }),
    ).toHaveAttribute("src", "https://storage.test/signed-thumbnail");
    expect(getMediaVariantDelivery).toHaveBeenCalledWith(
      "oliver-family",
      "upload-1",
      "thumbnail",
      expect.any(AbortSignal),
    );
  });

  it("announces loading without disclosing an image URL", () => {
    vi.mocked(getMediaVariantDelivery).mockReturnValue(new Promise(() => {}));

    renderImage();

    expect(screen.getByRole("status")).toHaveTextContent("Loading photograph");
    expect(screen.queryByRole("img")).not.toBeInTheDocument();
  });

  it("fails closed with safe copy when delivery is denied", async () => {
    vi.mocked(getMediaVariantDelivery).mockRejectedValue(new Error("403"));

    renderImage();

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "currently unavailable",
    );
    expect(screen.queryByRole("img")).not.toBeInTheDocument();
  });

  it("replaces a broken signed asset with the safe unavailable state", async () => {
    vi.mocked(getMediaVariantDelivery).mockResolvedValue({
      asset: "variant",
      transform_name: "thumbnail",
      processing_version: 1,
      url: "https://storage.test/missing-thumbnail",
      method: "GET",
      expires_at: "2026-08-10T12:05:00+00:00",
    });

    renderImage();
    fireEvent.error(await screen.findByRole("img"));

    expect(screen.getByRole("alert")).toHaveTextContent(
      "currently unavailable",
    );
  });
});
