import "@testing-library/jest-dom/vitest";
import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it } from "vitest";
import { useState } from "react";

import { server } from "@/test/msw/server";

import type { RichTextDocument } from "../types/story";
import { RichTextEditor } from "./RichTextEditor";

afterEach(cleanup);

function Harness({ initial }: { initial: RichTextDocument }) {
  const [value, setValue] = useState(initial);
  return (
    <>
      <RichTextEditor
        familySlug="mercer"
        storyId="story-1"
        label="Story body"
        value={value}
        onChange={setValue}
      />
      <output data-testid="document">{JSON.stringify(value)}</output>
    </>
  );
}

describe("RichTextEditor", () => {
  it("preserves blocks, marks, dividers and persisted typed mentions", () => {
    const document: RichTextDocument = {
      schema_version: 1,
      blocks: [
        {
          type: "heading_2",
          content: [
            { type: "text", text: "Heading", marks: ["bold", "italic"] },
          ],
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
          ],
        },
        { type: "horizontal_rule" },
      ],
    };
    render(<Harness initial={document} />);

    expect(screen.getByDisplayValue("Heading")).toHaveAttribute(
      "data-bold",
      "true",
    );
    expect(screen.getByDisplayValue("Heading")).toHaveAttribute(
      "data-italic",
      "true",
    );
    expect(screen.getByText("@Ada Mercer")).toBeInTheDocument();
    expect(screen.getByRole("separator")).toBeInTheDocument();
    expect(screen.getByTestId("document")).toHaveTextContent(
      JSON.stringify(document),
    );
  });

  it("turns an @Person choice into a typed Person node", async () => {
    server.use(
      http.get(
        "http://localhost:8082/api/families/mercer/stories/story-1/mention-suggestions",
        ({ request }) => {
          expect(new URL(request.url).searchParams.get("prefix")).toBe("Ad");
          return HttpResponse.json({
            data: [{ id: "person-1", label: "Ada Mercer" }],
          });
        },
      ),
    );
    render(
      <Harness
        initial={{
          schema_version: 1,
          blocks: [
            { type: "paragraph", content: [{ type: "text", text: "" }] },
          ],
        }}
      />,
    );
    const input = screen.getByRole("textbox", { name: "Block 1 text 1" });
    await userEvent.type(input, "Hello @Ad");
    await userEvent.click(
      await screen.findByRole("option", { name: "Ada Mercer" }),
    );

    const document = JSON.parse(
      screen.getByTestId("document").textContent,
    ) as RichTextDocument;
    expect(document.blocks[0]).toEqual({
      type: "paragraph",
      content: [
        { type: "text", text: "Hello " },
        { type: "mention", person_id: "person-1", label: "Ada Mercer" },
        { type: "text", text: "" },
      ],
    });
  });
});
