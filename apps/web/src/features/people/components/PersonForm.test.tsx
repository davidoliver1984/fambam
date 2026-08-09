import "@testing-library/jest-dom/vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { expect, it, vi } from "vitest";

import { PersonForm } from "./PersonForm";

it("maps nested Laravel validation errors onto their visible form fields", async () => {
  const user = userEvent.setup();
  const onSubmit = vi.fn().mockRejectedValue({
    isAxiosError: true,
    response: {
      status: 422,
      data: {
        errors: {
          "alternate_names.0": ["An alternate name is invalid."],
          "birth_date.value": ["The birth date is invalid."],
          "death_date.value": ["The death date is invalid."],
        },
      },
    },
  });
  render(
    <PersonForm
      submitLabel="Save Person"
      pending={false}
      successMessage="Saved."
      onSubmit={onSubmit}
    />,
  );
  await user.type(screen.getByLabelText("Preferred name"), "Ada");
  await user.type(screen.getByLabelText("Alternate names"), "A");
  await user.click(screen.getByRole("button", { name: "Save Person" }));

  expect(
    await screen.findByText("An alternate name is invalid."),
  ).toBeVisible();
  expect(screen.getByText("The birth date is invalid.")).toBeVisible();
  expect(screen.getByText("The death date is invalid.")).toBeVisible();
});
