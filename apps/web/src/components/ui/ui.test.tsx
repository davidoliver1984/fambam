import "@testing-library/jest-dom/vitest";
import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router";

import {
  ArchiveCard,
  Breadcrumbs,
  ButtonLink,
  ConfirmDialog,
  ContextMenu,
  Dialog,
  PageHeader,
  ProductFooter,
  StatusPanel,
} from ".";

afterEach(cleanup);

describe("shared visual primitives", () => {
  it("renders a labelled page hierarchy and typed navigation", () => {
    render(
      <MemoryRouter>
        <Breadcrumbs
          items={[
            { label: "Home", to: "/families/mercer" },
            { label: "Events" },
          ]}
        />
        <PageHeader
          eyebrow="Family timeline"
          title="Events"
          description="The ordinary days and big days we remember."
          actions={<ButtonLink to="/events/new">Create event</ButtonLink>}
        />
      </MemoryRouter>,
    );

    expect(
      screen.getByRole("navigation", { name: "Breadcrumb" }),
    ).toBeVisible();
    expect(screen.getByRole("link", { name: "Home" })).toHaveAttribute(
      "href",
      "/families/mercer",
    );
    expect(screen.getByText("Events", { selector: "span" })).toHaveAttribute(
      "aria-current",
      "page",
    );
    expect(screen.getByRole("heading", { name: "Events" })).toBeVisible();
    expect(screen.getByRole("link", { name: "Create event" })).toBeVisible();
  });

  it("announces errors and empty states with distinct semantics", () => {
    const { rerender } = render(
      <StatusPanel tone="error" title="Events could not be loaded">
        <p>Please try again.</p>
      </StatusPanel>,
    );
    expect(screen.getByRole("alert")).toHaveTextContent(
      "Events could not be loaded",
    );

    rerender(<StatusPanel tone="empty" title="No events yet" />);
    expect(screen.getByRole("status")).toHaveTextContent("No events yet");
  });

  it("preserves typed entity identity in reusable archive cards", () => {
    render(
      <MemoryRouter>
        <ArchiveCard
          entity="event"
          title="A summer together"
          to="/families/mercer/events/summer"
          meta="August 1987"
        />
      </MemoryRouter>,
    );

    const link = screen.getByRole("link", { name: "A summer together" });
    expect(link).toHaveAttribute("data-entity-kind", "event");
    expect(link).toHaveAttribute("href", "/families/mercer/events/summer");
  });

  it("closes a context menu with Escape and restores trigger focus", async () => {
    render(
      <ContextMenu label="Event options">
        <button role="menuitem" type="button">
          Edit event
        </button>
      </ContextMenu>,
    );
    const trigger = screen.getByRole("button", { name: "Event options" });
    await userEvent.click(trigger);
    const menu = screen.getByRole("menu");
    expect(menu).toBeVisible();
    expect(menu.parentElement).toBe(document.body);
    await userEvent.keyboard("{Escape}");
    expect(screen.queryByRole("menu")).not.toBeInTheDocument();
    expect(trigger).toHaveFocus();
  });

  it("moves through context menu actions with the keyboard", async () => {
    render(
      <ContextMenu label="Photo options">
        <button type="button">Add to album</button>
        <button type="button">Edit photo</button>
        <button type="button">Delete photo</button>
      </ContextMenu>,
    );

    await userEvent.click(
      screen.getByRole("button", { name: "Photo options" }),
    );
    const add = screen.getByRole("menuitem", { name: "Add to album" });
    const edit = screen.getByRole("menuitem", { name: "Edit photo" });
    const remove = screen.getByRole("menuitem", { name: "Delete photo" });
    expect(add).toHaveFocus();
    await userEvent.keyboard("{ArrowDown}");
    expect(edit).toHaveFocus();
    await userEvent.keyboard("{End}");
    expect(remove).toHaveFocus();
    await userEvent.keyboard("{Home}");
    expect(add).toHaveFocus();
  });

  it("traps focus in a dialog and restores the opener", async () => {
    const onClose = vi.fn();
    const opener = document.createElement("button");
    document.body.append(opener);
    opener.focus();
    const { rerender } = render(
      <Dialog open title="Edit event" onClose={onClose}>
        <input aria-label="Event name" />
        <button type="button">Save</button>
      </Dialog>,
    );

    expect(screen.getByRole("button", { name: "Close" })).toHaveFocus();
    await userEvent.tab();
    expect(screen.getByRole("textbox", { name: "Event name" })).toHaveFocus();
    await userEvent.keyboard("{Escape}");
    expect(onClose).toHaveBeenCalledOnce();

    rerender(
      <Dialog open={false} title="Edit event" onClose={onClose}>
        <input aria-label="Event name" />
      </Dialog>,
    );
    expect(opener).toHaveFocus();
    opener.remove();
  });

  it("provides a focus-managed destructive confirmation", async () => {
    const onCancel = vi.fn();
    const onConfirm = vi.fn();
    const { rerender } = render(
      <ConfirmDialog
        open
        title="Remove this event?"
        confirmLabel="Remove event"
        destructive
        onCancel={onCancel}
        onConfirm={onConfirm}
      >
        <p>The event can be restored later.</p>
      </ConfirmDialog>,
    );
    expect(screen.getByRole("dialog")).toHaveAccessibleName(
      "Remove this event?",
    );
    expect(screen.getByRole("button", { name: "Cancel" })).toHaveFocus();
    await userEvent.click(screen.getByRole("button", { name: "Remove event" }));
    expect(onConfirm).toHaveBeenCalledOnce();

    rerender(
      <ConfirmDialog
        open={false}
        title="Remove this event?"
        confirmLabel="Remove event"
        onCancel={onCancel}
        onConfirm={onConfirm}
      >
        <p>The event can be restored later.</p>
      </ConfirmDialog>,
    );
  });

  it("renders the full shared product footer", () => {
    render(
      <MemoryRouter>
        <ProductFooter familyHome="/families/mercer" />
      </MemoryRouter>,
    );
    expect(screen.getByRole("contentinfo")).toHaveTextContent(
      "Family memories, carefully kept.",
    );
    expect(
      screen.getByRole("navigation", { name: "Explore Fambam" }),
    ).toBeVisible();
  });
});
