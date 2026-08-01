import "@testing-library/jest-dom/vitest";
import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { App } from "./App";

describe("App", () => {
  it("renders the family archive foundation", () => {
    render(<App path="/" />);

    expect(
      screen.getByRole("heading", {
        name: "A private home for family memories.",
      }),
    ).toBeInTheDocument();
  });

  it("exposes a health view", () => {
    render(<App path="/health" />);

    expect(
      screen.getByRole("heading", { name: "Web application healthy" }),
    ).toBeInTheDocument();
  });
});
