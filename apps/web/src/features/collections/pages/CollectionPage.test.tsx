import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { server } from "@/test/msw/server";

import { CollectionPage } from "./CollectionPage";

vi.mock("@/features/photos/components/PhotoPresentationImage", () => ({
  PhotoPresentationImage: ({ photoId }: { photoId: string }) => (
    <img src={`https://images.test/${photoId}.jpg`} alt="" />
  ),
}));

const apiBaseUrl = "http://localhost:8082";
const collectionBase = `${apiBaseUrl}/api/families/mercer/collections/collection-1`;
const collection = {
  id: "collection-1",
  name: "William’s 50th birthday",
  description: "The final photographs for William’s birthday book.",
  created_at: "2026-09-26T09:00:00Z",
  photos: [
    {
      id: "photo-1",
      caption: "At the pier",
      media_upload_id: "upload-1",
      historical_date: { precision: "exact", value: "1986-08-14" },
      location_description: "Blackpool",
      people: [
        { id: "person-1", preferred_name: "William" },
        { id: "person-2", preferred_name: "Jane" },
      ],
      position: 0,
    },
    {
      id: "photo-2",
      caption: "Christmas dinner",
      media_upload_id: "upload-2",
      historical_date: { precision: "year", value: "1994" },
      location_description: "Ashton-under-Lyne",
      people: [{ id: "person-2", preferred_name: "Jane" }],
      position: 1,
    },
  ],
};

const candidate = {
  id: "photo-3",
  media_upload: {
    id: "upload-3",
    client_filename: "garden.jpg",
    uploader: null,
  },
  caption: "William in the garden",
  description: null,
  archive_source_description: null,
  visibility: "family_space",
  created_by: 1,
  historical_date: { precision: "exact", value: "2024-06-08" },
  location_description: "Glossop",
  do_not_resurface: false,
  provenance: {
    photographer: { person: null, description: null },
    scanner: { person: null, description: null },
    physical_owner: { person: null, description: null },
  },
  tags: [],
  people: [
    {
      id: "association-1",
      photo_id: "photo-3",
      person: { id: "person-1", preferred_name: "William" },
      proposal_source: "manual",
      status: "approved",
      proposed_by: 1,
      resolved_by: 1,
      resolved_at: null,
      created_at: "2026-09-26T09:00:00Z",
    },
  ],
  created_at: "2026-09-26T09:00:00Z",
  updated_at: "2026-09-26T09:00:00Z",
  permissions: {
    can_update: true,
    can_propose_provenance: true,
    can_resolve_provenance: true,
    can_manage_tags: true,
    can_flag_duplicate: true,
  },
};

function handlers(exports: unknown[] = []) {
  return [
    http.get(collectionBase, () => HttpResponse.json({ data: collection })),
    http.get(`${apiBaseUrl}/api/families/mercer/photos`, () =>
      HttpResponse.json({
        data: { items: [candidate], next_cursor: null },
      }),
    ),
    http.get(`${apiBaseUrl}/api/families/mercer/exports`, () =>
      HttpResponse.json({ data: exports }),
    ),
    http.get(`${apiBaseUrl}/sanctum/csrf-cookie`, () =>
      HttpResponse.json(null, { status: 204 }),
    ),
  ];
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const router = createMemoryRouter(
    [
      {
        path: "/families/:familySlug/collections/:collectionId",
        element: <CollectionPage />,
      },
      {
        path: "/families/:familySlug/collections",
        element: <p>Collections index</p>,
      },
    ],
    { initialEntries: ["/families/mercer/collections/collection-1"] },
  );
  render(
    <QueryClientProvider client={client}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

afterEach(cleanup);

describe("CollectionPage", () => {
  it("renders the frozen private ordered curation presentation", async () => {
    server.use(...handlers());
    renderPage();

    expect(
      await screen.findByRole("heading", {
        name: "William’s 50th birthday",
      }),
    ).toBeInTheDocument();
    expect(screen.getByText("Private collection")).toBeInTheDocument();
    expect(
      screen.getByText("Only you can see this Collection"),
    ).toBeInTheDocument();
    expect(
      screen.getByText("Blackpool · William, Jane · 14 August 1986"),
    ).toBeInTheDocument();
    expect(screen.getByText("At the pier").closest("li")).toHaveTextContent(
      "1At the pier",
    );
    expect(
      screen.getByText("Christmas dinner").closest("li"),
    ).toHaveTextContent("2Christmas dinner");
    expect(
      screen.getByRole("link", { name: "View At the pier" }),
    ).toHaveAttribute(
      "href",
      "/families/mercer/photos/photo-1?collectionId=collection-1",
    );
    expect(screen.queryByRole("button", { name: /photo options/i })).toBeNull();
  });

  it("updates, reorders, removes, and atomically adds Photos", async () => {
    const user = userEvent.setup();
    const requests: Array<{ path: string; body?: unknown }> = [];
    server.use(
      ...handlers(),
      http.patch(collectionBase, async ({ request }) => {
        requests.push({
          path: new URL(request.url).pathname,
          body: await request.json(),
        });
        return HttpResponse.json({ data: collection });
      }),
      http.put(`${collectionBase}/order`, async ({ request }) => {
        requests.push({
          path: new URL(request.url).pathname,
          body: await request.json(),
        });
        return HttpResponse.json({ data: collection });
      }),
      http.delete(`${collectionBase}/photos/photo-1`, () => {
        requests.push({ path: "/remove/photo-1" });
        return HttpResponse.json(null, { status: 204 });
      }),
      http.post(`${collectionBase}/photos/batch`, async ({ request }) => {
        requests.push({
          path: new URL(request.url).pathname,
          body: await request.json(),
        });
        return HttpResponse.json({ data: collection }, { status: 201 });
      }),
    );
    renderPage();
    await screen.findByRole("heading", { name: "William’s 50th birthday" });

    await user.click(screen.getByRole("button", { name: "Rename & describe" }));
    const titleInput =
      screen.getByLabelText<HTMLInputElement>("Collection title");
    expect(titleInput).toHaveFocus();
    expect(titleInput.selectionStart).toBe(0);
    await user.clear(titleInput);
    await user.type(titleInput, "Birthday book");
    await user.clear(screen.getByLabelText("Collection description"));
    await user.type(
      screen.getByLabelText("Collection description"),
      "Ready for print",
    );
    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await user.click(
      screen.getByRole("button", { name: "Move At the pier down" }),
    );
    await user.click(
      screen.getByRole("button", {
        name: "Remove At the pier from Collection",
      }),
    );

    await user.click(screen.getByRole("button", { name: "Add Photos" }));
    await user.click(
      screen.getByRole("checkbox", { name: /William in the garden/i }),
    );
    await user.click(screen.getByRole("button", { name: "Add 1 Photo" }));

    await waitFor(() => {
      expect(requests).toEqual(
        expect.arrayContaining([
          {
            path: "/api/families/mercer/collections/collection-1",
            body: { name: "Birthday book", description: "Ready for print" },
          },
          {
            path: "/api/families/mercer/collections/collection-1/order",
            body: { photo_ids: ["photo-2", "photo-1"] },
          },
          { path: "/remove/photo-1" },
          {
            path: "/api/families/mercer/collections/collection-1/photos/batch",
            body: { photo_ids: ["photo-3"] },
          },
        ]),
      );
    });
    expect(
      screen.queryByRole("dialog", { name: "Add existing Photos" }),
    ).not.toBeInTheDocument();
  });

  it("uses real export state and exact Collection deletion confirmation", async () => {
    const user = userEvent.setup();
    let deleted = false;
    server.use(
      ...handlers([
        {
          id: "export-1",
          scope: "collection",
          collection_id: "collection-1",
          state: "processing",
          photo_count: 2,
          byte_size: null,
          failure_reason: null,
          expires_at: null,
          created_at: "2026-09-26T10:00:00Z",
        },
      ]),
      http.delete(collectionBase, () => {
        deleted = true;
        return HttpResponse.json(null, { status: 204 });
      }),
    );
    renderPage();

    expect(
      await screen.findByRole("button", { name: "Preparing final set…" }),
    ).toBeDisabled();
    expect(
      screen.getByText("Your final Photo set is being prepared."),
    ).toBeInTheDocument();

    await user.click(
      screen.getByRole("button", { name: "Collection options" }),
    );
    await user.click(
      screen.getByRole("menuitem", { name: "Delete collection" }),
    );
    expect(
      screen.getByRole("heading", {
        name: "Delete “William’s 50th birthday”?",
      }),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        "The Collection will be removed. Its Photos stay in Fambam, their Albums and their Events.",
      ),
    ).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Delete collection" }));
    await waitFor(() => {
      expect(deleted).toBe(true);
    });
    expect(await screen.findByText("Collections index")).toBeInTheDocument();
  });
});
