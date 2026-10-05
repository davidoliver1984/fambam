import "@testing-library/jest-dom/vitest";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  within,
} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it } from "vitest";
import { useState } from "react";

import type { RichTextDocument } from "../types/story";
import {
  editorElementToRichTextDocument,
  RichTextEditor,
  richTextDocumentToEditorHtml,
} from "./RichTextEditor";

afterEach(cleanup);

const richDocument: RichTextDocument = {
  schema_version: 1,
  blocks: [
    {
      type: "heading_2",
      content: [{ type: "text", text: "Heading", marks: ["bold", "italic"] }],
    },
    {
      type: "paragraph",
      content: [
        { type: "text", text: "With " },
        {
          type: "mention",
          mention_id: "01AAAAAAAAAAAAAAAAAAAAAAAA",
          person_id: "person-1",
          label: "Ada Mercer",
        },
        { type: "text", text: " at the beach", marks: ["italic"] },
      ],
    },
    { type: "horizontal_rule" },
    {
      type: "heading_3",
      content: [{ type: "text", text: "Afterwards" }],
    },
    {
      type: "paragraph",
      content: [{ type: "text", text: "Tea on the promenade." }],
    },
  ],
};

function Harness({ initial = richDocument }: { initial?: RichTextDocument }) {
  const [value, setValue] = useState(initial);
  return (
    <>
      <RichTextEditor
        familySlug="mercer"
        storyId="story-1"
        label="Story body"
        value={value}
        mentionOptions={[{ id: "person-1", label: "Ada Mercer" }]}
        onChange={setValue}
      />
      <output data-testid="document">{JSON.stringify(value)}</output>
    </>
  );
}

describe("RichTextEditor", () => {
  it("renders one continuous document and hides block-builder controls", () => {
    render(<Harness />);

    const editor = screen.getByRole("textbox", { name: "Story body" });
    expect(editor).toHaveAttribute("contenteditable", "true");
    expect(editor.querySelectorAll("h2")).toHaveLength(1);
    expect(editor.querySelectorAll("h3")).toHaveLength(1);
    expect(editor.querySelectorAll("hr")).toHaveLength(1);
    expect(within(editor).getByText("@Ada Mercer")).toHaveAttribute(
      "data-person-id",
      "person-1",
    );
    expect(
      screen.getByRole("combobox", { name: "Text style" }),
    ).toHaveTextContent("ParagraphHeading 2Heading 3");
    expect(
      screen.queryByRole("button", { name: "Continue writing" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Remove block" }),
    ).not.toBeInTheDocument();
  });

  it("round-trips headings, marks, dividers and typed mentions without loss", () => {
    const root = document.createElement("div");
    root.innerHTML = richTextDocumentToEditorHtml(richDocument);
    expect(editorElementToRichTextDocument(root)).toEqual(richDocument);
  });

  it("opens an accessible Person picker from the compact toolbar", async () => {
    render(<Harness />);
    await userEvent.click(
      screen.getByRole("button", { name: "Mention a person" }),
    );

    expect(
      screen.getByRole("dialog", { name: "Mention a person" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("searchbox", { name: "Find a person" }),
    ).toBeInTheDocument();
    const option = screen.getByRole("option", { name: "Ada Mercer" });
    expect(option).toBeInTheDocument();
    expect(
      option.querySelector(".rich-text-editor__mention-avatar"),
    ).toHaveTextContent("AM");

    await userEvent.click(screen.getByRole("textbox", { name: "Story body" }));
    expect(
      screen.queryByRole("dialog", { name: "Mention a person" }),
    ).not.toBeInTheDocument();
  });

  it("toggles bold and italic off as well as on for selected text", async () => {
    render(<Harness />);
    const editor = screen.getByRole("textbox", { name: "Story body" });
    const paragraph = editor.querySelectorAll("p")[1];
    const selectedText = paragraph.firstChild;
    expect(selectedText).toBeInstanceOf(Text);
    if (selectedText === null) throw new Error("Expected paragraph text");

    const selection = window.getSelection();
    const range = document.createRange();
    range.setStart(selectedText, 11);
    range.setEnd(selectedText, 20);
    selection?.removeAllRanges();
    selection?.addRange(range);
    fireEvent(document, new Event("selectionchange"));

    const bold = screen.getByRole("button", { name: "Bold" });
    await userEvent.click(bold);
    expect(bold).toHaveAttribute("aria-pressed", "true");
    expect(editor.querySelector("p strong")?.textContent).toBe("promenade");
    await userEvent.click(bold);
    expect(bold).toHaveAttribute("aria-pressed", "false");
    expect(editor.querySelector("p strong")).not.toBeInTheDocument();

    const italic = screen.getByRole("button", { name: "Italic" });
    await userEvent.click(italic);
    expect(italic).toHaveAttribute("aria-pressed", "true");
    expect(editor.querySelector("p:last-of-type em")?.textContent).toBe(
      "promenade",
    );
    await userEvent.click(italic);
    expect(italic).toHaveAttribute("aria-pressed", "false");
    expect(editor.querySelector("p:last-of-type em")).not.toBeInTheDocument();
  });
});
