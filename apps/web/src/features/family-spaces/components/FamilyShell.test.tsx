import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { server } from "@/test/msw/server";

import { FamilyShell } from "./FamilyShell";
import { notificationTarget } from "./notificationTarget";

const baseUrl = "http://localhost:8082";

function setup(
  role = "owner",
  missing = false,
  listState: "ok" | "empty" | "error" = "ok",
  initialEntry = "/families/first-family",
  forbidden = false,
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
      forbidden
        ? HttpResponse.json({ message: "Forbidden." }, { status: 403 })
        : missing
          ? HttpResponse.json({ message: "Not Found." }, { status: 404 })
          : HttpResponse.json({
              data: {
                id:
                  params.familySlug === "first-family"
                    ? "family-1"
                    : "family-2",
                slug: params.familySlug,
                name:
                  params.familySlug === "first-family"
                    ? "First Family"
                    : "Second Family",
                role,
                status: "active",
                current_user_person_id: "person-david",
              },
            }),
    ),
    http.get(`${baseUrl}/api/families/:familySlug/notifications`, () =>
      HttpResponse.json({
        data: [
          {
            id: "notification-1",
            category: "contribution",
            photo_id: null,
            album_id: "album-1",
            story_id: null,
            person_id: null,
            comment_id: null,
            family_export_id: null,
            read_at: null,
            created_at: "2026-09-24T12:00:00Z",
            presentation: {
              actor: {
                person_id: "person-jane",
                display_name: "Jane Mercer",
                initials: "JM",
                portrait_thumbnail_url: null,
              },
              headline: "Jane Mercer added 6 Photos",
              detail: null,
              target_label: "Blackpool, 1986",
              thumbnail_url: "https://storage.test/blackpool-thumbnail.webp",
              target: { type: "album", id: "album-1" },
            },
          },
        ],
      }),
    ),
    http.patch(
      `${baseUrl}/api/families/:familySlug/notifications/:notificationId/read`,
      ({ params }) =>
        HttpResponse.json({
          data: {
            id: params.notificationId,
            read_at: "2026-09-24T13:00:00Z",
          },
        }),
    ),
    http.get(`${baseUrl}/api/families/:familySlug/search`, ({ request }) => {
      const group = new URL(request.url).searchParams.get("group") ?? "people";
      return HttpResponse.json({
        data: { [group]: { items: [], next_cursor: null } },
      });
    }),
    http.post(
      `${baseUrl}/logout`,
      () => new HttpResponse(null, { status: 204 }),
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
          {
            path: "events/:eventId",
            element: <h1>Invited Event</h1>,
          },
          {
            path: "people/:personId",
            element: <h1>Person result</h1>,
          },
          {
            path: "albums/:albumId",
            element: <h1>Invited Album</h1>,
          },
          {
            path: "search",
            element: <h1>Search results</h1>,
          },
          {
            path: "settings",
            element: <h1>Family settings</h1>,
          },
        ],
      },
      { path: "/account", element: <main>Account page</main> },
      { path: "/login", element: <main>Sign in page</main> },
    ],
    { initialEntries: [initialEntry] },
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
  it("shows the approved navigation and routes within the current Family Space", async () => {
    setup();
    expect(
      await screen.findByRole("heading", { name: "Family home" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("navigation", { name: "Main navigation" }),
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
  });

  it("opens only an admitted Event journey when Family Space access is forbidden to a Guest", async () => {
    setup(
      "guest",
      false,
      "empty",
      "/families/first-family/events/event-1",
      true,
    );
    expect(
      await screen.findByRole("heading", { name: "Invited Event" }),
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
    expect(
      screen.queryByRole("link", { name: "Albums" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "Search family…" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "Home" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "Stories" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "Collections" }),
    ).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Event" })).toHaveAttribute(
      "href",
      "/families/first-family/events/event-1",
    );
    expect(screen.getByRole("link", { name: "Account" })).toBeInTheDocument();
  });

  it("keeps an Event return path while a Guest views an admitted Album", async () => {
    setup(
      "guest",
      false,
      "empty",
      "/families/first-family/albums/album-1?eventId=event-1",
      true,
    );
    expect(
      await screen.findByRole("heading", { name: "Invited Album" }),
    ).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Event" })).toHaveAttribute(
      "href",
      "/families/first-family/events/event-1",
    );
  });

  it("limits Contributors to their resource-scoped archive routes", async () => {
    setup("contributor");
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
    expect(
      screen.queryByRole("link", { name: "Search family…" }),
    ).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Albums" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Stories" })).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Collections" }),
    ).toBeInTheDocument();
  });

  it("persists the accessible appearance toggle", async () => {
    setup();
    await screen.findByRole("heading", { name: "Family home" });
    await userEvent.click(screen.getByLabelText("Open account menu for David"));
    const toggle = screen.getByRole("menuitem", { name: "Dark mode" });
    await userEvent.click(toggle);
    expect(document.documentElement).toHaveAttribute("data-theme", "dark");
    expect(
      screen.getByRole("menuitem", { name: "Light mode" }),
    ).toBeInTheDocument();
    expect(window.localStorage.getItem("fambam-theme")).toBe("dark");
    await userEvent.keyboard("{Escape}");
    expect(
      screen.getByLabelText("Open account menu for David").closest("details"),
    ).not.toHaveAttribute("open");
  });

  it("supports arrow-key navigation through enabled account actions", async () => {
    setup();
    await screen.findByRole("heading", { name: "Family home" });
    await userEvent.click(screen.getByLabelText("Open account menu for David"));
    const firstAction = screen.getByRole("menuitem", {
      name: "View my Person page",
    });
    firstAction.focus();
    await userEvent.keyboard("{ArrowDown}");
    expect(
      screen.getByRole("menuitem", { name: "Edit profile" }),
    ).toHaveFocus();
    await userEvent.keyboard("{End}");
    expect(screen.getByRole("menuitem", { name: "Sign out" })).toHaveFocus();
    await userEvent.keyboard("{ArrowDown}");
    expect(firstAction).toHaveFocus();
  });

  it("offers a keyboard-dismissible compact navigation", async () => {
    setup();
    await screen.findByRole("heading", { name: "Family home" });
    const menu = screen.getByRole("button", {
      name: "Show navigation",
      hidden: true,
    });
    await userEvent.click(menu);
    expect(menu).toHaveAttribute("aria-expanded", "true");
    expect(menu).toHaveAccessibleName("Hide navigation");
    expect(
      screen.getByRole("navigation", { name: "Main navigation" }),
    ).toHaveClass("mobile-open");
    await userEvent.keyboard("{Escape}");
    expect(menu).toHaveAttribute("aria-expanded", "false");
    expect(menu).toHaveAccessibleName("Show navigation");
    expect(menu).toHaveFocus();
  });

  it("uses the approved search popover with real production result groups", async () => {
    const router = setup();
    server.use(
      http.get(`${baseUrl}/api/families/:familySlug/search`, ({ request }) => {
        const group = new URL(request.url).searchParams.get("group");
        const items = {
          people: [
            {
              id: "person-1",
              preferred_name: "William Mercer",
              relationship_to_viewer: "your brother",
              portrait_thumbnail_url: null,
            },
          ],
          albums: [
            {
              id: "album-1",
              name: "Blackpool, 1986",
              description: null,
              visibility: "family_space",
              event_id: null,
              photo_count: 24,
              cover_thumbnail_url:
                "https://storage.test/blackpool-thumbnail.webp",
            },
          ],
          events: [
            {
              id: "event-1",
              name: "Blackpool summer holiday",
              description: null,
              location: "Blackpool",
              starts_on: "1986-08-01",
              ends_on: "1986-08-08",
            },
          ],
        }[group ?? "people"];
        return HttpResponse.json({
          data: { [group ?? "people"]: { items, next_cursor: null } },
        });
      }),
    );
    await screen.findByRole("heading", { name: "Family home" });
    const trigger = screen.getByRole("button", { name: "Search family" });
    await userEvent.click(trigger);
    const input = screen.getByRole("searchbox", {
      name: "Search people, places and albums",
    });
    expect(input).toHaveFocus();
    await userEvent.type(input, "Blackpool");
    expect(await screen.findByText("William Mercer")).toBeInTheDocument();
    expect(screen.getByText("Person · your brother")).toBeInTheDocument();
    expect(screen.getByText("Blackpool, 1986")).toBeInTheDocument();
    expect(screen.getByText("Album · 24 photographs")).toBeInTheDocument();
    expect(screen.getByText("Blackpool summer holiday")).toBeInTheDocument();
    await userEvent.click(
      screen.getByRole("button", { name: /William Mercer/ }),
    );
    expect(router.state.location.pathname).toBe(
      "/families/first-family/people/person-1",
    );
  });

  it("passes the current term to the full search page", async () => {
    const router = setup();
    await screen.findByRole("heading", { name: "Family home" });
    await userEvent.click(
      screen.getByRole("button", { name: "Search family" }),
    );
    await userEvent.type(
      screen.getByRole("searchbox", {
        name: "Search people, places and albums",
      }),
      "Sarah Mercer",
    );
    await userEvent.click(
      screen.getByRole("button", {
        name: "See all results for “Sarah Mercer”",
      }),
    );
    expect(router.state.location.pathname).toBe(
      "/families/first-family/search",
    );
    expect(router.state.location.search).toBe("?q=Sarah+Mercer");
  });

  it("opens the production-backed notification panel and follows its target", async () => {
    const router = setup();
    await screen.findByRole("heading", { name: "Family home" });
    const trigger = screen.getByRole("button", { name: "Notifications" });
    await userEvent.click(trigger);
    expect(
      screen.getByRole("heading", { name: "Notifications" }),
    ).toBeInTheDocument();
    const actorLink = await screen.findByRole("link", { name: "Jane" });
    expect(actorLink).toHaveAttribute(
      "href",
      "/families/first-family/people/person-jane",
    );
    const entityLink = screen.getByRole("link", { name: "album" });
    const item = entityLink.closest(".shell-notification-item");
    expect(item).not.toBeNull();
    expect(item).toHaveTextContent("Jane added 6 photographs to your album");
    expect(item).toHaveTextContent("Blackpool, 1986");
    expect(item?.querySelector("i")).toHaveAccessibleName("Unread");
    await userEvent.click(entityLink);
    expect(router.state.location.pathname).toBe(
      "/families/first-family/albums/album-1",
    );
  });

  it("preserves album context when a notification opens a photo comment", () => {
    expect(
      notificationTarget("first-family", {
        id: "notification-comment",
        category: "comment",
        photo_id: "photo-1",
        album_id: "album-2",
        story_id: null,
        person_id: null,
        comment_id: "comment-1",
        story_comment_id: null,
        family_export_id: null,
        event_id: null,
        read_at: null,
        created_at: "2026-09-24T12:00:00Z",
        presentation: {
          actor: null,
          headline: "Sarah Mercer commented on your photograph",
          detail: "I remember this.",
          target_label: "Family picnic",
          thumbnail_url: null,
          target: { type: "photo", id: "photo-1" },
        },
      }),
    ).toBe("/families/first-family/photos/photo-1?albumId=album-2");
  });

  it("preserves the approved account and footer inventories", async () => {
    const router = setup();
    await screen.findByRole("heading", { name: "Family home" });
    await userEvent.click(screen.getByLabelText("Open account menu for David"));
    expect(
      screen.getByRole("menuitem", { name: "View my Person page" }),
    ).toBeEnabled();
    await userEvent.click(
      screen.getByRole("menuitem", { name: "View my Person page" }),
    );
    expect(router.state.location.pathname).toBe(
      "/families/first-family/people/person-david",
    );
    await userEvent.click(screen.getByLabelText("Open account menu for David"));
    expect(
      screen.getByRole("menuitem", { name: "Family overview" }),
    ).toBeDisabled();
    expect(
      screen.getByRole("menuitem", { name: "Platform Admin" }),
    ).toBeDisabled();
    expect(screen.getByRole("menuitem", { name: "Sign out" })).toBeEnabled();
    expect(
      screen.getByRole("navigation", { name: "Footer navigation" }),
    ).toHaveTextContent(
      "AboutAbout FambamHow it worksOur storyContactLegalTerms of ServicePrivacy PolicyCookiesData & SecuritySupportHelp CentreGet in touchFeature requestsStatus",
    );
    expect(
      screen.getByText("© 2026 Fambam. Family memories, carefully kept."),
    ).toBeInTheDocument();
  });

  it("signs out from the final account-menu action", async () => {
    const router = setup();
    await screen.findByRole("heading", { name: "Family home" });
    await userEvent.click(screen.getByLabelText("Open account menu for David"));
    await userEvent.click(screen.getByRole("menuitem", { name: "Sign out" }));
    expect(await screen.findByText("Sign in page")).toBeInTheDocument();
    expect(router.state.location.pathname).toBe("/login");
  });

  it("keeps a missing Family Space out of the shell", async () => {
    setup("owner", true);
    expect(
      await screen.findByRole("heading", { name: "Family Space not found" }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("navigation", { name: "Main navigation" }),
    ).not.toBeInTheDocument();
  });
});
