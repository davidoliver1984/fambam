import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { server } from "@/test/msw/server";

import { CollectionsPage } from "./CollectionsPage";

afterEach(cleanup);

describe("CollectionsPage", () => {
  it("lists the current user's private Collections", async () => {
    server.use(
      http.get("http://localhost:8082/api/families/mercer/collections", () =>
        HttpResponse.json({
          data: [
            {
              id: "collection-1",
              name: "For the reunion",
              description: "Print shortlist",
              created_at: null,
            },
          ],
        }),
      ),
    );
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
    expect(
      await screen.findByRole("link", { name: "For the reunion" }),
    ).toHaveAttribute("href", "/families/mercer/collections/collection-1");
    expect(screen.getByText("Print shortlist")).toBeInTheDocument();
  });
});
