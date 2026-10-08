import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { server } from "@/test/msw/server";

import type { CreateStoryInput } from "../types/story";
import { CreateStoryPage } from "./CreateStoryPage";

afterEach(cleanup);

describe("CreateStoryPage", () => {
  it("creates a Story with the same canonical structured document used by Edit", async () => {
    let submitted: CreateStoryInput | undefined;
    server.use(
      http.get("http://localhost:8082/api/families/mercer/people", () =>
        HttpResponse.json({
          data: {
            items: [{ id: "person-1", preferred_name: "Ada Mercer" }],
            next_cursor: null,
          },
        }),
      ),
      http.get("http://localhost:8082/sanctum/csrf-cookie", () =>
        HttpResponse.json({}),
      ),
      http.post(
        "http://localhost:8082/api/families/mercer/stories",
        async ({ request }) => {
          submitted = (await request.json()) as CreateStoryInput;
          return HttpResponse.json({ data: { id: "story-new" } });
        },
      ),
    );
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    const router = createMemoryRouter(
      [
        {
          path: "/families/:familySlug/stories/new",
          element: <CreateStoryPage />,
        },
        {
          path: "/families/:familySlug/stories/:storyId",
          element: <p>Created Story</p>,
        },
      ],
      { initialEntries: ["/families/mercer/stories/new"] },
    );
    render(
      <QueryClientProvider client={client}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    );

    await userEvent.selectOptions(
      screen.getByLabelText("Choose person"),
      await screen.findByRole("option", { name: "Ada Mercer" }),
    );
    const editor = screen.getByRole("textbox", { name: "Your Story" });
    editor.innerHTML =
      "<h2>A bright morning</h2><p>With <strong>the whole family</strong>.</p><hr><p>Home again.</p>";
    fireEvent.input(editor);
    await userEvent.click(
      screen.getByRole("button", { name: "Publish Story" }),
    );

    await waitFor(() => {
      expect(submitted).toEqual({
        subject_type: "person",
        subject_id: "person-1",
        body: {
          schema_version: 1,
          blocks: [
            {
              type: "heading_2",
              content: [{ type: "text", text: "A bright morning" }],
            },
            {
              type: "paragraph",
              content: [
                { type: "text", text: "With " },
                {
                  type: "text",
                  text: "the whole family",
                  marks: ["bold"],
                },
                { type: "text", text: "." },
              ],
            },
            { type: "horizontal_rule" },
            {
              type: "paragraph",
              content: [{ type: "text", text: "Home again." }],
            },
          ],
        },
      });
    });
    expect(await screen.findByText("Created Story")).toBeInTheDocument();
  });
});
