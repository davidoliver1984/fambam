import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router";

import { server } from "@/test/msw/server";

import { HomePage } from "./HomePage";

const apiBaseUrl = "http://localhost:8082";
const presentation =
  "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 9'%3E%3Crect width='16' height='9' fill='%23874c34'/%3E%3C/svg%3E";

const family = {
  id: "family-1",
  slug: "mercer-family",
  name: "Mercer family",
  status: "active" as const,
  role: "owner" as const,
};

function photo(id: string, alt: string) {
  return {
    id,
    media_upload_id: `upload-${id}`,
    active_photo_version_id: null,
    alt,
    presentation: {
      url: presentation,
      method: "GET" as const,
      expires_at: "2026-09-26T13:00:00Z",
    },
  };
}

function renderHome(
  requests: string[],
  contribution: {
    photoCount: number;
    photos: ReturnType<typeof photo>[];
  } = {
    photoCount: 24,
    photos: [
      photo("photo-pier", "The family at Blackpool"),
      photo("photo-tower", "Blackpool Tower"),
      photo("photo-beach", "On Blackpool beach"),
      photo("photo-tram", "Riding the tram"),
      photo("photo-coats", "Coats by the promenade"),
    ],
  },
) {
  server.use(
    http.get(`${apiBaseUrl}/api/user`, ({ request }) => {
      requests.push(new URL(request.url).pathname);
      return HttpResponse.json({
        data: {
          id: 1,
          name: "David Oliver",
          email: "david@example.test",
          timezone: "Europe/London",
          email_verified_at: "2026-01-01T00:00:00Z",
          can_create_family_spaces: false,
          two_factor_enabled: false,
        },
      });
    }),
    http.get(`${apiBaseUrl}/api/families/mercer-family/home`, ({ request }) => {
      requests.push(new URL(request.url).pathname);
      return HttpResponse.json({
        data: {
          activity: [
            {
              id: "activity-album",
              action_type: "photos_added_to_album",
              actor: {
                user_id: 2,
                name: "Sarah Mercer",
                person_id: "person-sarah",
              },
              subject: {
                type: "album",
                id: "album-blackpool",
                label: "Blackpool holiday",
              },
              contribution_batch_id: "batch-1",
              photo_ids: contribution.photos.map((item) => item.id),
              photo_count: contribution.photoCount,
              created_at: "2026-09-26T10:00:00Z",
              album: {
                id: "album-blackpool",
                name: "Blackpool, the wind and Grandma’s pink coat",
                description:
                  "Dad found this envelope behind the old telephone table.",
                starts_on: "1986-08-12",
                ends_on: "1986-08-19",
                location: "Blackpool",
              },
              feature_photo: contribution.photos[0] ?? null,
              contribution_photos: contribution.photos,
              engagement: {
                love_count: 8,
                loved_by_me: false,
                comment_count: 3,
              },
            },
            {
              id: "activity-story",
              action_type: "story_added",
              actor: {
                user_id: 3,
                name: "Jane Mercer",
                person_id: "person-jane",
              },
              subject: {
                type: "story",
                id: "story-drive",
                label: "Story",
                subject_type: "person",
                subject_id: "person-william",
              },
              contribution_batch_id: null,
              photo_ids: [],
              photo_count: 0,
              created_at: "2026-09-25T12:00:00Z",
              story: {
                id: "story-drive",
                heading:
                  "The day William tried to drive to Scotland with two biscuits and his old library card in his pocket",
                excerpt:
                  "He was six, the Mini was parked on the drive, and his provisions consisted of two digestives.",
                subject: {
                  type: "person",
                  id: "person-william",
                  label: "William Mercer",
                },
              },
              engagement: {
                love_count: 5,
                loved_by_me: true,
                comment_count: 2,
              },
            },
          ],
          latest_photos: [photo("latest-1", "Recent family photograph")],
          on_this_day: {
            photo_id: "memory-1",
            media_upload_id: "upload-memory-1",
            label: "Christmas at Nan’s",
            reason: "On this day in 1994",
            location: "Ashton-under-Lyne",
            historical_date: { precision: "exact", value: "1994-09-26" },
            added_at: "2026-01-01T00:00:00Z",
            photo: photo("memory-1", "Christmas dinner at Nan's"),
          },
        },
      });
    }),
    http.get(
      `${apiBaseUrl}/api/families/mercer-family/people`,
      ({ request }) => {
        requests.push(new URL(request.url).pathname);
        return HttpResponse.json({
          data: [
            {
              id: "person-emma",
              preferred_name: "Emma Mercer",
              alternate_names: [],
              identity_status: "confirmed",
              birth_date: { precision: "exact", value: "2003-10-02" },
              is_deceased: false,
              death_date: { precision: "unknown", value: null },
              biography: null,
              account_link: null,
              redirected_from_person_id: null,
              created_at: "2026-01-01T00:00:00Z",
              updated_at: "2026-01-01T00:00:00Z",
              permissions: {},
            },
            {
              id: "person-uncertain",
              preferred_name: "Uncertain Birthday",
              alternate_names: [],
              identity_status: "confirmed",
              birth_date: { precision: "year", value: "1970" },
              is_deceased: false,
              death_date: { precision: "unknown", value: null },
              biography: null,
              account_link: null,
              redirected_from_person_id: null,
              created_at: "2026-01-01T00:00:00Z",
              updated_at: "2026-01-01T00:00:00Z",
              permissions: {},
            },
          ],
        });
      },
    ),
  );

  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={["/families/mercer-family"]}>
        <HomePage familySpace={family} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

describe("HomePage", () => {
  it("binds the bounded Home payload to the frozen card and rail composition", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.setSystemTime(new Date("2026-09-26T12:00:00Z"));
    const requests: string[] = [];
    renderHome(requests);

    expect(
      await screen.findByRole("heading", { name: "Good afternoon, David" }),
    ).toBeInTheDocument();
    expect(screen.getByText("The Mercer family")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Create album" })).toHaveAttribute(
      "href",
      "/families/mercer-family/albums/new",
    );
    expect(
      screen.getByRole("link", {
        name: "Blackpool, the wind and Grandma’s pink coat",
      }),
    ).toHaveAttribute("href", "/families/mercer-family/albums/album-blackpool");
    expect(screen.getByText(/added 24 new photos to/)).toBeInTheDocument();
    expect(
      screen.queryByRole("heading", {
        name: "Blackpool, the wind and Grandma’s pink coat",
      }),
    ).not.toBeInTheDocument();
    expect(screen.getByText("24 photos")).toBeInTheDocument();
    expect(screen.getByText("+19")).toHaveAccessibleName("19 more photos");
    expect(
      screen.getByRole("link", {
        name: "Open Blackpool, the wind and Grandma’s pink coat · 24 photos",
      }),
    ).toHaveClass("home-contribution-mosaic", "home-mosaic-count-5");
    expect(
      screen.getByRole("heading", {
        name: "The day William tried to drive to Scotland with two biscuits and his old library card in his pocket",
      }),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        "The day William tried to drive to Scotland with two biscuits and his old library…",
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "William Mercer" }),
    ).toHaveAttribute("href", "/families/mercer-family/people/person-william");
    expect(
      screen.getByRole("link", { name: "Open recent photograph 1" }),
    ).toHaveAttribute("href", "/families/mercer-family/photos/latest-1");
    expect(
      screen.getByText("32 years ago · Ashton-under-Lyne"),
    ).toBeInTheDocument();
    expect(screen.getByText("2 October · turns 23")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "View Emma Mercer" }),
    ).toHaveAttribute("href", "/families/mercer-family/people/person-emma");
    expect(screen.getByRole("link", { name: /^Emma$/ })).toHaveAttribute(
      "href",
      "/families/mercer-family/people/person-emma",
    );
    expect(screen.queryByText("Uncertain Birthday")).not.toBeInTheDocument();
    expect(requests.sort()).toEqual(
      [
        "/api/families/mercer-family/home",
        "/api/families/mercer-family/people",
        "/api/user",
      ].sort(),
    );
  });

  it("opens the shared keyboard-accessible feed menu", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.setSystemTime(new Date("2026-09-26T12:00:00Z"));
    renderHome([]);
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

    const menus = await screen.findAllByRole("button", {
      name: "Feed item options",
    });
    await user.click(menus[0]);
    expect(screen.getByRole("menu")).toBeInTheDocument();
    expect(
      screen.getByRole("menuitem", { name: "View album" }),
    ).toHaveAttribute("href", "/families/mercer-family/albums/album-blackpool");
    await user.keyboard("{Escape}");
    expect(screen.queryByRole("menu")).not.toBeInTheDocument();
    expect(menus[0]).toHaveFocus();
  });

  it("keeps a one-photo contribution in the existing feature-image treatment", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.setSystemTime(new Date("2026-09-26T12:00:00Z"));
    renderHome([], {
      photoCount: 1,
      photos: [photo("photo-pier", "The family at Blackpool")],
    });

    const feature = await screen.findByRole("link", {
      name: "Open Blackpool, the wind and Grandma’s pink coat",
    });
    expect(feature).toHaveClass("home-feature-image");
    expect(feature).not.toHaveClass("home-contribution-mosaic");
    expect(screen.getByText("1 photo")).toBeInTheDocument();
    expect(screen.queryByText(/^\+/)).not.toBeInTheDocument();
  });

  it("keeps the mosaic treatment when authorization leaves one visible tile", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.setSystemTime(new Date("2026-09-26T12:00:00Z"));
    renderHome([], {
      photoCount: 3,
      photos: [photo("photo-pier", "The family at Blackpool")],
    });

    const feature = await screen.findByRole("link", {
      name: "Open Blackpool, the wind and Grandma’s pink coat · 3 photos",
    });
    expect(feature).toHaveClass(
      "home-contribution-mosaic",
      "home-mosaic-count-1",
    );
    expect(screen.getByText("+2")).toHaveAccessibleName("2 more photos");
  });

  it("updates payload-seeded love without fetching a per-card summary", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.setSystemTime(new Date("2026-09-26T12:00:00Z"));
    const requests: string[] = [];
    server.use(
      http.get(`${apiBaseUrl}/sanctum/csrf-cookie`, () =>
        HttpResponse.json({}),
      ),
      http.put(
        `${apiBaseUrl}/api/families/mercer-family/albums/album-blackpool/love`,
        ({ request }) => {
          requests.push(`${request.method} ${new URL(request.url).pathname}`);
          return HttpResponse.json({
            data: { count: 9, loved_by_me: true, reactors: [] },
          });
        },
      ),
      http.delete(
        `${apiBaseUrl}/api/families/mercer-family/albums/album-blackpool/love`,
        ({ request }) => {
          requests.push(`${request.method} ${new URL(request.url).pathname}`);
          return new HttpResponse(null, { status: 204 });
        },
      ),
    );
    renderHome([]);
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

    const love = await screen.findByRole("button", { name: "Love · 8" });
    await user.click(love);
    expect(
      await screen.findByRole("button", { name: "Remove love · 9" }),
    ).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Remove love · 9" }));
    expect(
      await screen.findByRole("button", { name: "Love · 8" }),
    ).toBeInTheDocument();
    expect(requests).toEqual([
      "PUT /api/families/mercer-family/albums/album-blackpool/love",
      "DELETE /api/families/mercer-family/albums/album-blackpool/love",
    ]);
  });
});
