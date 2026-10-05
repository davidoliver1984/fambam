import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, renderHook, waitFor } from "@testing-library/react";
import type { PropsWithChildren } from "react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { getFamilyExports } from "../api/familyExportApi";
import type { FamilyExport } from "../types/familyExport";
import { useFamilyExportsQuery } from "./useFamilyExports";

vi.mock("../api/familyExportApi", () => ({
  authorizeFamilyExportDownload: vi.fn(),
  getFamilyExports: vi.fn(),
  requestFullFamilyExport: vi.fn(),
  requestPersonalFamilyExport: vi.fn(),
}));

const pendingExport = {
  id: "export-pending",
  scope: "personal",
  state: "pending",
  photo_count: null,
  byte_size: null,
  failure_reason: null,
  expires_at: null,
  created_at: "2026-10-05T08:30:00Z",
} satisfies FamilyExport;

const readyExport = {
  ...pendingExport,
  state: "ready",
  photo_count: 12,
  byte_size: 4096,
  expires_at: "2026-10-06T08:30:00Z",
} satisfies FamilyExport;

function harness() {
  const client = new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  });
  const wrapper = ({ children }: PropsWithChildren) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return { client, wrapper };
}

afterEach(() => {
  vi.useRealTimers();
  vi.resetAllMocks();
});

describe("useFamilyExportsQuery polling", () => {
  it("uses one list poll for multiple active exports and stops after a terminal response", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.mocked(getFamilyExports)
      .mockResolvedValueOnce([
        pendingExport,
        { ...pendingExport, id: "export-processing", state: "processing" },
      ])
      .mockResolvedValue([readyExport]);
    const { client, wrapper } = harness();
    renderHook(() => useFamilyExportsQuery("family", true), { wrapper });

    await waitFor(() => {
      expect(getFamilyExports).toHaveBeenCalledTimes(1);
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(3_000);
    });
    await waitFor(() => {
      expect(getFamilyExports).toHaveBeenCalledTimes(2);
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(6_000);
    });

    expect(getFamilyExports).toHaveBeenCalledTimes(2);
    client.clear();
  });

  it("cancels polling when the final observer unmounts", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.mocked(getFamilyExports).mockResolvedValue([pendingExport]);
    const { client, wrapper } = harness();
    const { unmount } = renderHook(
      () => useFamilyExportsQuery("family", true),
      { wrapper },
    );

    await waitFor(() => {
      expect(getFamilyExports).toHaveBeenCalledTimes(1);
    });
    unmount();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(6_000);
    });

    expect(getFamilyExports).toHaveBeenCalledTimes(1);
    client.clear();
  });
});
