import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import { TwoFactorChallengePage } from "./TwoFactorChallengePage";

vi.mock("../hooks/useTwoFactorMutations", () => ({
  useTwoFactorChallengeMutation: () => ({
    isError: false,
    isPending: false,
    mutateAsync: vi.fn(),
    reset: vi.fn(),
  }),
}));

afterEach(cleanup);

describe("TwoFactorChallengePage", () => {
  it("shows one sign-in method at a time", async () => {
    const user = userEvent.setup();
    render(
      <QueryClientProvider client={new QueryClient()}>
        <TwoFactorChallengePage />
      </QueryClientProvider>,
    );

    expect(screen.getByLabelText("Six-digit code")).toBeInTheDocument();
    expect(screen.queryByLabelText("Recovery code")).not.toBeInTheDocument();

    await user.click(
      screen.getByRole("button", { name: "Use a recovery code instead" }),
    );

    expect(screen.getByLabelText("Recovery code")).toBeInTheDocument();
    expect(screen.queryByLabelText("Six-digit code")).not.toBeInTheDocument();

    await user.click(
      screen.getByRole("button", {
        name: "Use an authenticator code instead",
      }),
    );

    expect(screen.getByLabelText("Six-digit code")).toBeInTheDocument();
    expect(screen.queryByLabelText("Recovery code")).not.toBeInTheDocument();
  });
});
