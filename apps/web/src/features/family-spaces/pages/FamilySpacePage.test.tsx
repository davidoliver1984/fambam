import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, cleanup, render, screen } from "@testing-library/react";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { server } from "@/test/msw/server";

import { familySpaceKeys } from "../api/familySpaceKeys";
import { FamilySpacePage } from "./FamilySpacePage";

vi.mock("@/features/invitations/pages/InvitationManagement", () => ({
  InvitationManagement: ({ familySlug }: { familySlug: string }) => (
    <p>Invitations for {familySlug}</p>
  ),
}));

const apiBaseUrl = "http://localhost:8082";

function renderPage(path: string) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const router = createMemoryRouter(
    [{ path: "/families/:familySlug", element: <FamilySpacePage /> }],
    { initialEntries: [path] },
  );

  const rendered = render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );

  return { ...rendered, queryClient, router };
}

afterEach(cleanup);

describe("FamilySpacePage", () => {
  it("derives the active Family Space from the URL", async () => {
    server.use(
      http.get(`${apiBaseUrl}/api/families/oliver-family`, () =>
        HttpResponse.json({
          data: {
            id: "01K1ZZZZZZZZZZZZZZZZZZZZZZ",
            slug: "oliver-family",
            name: "Oliver Family",
            status: "active",
            role: "owner",
          },
        }),
      ),
    );

    renderPage("/families/oliver-family");

    expect(
      await screen.findByRole("heading", { name: "Oliver Family" }),
    ).toBeInTheDocument();
    expect(
      screen.getByText("Invitations for oliver-family"),
    ).toBeInTheDocument();
  });

  it("renders the same unavailable state for a tenant 404", async () => {
    server.use(
      http.get(`${apiBaseUrl}/api/families/private-family`, () =>
        HttpResponse.json({ message: "Not Found." }, { status: 404 }),
      ),
    );

    renderPage("/families/private-family");

    expect(
      await screen.findByRole("heading", { name: "Family Space not found" }),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/unavailable or you no longer have access/i),
    ).toBeInTheDocument();
  });

  it("does not serve one Family Space from another tenant query cache", async () => {
    const requestedSlugs: string[] = [];
    server.use(
      http.get(`${apiBaseUrl}/api/families/:familySlug`, ({ params }) => {
        const familySlug = String(params.familySlug);
        requestedSlugs.push(familySlug);

        return HttpResponse.json({
          data: {
            id:
              familySlug === "oliver-family"
                ? "01K1ZZZZZZZZZZZZZZZZZZZZZZ"
                : "01K20000000000000000000000",
            slug: familySlug,
            name:
              familySlug === "oliver-family"
                ? "Oliver Family"
                : "Second Family",
            status: "active",
            role: "owner",
          },
        });
      }),
    );

    const { router } = renderPage("/families/oliver-family");
    expect(
      await screen.findByRole("heading", { name: "Oliver Family" }),
    ).toBeInTheDocument();

    await act(async () => {
      await router.navigate("/families/second-family");
    });

    expect(
      await screen.findByRole("heading", { name: "Second Family" }),
    ).toBeInTheDocument();
    expect(screen.queryByText("Oliver Family")).not.toBeInTheDocument();
    expect(requestedSlugs).toEqual(["oliver-family", "second-family"]);
  });

  it("handles membership loss as the normal inaccessible-family state", async () => {
    let hasAccess = true;
    server.use(
      http.get(`${apiBaseUrl}/api/families/oliver-family`, () =>
        hasAccess
          ? HttpResponse.json({
              data: {
                id: "01K1ZZZZZZZZZZZZZZZZZZZZZZ",
                slug: "oliver-family",
                name: "Oliver Family",
                status: "active",
                role: "member",
              },
            })
          : HttpResponse.json({ message: "Not Found." }, { status: 404 }),
      ),
    );

    const { queryClient } = renderPage("/families/oliver-family");
    expect(
      await screen.findByRole("heading", { name: "Oliver Family" }),
    ).toBeInTheDocument();

    hasAccess = false;
    await act(async () => {
      await queryClient.invalidateQueries({
        queryKey: familySpaceKeys.detail("oliver-family"),
      });
    });

    expect(
      await screen.findByRole("heading", { name: "Family Space not found" }),
    ).toBeInTheDocument();
  });
});
