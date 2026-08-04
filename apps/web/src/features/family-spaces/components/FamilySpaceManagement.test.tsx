import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router";

import { createFamilySpace, getFamilySpaces } from "../api/familySpaceApi";
import { FamilySpaceManagement } from "./FamilySpaceManagement";

vi.mock("../api/familySpaceApi", () => ({
  createFamilySpace: vi.fn(),
  getFamilySpaces: vi.fn(),
}));

const familySpace = {
  id: "01K1ZZZZZZZZZZZZZZZZZZZZZZ",
  slug: "oliver-family",
  name: "Oliver Family",
  status: "active" as const,
  role: "owner" as const,
};

function renderManagement(canCreate: boolean) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <FamilySpaceManagement canCreate={canCreate} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(getFamilySpaces).mockResolvedValue([familySpace]);
  vi.mocked(createFamilySpace).mockResolvedValue(familySpace);
});

afterEach(() => {
  cleanup();
});

describe("FamilySpaceManagement", () => {
  it("shows creation only for the platform capability", async () => {
    renderManagement(false);

    expect(await screen.findByText("Oliver Family")).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Create Family Space" }),
    ).not.toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Open Family Space" }),
    ).toHaveAttribute("href", "/families/oliver-family");
  });

  it("creates through the feature mutation", async () => {
    const user = userEvent.setup();
    renderManagement(true);
    await screen.findByText("Oliver Family");

    await user.type(screen.getByLabelText("Family Space name"), "New Family");
    await user.type(screen.getByLabelText("URL name"), "new-family");
    await user.click(
      screen.getByRole("button", { name: "Create Family Space" }),
    );

    expect(vi.mocked(createFamilySpace).mock.calls[0]?.[0]).toEqual({
      name: "New Family",
      slug: "new-family",
    });
    expect(
      await screen.findByText("Family Space created."),
    ).toBeInTheDocument();
  });
});
