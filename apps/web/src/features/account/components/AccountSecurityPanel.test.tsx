import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it } from "vitest";

import { AccountSecurityPanel, TwoFactorPanel } from "./AccountSecurityPanel";

afterEach(cleanup);

describe("AccountSecurityPanel", () => {
  it("keeps password confirmation hidden until disabling the authenticator", async () => {
    const user = userEvent.setup();
    const queryClient = new QueryClient();
    render(
      <QueryClientProvider client={queryClient}>
        <TwoFactorPanel enabled />
      </QueryClientProvider>,
    );

    expect(screen.queryByLabelText("Current password")).not.toBeInTheDocument();

    await user.click(
      screen.getByRole("button", { name: "Disable authenticator" }),
    );

    expect(screen.getByLabelText("Current password")).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Show password" }),
    ).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Cancel" }));
    expect(screen.queryByLabelText("Current password")).not.toBeInTheDocument();
  });

  it("rejects mismatched replacement passwords before an API request", async () => {
    const user = userEvent.setup();
    const queryClient = new QueryClient({
      defaultOptions: { mutations: { retry: false } },
    });
    render(
      <QueryClientProvider client={queryClient}>
        <AccountSecurityPanel twoFactorEnabled={false} />
      </QueryClientProvider>,
    );

    const heading = screen.getByRole("heading", { name: "Change password" });
    const form = heading.closest("form");
    if (form === null) throw new Error("Password form is missing");
    const passwordForm = within(form);

    await user.type(
      passwordForm.getByLabelText("Current password"),
      "current-password",
    );
    await user.type(
      passwordForm.getByLabelText("New password"),
      "a-long-replacement-passphrase",
    );
    await user.type(
      passwordForm.getByLabelText("Confirm new password"),
      "a-different-long-passphrase",
    );
    await user.click(
      passwordForm.getByRole("button", { name: "Change password" }),
    );

    expect(await passwordForm.findByRole("alert")).toHaveTextContent(
      "The passwords do not match.",
    );
  });
});
