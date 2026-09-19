import "@testing-library/jest-dom/vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router";
import { afterEach, describe, expect, it } from "vitest";

import { UiPlaygroundPage } from "./UiPlaygroundPage";

afterEach(() => {
  cleanup();
});

describe("UiPlaygroundPage", () => {
  it("labels the major interface-element groups", () => {
    render(
      <MemoryRouter>
        <UiPlaygroundPage />
      </MemoryRouter>,
    );

    expect(
      screen.getByRole("heading", { name: "Interface elements" }),
    ).toBeInTheDocument();
    expect(screen.getByText("Primary buttons")).toBeInTheDocument();
    expect(screen.getByText("Text input")).toBeInTheDocument();
    expect(screen.getByText("Error message")).toBeInTheDocument();
    expect(
      screen.getByText("Photo grid · Search results / Albums / Photos"),
    ).toBeInTheDocument();
    expect(screen.getByText("Photograph card")).toBeInTheDocument();
    expect(screen.getByText("Breadcrumbs")).toBeInTheDocument();
  });

  it("exposes common controls with accessible names", () => {
    render(
      <MemoryRouter>
        <UiPlaygroundPage />
      </MemoryRouter>,
    );

    expect(screen.getByLabelText("Photo title")).toBeInTheDocument();
    expect(screen.getAllByRole("searchbox")).toHaveLength(2);
    expect(screen.getByRole("button", { name: "Save changes" })).toBeEnabled();
    expect(screen.getByRole("button", { name: "Saving…" })).toBeDisabled();
    expect(screen.getByRole("table")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Open Summer at the coast" }),
    ).toBeInTheDocument();
  });
});
