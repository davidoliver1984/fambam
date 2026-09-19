import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { server } from "@/test/msw/server";

import { FamilyShell } from "./FamilyShell";

const baseUrl = "http://localhost:8082";

function setup(
  role = "owner",
  missing = false,
  listState: "ok" | "empty" | "error" = "ok",
) {
  server.use(
    http.get(`${baseUrl}/api/user`, () =>
      HttpResponse.json({
        data: { id: "user-1", name: "David", email: "david@example.test" },
      }),
    ),
    http.get(`${baseUrl}/api/family-spaces`, () =>
      listState === "error"
        ? HttpResponse.json({ message: "Unavailable" }, { status: 503 })
        : HttpResponse.json({
            data:
              listState === "empty"
                ? []
                : [
                    {
                      id: "family-1",
                      slug: "first-family",
                      name: "First Family",
                      role,
                      status: "active",
                    },
                    {
                      id: "family-2",
                      slug: "second-family",
                      name: "Second Family",
                      role: "member",
                      status: "active",
                    },
                  ],
          }),
    ),
    http.get(`${baseUrl}/api/families/:familySlug`, ({ params }) =>
      missing
        ? HttpResponse.json({ message: "Not Found." }, { status: 404 })
        : HttpResponse.json({
            data: {
              id:
                params.familySlug === "first-family" ? "family-1" : "family-2",
              slug: params.familySlug,
              name:
                params.familySlug === "first-family"
                  ? "First Family"
                  : "Second Family",
              role,
              status: "active",
            },
          }),
    ),
  );
  const router = createMemoryRouter(
    [
      {
        path: "/families/:familySlug",
        element: <FamilyShell />,
        children: [
          {
            index: true,
            element: (
              <main>
                <h1>Family home</h1>
              </main>
            ),
          },
          {
            path: "photos",
            element: (
              <main>
                <h1>Photos page</h1>
              </main>
            ),
          },
        ],
      },
      { path: "/account", element: <main>Account page</main> },
    ],
    { initialEntries: ["/families/first-family"] },
  );
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
  return router;
}

afterEach(() => {
  cleanup();
  document.documentElement.removeAttribute("data-theme");
  window.localStorage.removeItem("fambam-theme");
});

describe("FamilyShell", () => {
  it("shows the current family, navigates, and switches Family Space without carrying the old route", async () => {
    const router = setup();
    expect(
      await screen.findByRole("heading", { name: "Family home" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("navigation", { name: "Family navigation" }),
    ).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Home" })).toHaveAttribute(
      "aria-current",
      "page",
    );
    await userEvent.click(screen.getByRole("link", { name: "Photos" }));
    expect(
      await screen.findByRole("heading", { name: "Photos page" }),
    ).toBeInTheDocument();
    expect(document.getElementById("family-content")).toHaveFocus();
    await userEvent.selectOptions(
      screen.getByRole("combobox", { name: "Family Space" }),
      "second-family",
    );
    expect(router.state.location.pathname).toBe("/families/second-family");
    expect(
      await screen.findByRole("heading", { name: "Family home" }),
    ).toBeInTheDocument();
  });

  it("does not advertise member-only routes to Guests", async () => {
    setup("guest");
    expect(
      await screen.findByRole("heading", { name: "Family home" }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "People" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "Photos" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "Events" }),
    ).not.toBeInTheDocument();
  });

  it("keeps the active family available when the switcher list is empty or fails", async () => {
    setup("owner", false, "error");
    await screen.findByRole("heading", { name: "Family home" });
    expect(
      await screen.findByText("Other Family Spaces could not be loaded."),
    ).toHaveAttribute("role", "status");
    expect(screen.getByText("First Family")).toBeInTheDocument();
  });

  it("uses the active Family Space when the membership list is temporarily empty", async () => {
    setup("owner", false, "empty");
    await screen.findByRole("heading", { name: "Family home" });
    expect(screen.getByText("First Family")).toBeInTheDocument();
    expect(
      screen.queryByRole("combobox", { name: "Family Space" }),
    ).not.toBeInTheDocument();
  });

  it("persists the accessible appearance toggle", async () => {
    setup();
    await screen.findByRole("heading", { name: "Family home" });
    await userEvent.click(screen.getByLabelText("Open account menu for David"));
    const toggle = screen.getByRole("button", { name: "Use dark mode" });
    await userEvent.click(toggle);
    expect(document.documentElement).toHaveAttribute("data-theme", "dark");
    expect(toggle).toHaveAttribute("aria-pressed", "true");
    expect(window.localStorage.getItem("fambam-theme")).toBe("dark");
    await userEvent.keyboard("{Escape}");
    expect(
      screen.getByLabelText("Open account menu for David").closest("details"),
    ).not.toHaveAttribute("open");
  });

  it("offers a keyboard-dismissible compact navigation", async () => {
    setup();
    await screen.findByRole("heading", { name: "Family home" });
    const menu = screen.getByRole("button", { name: "Menu", hidden: true });
    await userEvent.click(menu);
    expect(menu).toHaveAttribute("aria-expanded", "true");
    expect(
      screen.getByRole("navigation", { name: "Mobile family navigation" }),
    ).toBeInTheDocument();
    await userEvent.keyboard("{Escape}");
    expect(menu).toHaveAttribute("aria-expanded", "false");
    expect(menu).toHaveFocus();
  });

  it("keeps a missing Family Space out of the shell", async () => {
    setup("owner", true);
    expect(
      await screen.findByRole("heading", { name: "Family Space not found" }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("navigation", { name: "Family navigation" }),
    ).not.toBeInTheDocument();
  });
});
