import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it } from "vitest";
import { MemoryRouter } from "react-router";

import { server } from "@/test/msw/server";

import { LoveButton } from "./LoveButton";

afterEach(cleanup);

describe("LoveButton", () => {
  it("loads and toggles the shared Love response", async () => {
    let loved = false;
    const endpoint =
      "http://localhost:8082/api/families/mercer/albums/album-1/love";
    server.use(
      http.get(endpoint, () =>
        HttpResponse.json({
          data: { count: loved ? 1 : 0, loved_by_me: loved, reactors: [] },
        }),
      ),
      http.put(endpoint, () => {
        loved = true;
        return HttpResponse.json({
          data: { count: 1, loved_by_me: true, reactors: [] },
        });
      }),
    );
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <LoveButton
            familySlug="mercer"
            targetType="album"
            targetId="album-1"
          />
        </MemoryRouter>
      </QueryClientProvider>,
    );
    const button = await screen.findByRole("button", { name: /Love this/ });
    await userEvent.click(button);
    expect(
      await screen.findByRole("button", { name: /Loved/ }),
    ).toHaveAttribute("aria-pressed", "true");
  });
});
