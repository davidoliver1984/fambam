import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, renderHook, waitFor } from "@testing-library/react";
import type { PropsWithChildren } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { familyExportKeys } from "@/features/exports/api/familyExportKeys";
import { homeKeys } from "@/features/home/hooks/useHomeQuery";
import { photoKeys } from "@/features/photos/api/photoKeys";
import { searchKeys } from "@/features/search/api/searchKeys";

import {
  addPhotoToAlbum,
  createAlbum,
  deleteAlbum,
  getAlbums,
  removePhotoFromAlbum,
  updateAlbum,
} from "../api/albumApi";
import { albumKeys } from "../api/albumKeys";
import type { Album } from "../types/album";
import {
  useDeleteAlbumMutation,
  useAddAlbumPhotoMutation,
  useAlbumsQuery,
  useCreateAlbumMutation,
  useRemoveAlbumPhotoMutation,
  useUpdateAlbumMutation,
} from "./useAlbumQueries";

vi.mock("../api/albumApi", () => ({
  addPhotoToAlbum: vi.fn(),
  createAlbum: vi.fn(),
  deleteAlbum: vi.fn(),
  getAlbum: vi.fn(),
  getAlbums: vi.fn(),
  removePhotoFromAlbum: vi.fn(),
  requestAlbumExport: vi.fn(),
  setAlbumCover: vi.fn(),
  updateAlbum: vi.fn(),
  uploadPhotoToAlbum: vi.fn(),
}));

const familySlug = "family";
const albumId = "01K90000000000000000000000";
const album = {
  id: albumId,
  name: "Album",
  created_at: "2026-09-01T10:00:00+00:00",
  updated_at: "2026-09-01T10:00:00+00:00",
  is_new: false,
} as Album;

function harness() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const wrapper = ({ children }: PropsWithChildren) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return { client, wrapper };
}

describe("Album mutations", () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it("invalidates Album detail/list plus affected search and Home summaries after update", async () => {
    vi.mocked(updateAlbum).mockResolvedValue({ ...album, name: "Updated" });
    const { client, wrapper } = harness();
    const keys = [
      albumKeys.list(familySlug),
      albumKeys.detail(familySlug, albumId),
      searchKeys.group(familySlug, "albums", {}),
      homeKeys.detail(familySlug),
    ];
    keys.forEach((key) => client.setQueryData(key, {}));
    const { result } = renderHook(
      () => useUpdateAlbumMutation(familySlug, albumId),
      { wrapper },
    );

    await act(async () => {
      await result.current.mutateAsync({ name: "Updated" });
    });

    expect(updateAlbum).toHaveBeenCalledWith(familySlug, albumId, {
      name: "Updated",
    });
    keys.forEach((key) => {
      expect(client.getQueryState(key)?.isInvalidated).toBe(true);
    });
  });

  it("invalidates every paged Album list after create", async () => {
    vi.mocked(createAlbum).mockResolvedValue({ ...album, name: "Created" });
    const { client, wrapper } = harness();
    const listKey = albumKeys.page(familySlug, { sort: "newest" });
    client.setQueryData(listKey, { pages: [], pageParams: [] });
    const { result } = renderHook(() => useCreateAlbumMutation(familySlug), {
      wrapper,
    });

    await act(async () => {
      await result.current.mutateAsync({
        name: "Created",
        description: null,
        visibility: "family_space",
      });
    });

    expect(client.getQueryState(listKey)?.isInvalidated).toBe(true);
  });

  it("removes stale detail and invalidates list, search, Home and exports after delete", async () => {
    vi.mocked(deleteAlbum).mockResolvedValue();
    const { client, wrapper } = harness();
    const detailKey = albumKeys.detail(familySlug, albumId);
    const invalidated = [
      albumKeys.list(familySlug),
      searchKeys.group(familySlug, "albums", {}),
      homeKeys.detail(familySlug),
      familyExportKeys.all(familySlug),
    ];
    client.setQueryData(detailKey, album);
    invalidated.forEach((key) => client.setQueryData(key, {}));
    const { result } = renderHook(
      () => useDeleteAlbumMutation(familySlug, albumId),
      { wrapper },
    );

    await act(async () => {
      await result.current.mutateAsync();
    });

    expect(client.getQueryState(detailKey)).toBeUndefined();
    invalidated.forEach((key) => {
      expect(client.getQueryState(key)?.isInvalidated).toBe(true);
    });
  });

  it("does not invalidate successful-read caches when update fails", async () => {
    vi.mocked(updateAlbum).mockRejectedValue(new Error("Update failed"));
    const { client, wrapper } = harness();
    const listKey = albumKeys.list(familySlug);
    client.setQueryData(listKey, [album]);
    const { result } = renderHook(
      () => useUpdateAlbumMutation(familySlug, albumId),
      { wrapper },
    );

    await act(async () => {
      await expect(
        result.current.mutateAsync({ name: "Unavailable" }),
      ).rejects.toThrow("Update failed");
    });

    expect(client.getQueryState(listKey)?.isInvalidated).toBe(false);
  });

  it("invalidates every Photo page and authoritative history after adding membership", async () => {
    vi.mocked(addPhotoToAlbum).mockResolvedValue();
    const photoId = "01KP0000000000000000000000";
    const { client, wrapper } = harness();
    const keys = membershipDependentKeys(client, photoId);
    const { result } = renderHook(() => useAddAlbumPhotoMutation(familySlug), {
      wrapper,
    });

    await act(async () => {
      await result.current.mutateAsync({
        albumId,
        photoId,
        confirmed: true,
      });
    });

    keys.forEach((key) => {
      expect(client.getQueryState(key)?.isInvalidated).toBe(true);
    });
  });

  it("invalidates every Photo page and authoritative history after removing membership", async () => {
    vi.mocked(removePhotoFromAlbum).mockResolvedValue();
    const photoId = "01KP0000000000000000000000";
    const { client, wrapper } = harness();
    const keys = membershipDependentKeys(client, photoId);
    const { result } = renderHook(
      () => useRemoveAlbumPhotoMutation(familySlug),
      { wrapper },
    );

    await act(async () => {
      await result.current.mutateAsync({ albumId, photoId });
    });

    keys.forEach((key) => {
      expect(client.getQueryState(key)?.isInvalidated).toBe(true);
    });
  });
});

function membershipDependentKeys(client: QueryClient, photoId: string) {
  const keys = [
    photoKeys.list(familySlug, { sort: "newest" }),
    photoKeys.list(familySlug, { q: "holiday", without_album: true }),
    photoKeys.albumHistory(familySlug, photoId),
    albumKeys.list(familySlug),
  ];
  keys.forEach((key) => client.setQueryData(key, {}));

  return keys;
}

describe("Album infinite query", () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it("keeps page boundaries in cache while exposing one flattened Album list", async () => {
    vi.mocked(getAlbums).mockImplementation((_family, _criteria, cursor) =>
      Promise.resolve(
        cursor === null
          ? { items: [album], next_cursor: "page-2" }
          : {
              items: [{ ...album, id: "album-2", name: "Second" }],
              next_cursor: null,
            },
      ),
    );
    const { client, wrapper } = harness();
    const { result } = renderHook(
      () => useAlbumsQuery(familySlug, { sort: "newest" }),
      { wrapper },
    );
    await waitFor(() => {
      expect(result.current.data).toEqual([album]);
    });

    await act(async () => {
      await result.current.fetchNextPage();
    });

    await waitFor(() => {
      expect(result.current.data?.map((item) => item.id)).toEqual([
        album.id,
        "album-2",
      ]);
    });
    const cached = client.getQueryData<{
      pages: Array<{ items: Album[] }>;
    }>(albumKeys.page(familySlug, { sort: "newest" }));
    expect(cached?.pages).toHaveLength(2);
  });

  it("starts from the first page when sort, search, or filter criteria change", async () => {
    vi.mocked(getAlbums).mockResolvedValue({
      items: [album],
      next_cursor: "unused-continuation",
    });
    const { wrapper } = harness();
    const { rerender } = renderHook(
      ({ sort, q, tagId }) =>
        useAlbumsQuery(familySlug, { sort, q, tag_id: tagId }),
      {
        initialProps: {
          sort: "newest" as "newest" | "oldest",
          q: "summer",
          tagId: "tag-summer",
        },
        wrapper,
      },
    );
    await waitFor(() => {
      expect(getAlbums).toHaveBeenCalledTimes(1);
    });

    rerender({ sort: "oldest", q: "winter", tagId: "tag-winter" });
    await waitFor(() => {
      expect(getAlbums).toHaveBeenCalledTimes(2);
    });

    expect(vi.mocked(getAlbums).mock.calls[1]?.[1]).toEqual({
      sort: "oldest",
      q: "winter",
      tag_id: "tag-winter",
    });
    expect(vi.mocked(getAlbums).mock.calls[1]?.[2]).toBeNull();
  });
});
