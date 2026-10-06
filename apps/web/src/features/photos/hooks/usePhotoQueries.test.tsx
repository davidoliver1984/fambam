import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, renderHook, waitFor } from "@testing-library/react";
import type { PropsWithChildren } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { getPhotoAlbumHistory, getPhotos } from "../api/photoApi";
import { photoKeys } from "../api/photoKeys";
import type { Photo } from "../types/photo";
import { usePhotoAlbumHistoryQuery, usePhotosQuery } from "./usePhotoQueries";

vi.mock("../api/photoApi", () => ({
  getDeletedPhotos: vi.fn(),
  getPhoto: vi.fn(),
  getPhotoAlbumHistory: vi.fn(),
  getPhotoMetadataProposals: vi.fn(),
  getPhotoPersonProposals: vi.fn(),
  getPhotoProvenanceProposals: vi.fn(),
  getPhotos: vi.fn(),
  getPromotableMediaUploads: vi.fn(),
}));

const familySlug = "mercer";
const photo = {
  id: "01K60000000000000000000000",
  caption: "At the pier",
  media_upload: { id: "upload-1", client_filename: "pier.jpg" },
} as Photo;

function harness() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const wrapper = ({ children }: PropsWithChildren) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );

  return { client, wrapper };
}

describe("Photo infinite query", () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it("retains page boundaries while exposing a flattened presentation list", async () => {
    vi.mocked(getPhotos).mockImplementation((_family, _criteria, cursor) =>
      Promise.resolve(
        cursor === null
          ? { items: [photo], next_cursor: "page-2" }
          : {
              items: [{ ...photo, id: "photo-2" }],
              next_cursor: null,
            },
      ),
    );
    const { client, wrapper } = harness();
    const criteria = { sort: "newest" as const, q: "pier" };
    const { result } = renderHook(() => usePhotosQuery(familySlug, criteria), {
      wrapper,
    });
    await waitFor(() => {
      expect(result.current.data).toEqual([photo]);
    });

    await act(async () => {
      await result.current.fetchNextPage();
    });

    await waitFor(() => {
      expect(result.current.data?.map((item) => item.id)).toEqual([
        photo.id,
        "photo-2",
      ]);
    });
    const cached = client.getQueryData<{
      pages: Array<{ items: Photo[] }>;
    }>(photoKeys.list(familySlug, criteria));
    expect(cached?.pages).toHaveLength(2);
    await waitFor(() => {
      expect(result.current.hasNextPage).toBe(false);
    });
  });

  it("starts at page one when search sort or structured filters change", async () => {
    vi.mocked(getPhotos).mockResolvedValue({
      items: [photo],
      next_cursor: "unused",
    });
    const { wrapper } = harness();
    const { rerender } = renderHook(
      ({ sort, q, withoutAlbum }) =>
        usePhotosQuery(familySlug, {
          sort,
          q,
          without_album: withoutAlbum,
        }),
      {
        initialProps: {
          sort: "newest" as "newest" | "oldest",
          q: "summer",
          withoutAlbum: false,
        },
        wrapper,
      },
    );
    await waitFor(() => {
      expect(getPhotos).toHaveBeenCalledTimes(1);
    });

    rerender({ sort: "oldest", q: "winter", withoutAlbum: true });
    await waitFor(() => {
      expect(getPhotos).toHaveBeenCalledTimes(2);
    });
    expect(vi.mocked(getPhotos).mock.calls[1]?.[1]).toEqual({
      sort: "oldest",
      q: "winter",
      without_album: true,
    });
    expect(vi.mocked(getPhotos).mock.calls[1]?.[2]).toBeNull();
  });

  it("coalesces simultaneous next-page requests and detects the end", async () => {
    let resolveNext:
      ((page: { items: Photo[]; next_cursor: null }) => void) | undefined;
    vi.mocked(getPhotos).mockImplementation((_family, _criteria, cursor) => {
      if (cursor === null) {
        return Promise.resolve({ items: [photo], next_cursor: "page-2" });
      }

      return new Promise((resolve) => {
        resolveNext = resolve;
      });
    });
    const { wrapper } = harness();
    const { result } = renderHook(() => usePhotosQuery(familySlug), {
      wrapper,
    });
    await waitFor(() => {
      expect(result.current.hasNextPage).toBe(true);
    });

    let first: ReturnType<typeof result.current.fetchNextPage> | undefined;
    let second: ReturnType<typeof result.current.fetchNextPage> | undefined;
    act(() => {
      first = result.current.fetchNextPage();
      second = result.current.fetchNextPage();
    });
    expect(first).toBe(second);
    await waitFor(() => {
      expect(getPhotos).toHaveBeenCalledTimes(2);
      expect(resolveNext).toBeTypeOf("function");
    });
    resolveNext?.({ items: [{ ...photo, id: "photo-2" }], next_cursor: null });
    await act(async () => {
      await first;
    });
    await waitFor(() => {
      expect(result.current.hasNextPage).toBe(false);
    });
  });
});

describe("Photo Album membership retrieval", () => {
  it("remains off until one Photo picker is opened", async () => {
    vi.mocked(getPhotoAlbumHistory).mockResolvedValue([]);
    const { wrapper } = harness();
    const { rerender } = renderHook(
      ({ open }) => usePhotoAlbumHistoryQuery(familySlug, photo.id, open),
      { initialProps: { open: false }, wrapper },
    );
    expect(getPhotoAlbumHistory).not.toHaveBeenCalled();

    rerender({ open: true });
    await waitFor(() => {
      expect(getPhotoAlbumHistory).toHaveBeenCalledTimes(1);
    });
    expect(getPhotoAlbumHistory).toHaveBeenCalledWith(
      familySlug,
      photo.id,
      expect.any(AbortSignal),
    );
  });
});
