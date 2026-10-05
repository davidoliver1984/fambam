import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  render,
  screen,
  waitFor,
  within,
} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { server } from "@/test/msw/server";

import { CollectionsPage } from "./CollectionsPage";

vi.mock("@/features/photos/components/PhotoPresentationImage", () => ({
  PhotoPresentationImage: ({ photoId }: { photoId: string }) => (
    <img alt="" src={`/authorised-presentation/${photoId}`} />
  ),
}));

afterEach(cleanup);

const indexUrl = "http://localhost:8082/api/families/mercer/collections";
const williamId = "01KWILLIAMSBIRTHDAY0000000";
const records = [
  {
    id: williamId,
    name: "William’s 50th birthday",
    description: "The final photographs for William’s birthday book.",
    purpose: null,
    created_at: "2026-09-01T09:00:00Z",
    updated_at: "2026-10-03T09:00:00Z",
    photo_count: 18,
    preview_photo: {
      photo_id: "photo-first-authorised",
      media_upload_id: "upload-1",
    },
  },
  {
    id: "01KPRINTSFORMUM00000000000",
    name: "Prints for Mum",
    description: "A small set to order as proper prints.",
    purpose: "prints",
    created_at: "2026-09-02T09:00:00Z",
    updated_at: "2026-10-02T09:00:00Z",
    photo_count: 12,
    preview_photo: null,
  },
  {
    id: "01KCALENDARSHORTLIST000000",
    name: "Family calendar shortlist",
    description: "Possible photographs for next year’s calendar.",
    purpose: "calendar",
    created_at: "2026-09-03T09:00:00Z",
    updated_at: "2026-10-01T09:00:00Z",
    photo_count: 9,
    preview_photo: null,
  },
] as const;

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const router = createMemoryRouter(
    [
      {
        path: "/families/:familySlug/collections",
        element: <CollectionsPage />,
      },
    ],
    { initialEntries: ["/families/mercer/collections"] },
  );
  render(
    <QueryClientProvider client={client}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
  return router;
}

function installCanonicalIndex(requests: URLSearchParams[] = []) {
  server.use(
    http.get(indexUrl, ({ request }) => {
      const params = new URL(request.url).searchParams;
      requests.push(params);
      const purposes = [...params.entries()]
        .filter(([key]) => key === "purpose" || key.startsWith("purpose["))
        .map(([, value]) => value);
      const query = params.get("q")?.toLocaleLowerCase() ?? "";
      const collectionId = params.get("collection_id");
      const filtered = records
        .filter((item) => collectionId === null || item.id === collectionId)
        .filter(
          (item) =>
            purposes.length === 0 || purposes.includes(item.purpose ?? ""),
        )
        .filter((item) =>
          `${item.name} ${item.description}`
            .toLocaleLowerCase()
            .includes(query),
        )
        .toSorted((left, right) =>
          params.get("sort") === "name"
            ? left.name.localeCompare(right.name)
            : right.updated_at.localeCompare(left.updated_at) ||
              right.id.localeCompare(left.id),
        );
      return HttpResponse.json({ data: filtered });
    }),
  );
}

async function openFilters() {
  const trigger = screen.getByRole("button", { name: /^Filters/ });
  if (trigger.getAttribute("aria-expanded") === "false") {
    await userEvent.click(trigger);
  }
  const quickFilters = within(
    document.querySelector(".collections-quick-filters") as HTMLElement,
  );
  return {
    william: quickFilters.getByRole("button", {
      name: "Collection: William’s 50th birthday",
    }),
    prints: quickFilters.getByRole("button", { name: "Purpose: Prints" }),
    calendar: quickFilters.getByRole("button", { name: "Purpose: Calendar" }),
  };
}

describe("CollectionsPage", () => {
  it("renders canonical cards, previews, counts and whole-card routes", async () => {
    installCanonicalIndex();
    renderPage();

    expect(
      await screen.findByRole("link", { name: /^William’s 50th birthday/ }),
    ).toHaveAttribute("href", `/families/mercer/collections/${williamId}`);
    expect(document.querySelector(".collections-card img")).toHaveAttribute(
      "src",
      "/authorised-presentation/photo-first-authorised",
    );
    expect(screen.getByText("18 Photos · Private")).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /Options for/i }),
    ).not.toBeInTheDocument();
  });

  it("uses stable Collection identity and canonical Purpose filters with OR/AND semantics", async () => {
    const requests: URLSearchParams[] = [];
    installCanonicalIndex(requests);
    renderPage();
    await screen.findByRole("link", { name: /^William’s 50th birthday/ });
    let filters = await openFilters();

    await userEvent.click(filters.william);
    expect(
      await screen.findByRole("link", { name: /^William’s 50th birthday/ }),
    ).toBeInTheDocument();
    await waitFor(() => {
      expect(requests.at(-1)?.get("collection_id")).toBe(williamId);
    });
    expect(requests.at(-1)?.get("q")).toBeNull();

    filters = await openFilters();
    await userEvent.click(filters.william);
    await screen.findByRole("link", { name: /^Prints for Mum/ });
    filters = await openFilters();
    await userEvent.click(filters.prints);
    expect(
      await screen.findByRole("link", { name: /^Prints for Mum/ }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "Family calendar shortlist" }),
    ).not.toBeInTheDocument();

    filters = await openFilters();
    await userEvent.click(filters.calendar);
    expect(
      await screen.findByRole("link", { name: /^Family calendar shortlist/ }),
    ).toBeInTheDocument();
    const purposeValues = [...(requests.at(-1)?.entries() ?? [])]
      .filter(([key]) => key === "purpose" || key.startsWith("purpose["))
      .map(([, value]) => value);
    expect(purposeValues).toEqual(["prints", "calendar"]);

    filters = await openFilters();
    await userEvent.click(filters.calendar);
    await screen.findByRole("link", { name: /^Prints for Mum/ });
    filters = await openFilters();
    await userEvent.click(filters.william);
    expect(await screen.findByText("No collections found")).toBeInTheDocument();
    const combined = requests.at(-1);
    expect(combined?.get("collection_id")).toBe(williamId);
    expect([...(combined?.values() ?? [])]).toContain("prints");
  });

  it("combines server search and sort with active filters while preserving view state", async () => {
    const requests: URLSearchParams[] = [];
    installCanonicalIndex(requests);
    renderPage();
    await screen.findByRole("link", { name: /^Prints for Mum/ });
    let filters = await openFilters();
    await userEvent.click(filters.prints);
    await screen.findByRole("link", { name: /^Prints for Mum/ });

    const search = screen.getByRole("combobox", {
      name: "Search collections…",
    });
    await userEvent.type(search, "proper");
    await waitFor(() => {
      expect(requests.at(-1)?.get("q")).toBe("proper");
    });
    expect(
      await screen.findByRole("link", { name: /^Prints for Mum/ }),
    ).toBeInTheDocument();
    expect([...(requests.at(-1)?.values() ?? [])]).toContain("prints");

    await userEvent.selectOptions(
      screen.getByRole("combobox", { name: /sort/i }),
      "name",
    );
    await waitFor(() => {
      expect(requests.at(-1)?.get("sort")).toBe("name");
    });
    expect([...(requests.at(-1)?.values() ?? [])]).toContain("prints");

    await userEvent.click(screen.getByRole("button", { name: "List view" }));
    expect(
      document.querySelector(".collections-grid--list"),
    ).toBeInTheDocument();
    filters = await openFilters();
    expect(filters.prints).toHaveAttribute("aria-pressed", "true");
  });

  it("exposes exact typed suggestions with keyboard navigation and clears zero results", async () => {
    const requests: URLSearchParams[] = [];
    installCanonicalIndex(requests);
    renderPage();
    await screen.findByRole("link", { name: /^Prints for Mum/ });
    const search = screen.getByRole("combobox", {
      name: "Search collections…",
    });
    await userEvent.click(search);
    const williamOption = screen.getByRole("option", {
      name: /William’s 50th birthdayCollection/i,
    });
    expect(
      screen.getByRole("option", { name: /PrintsPurpose/i }),
    ).toBeInTheDocument();
    await userEvent.keyboard("{ArrowDown}");
    expect(williamOption).toHaveFocus();

    await userEvent.click(search);
    await userEvent.type(search, "missing");
    expect(await screen.findByText("No collections found")).toBeInTheDocument();
    expect(requests.at(-1)?.get("q")).toBe("missing");
    await userEvent.click(
      screen.getByRole("button", { name: "Clear search and filters" }),
    );
    expect(
      await screen.findByRole("link", { name: /^Family calendar shortlist/ }),
    ).toBeInTheDocument();
  });

  it("creates a persisted Collection, closes the dialog and remains on the index", async () => {
    let items: Array<Record<string, unknown>> = [];
    server.use(
      http.get(
        "http://localhost:8082/sanctum/csrf-cookie",
        () => new HttpResponse(null, { status: 204 }),
      ),
      http.get(indexUrl, () => HttpResponse.json({ data: items })),
      http.post(indexUrl, async ({ request }) => {
        const body = (await request.json()) as {
          name: string;
          description: string | null;
        };
        const created = {
          id: "collection-new",
          name: body.name,
          description: body.description,
          purpose: null,
          created_at: "2026-10-04T10:00:00Z",
          updated_at: "2026-10-04T10:00:00Z",
          photo_count: 0,
          preview_photo: null,
        };
        items = [created];
        return HttpResponse.json({ data: created }, { status: 201 });
      }),
    );
    const router = renderPage();
    await screen.findByText("No Collections yet.");
    await userEvent.click(
      screen.getByRole("button", { name: /New collection/i }),
    );
    await userEvent.type(screen.getByLabelText("Name"), "Book shortlist");
    await userEvent.type(
      screen.getByLabelText(/Short description/i),
      "For the birthday book",
    );
    await userEvent.click(
      screen.getByRole("button", { name: "Create collection" }),
    );

    await waitFor(() => {
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    });
    expect(
      await screen.findByRole("link", { name: /^Book shortlist/ }),
    ).toBeInTheDocument();
    expect(router.state.location.pathname).toBe("/families/mercer/collections");
  });
});
