import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, renderHook, waitFor } from "@testing-library/react";
import type { PropsWithChildren } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { getPeople } from "../api/personApi";
import { personKeys } from "../api/personKeys";
import type { PersonSummary } from "../types/person";
import { usePeopleQuery } from "./usePeopleQuery";

vi.mock("../api/personApi", () => ({ getPeople: vi.fn() }));

const familySlug = "family";
const person: PersonSummary = {
  id: "01K30000000000000000000000",
  preferred_name: "Ada Oliver",
  alternate_names: [],
  identity_status: "confirmed",
  birth_date: { precision: "year", value: "1948" },
  death_date: { precision: "unknown", value: null },
  status: "living",
  portrait_thumbnail_url: null,
  relationship_summary: null,
};

function harness() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const wrapper = ({ children }: PropsWithChildren) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return { client, wrapper };
}

describe("People infinite query", () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it("retains page boundaries, appends once, and exposes the end state", async () => {
    vi.mocked(getPeople).mockImplementation((_family, _criteria, cursor) =>
      Promise.resolve(
        cursor === null
          ? { items: [person], next_cursor: "page-2" }
          : {
              items: [{ ...person, id: "person-2", preferred_name: "Beth" }],
              next_cursor: null,
            },
      ),
    );
    const criteria = { sort: "az" as const, status: "living" as const };
    const { client, wrapper } = harness();
    const { result } = renderHook(() => usePeopleQuery(familySlug, criteria), {
      wrapper,
    });
    await waitFor(() => {
      expect(result.current.data).toEqual([person]);
    });

    await act(async () => {
      await result.current.fetchNextPage();
    });

    await waitFor(() => {
      expect(result.current.data?.map((item) => item.id)).toEqual([
        person.id,
        "person-2",
      ]);
      expect(result.current.hasNextPage).toBe(false);
    });
    expect(getPeople).toHaveBeenCalledTimes(2);
    await act(async () => {
      await result.current.fetchNextPage();
    });
    expect(getPeople).toHaveBeenCalledTimes(2);
    const cached = client.getQueryData<{
      pages: Array<{ items: PersonSummary[] }>;
    }>(personKeys.page(familySlug, criteria));
    expect(cached?.pages).toHaveLength(2);
  });

  it("uses criteria in the key and restarts from null when they change", async () => {
    vi.mocked(getPeople).mockResolvedValue({
      items: [person],
      next_cursor: "continuation",
    });
    const { wrapper } = harness();
    const { rerender } = renderHook(
      ({ sort, q, status }) => usePeopleQuery(familySlug, { sort, q, status }),
      {
        initialProps: {
          sort: "az" as "az" | "za",
          q: "ada",
          status: "living" as "living" | "remembered",
        },
        wrapper,
      },
    );
    await waitFor(() => {
      expect(getPeople).toHaveBeenCalledTimes(1);
    });

    rerender({ sort: "za", q: "beth", status: "remembered" });
    await waitFor(() => {
      expect(getPeople).toHaveBeenCalledTimes(2);
    });

    expect(vi.mocked(getPeople).mock.calls[1]?.[1]).toEqual({
      sort: "za",
      q: "beth",
      status: "remembered",
    });
    expect(vi.mocked(getPeople).mock.calls[1]?.[2]).toBeNull();
  });
});
