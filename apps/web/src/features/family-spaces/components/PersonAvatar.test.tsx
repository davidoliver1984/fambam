import "@testing-library/jest-dom/vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { PersonAvatar } from "./PersonAvatar";

describe("PersonAvatar", () => {
  it("falls back to initials when the image cannot be loaded", () => {
    render(
      <PersonAvatar
        name="Sarah Mercer"
        portraitUrl="https://media.example.test/missing.jpg"
      />,
    );

    fireEvent.error(screen.getByRole("presentation", { hidden: true }));

    expect(screen.getByText("SM")).toBeInTheDocument();
    expect(
      screen.queryByRole("presentation", { hidden: true }),
    ).not.toBeInTheDocument();
  });
});
