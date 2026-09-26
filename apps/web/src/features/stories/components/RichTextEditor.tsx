import { useEffect, useState, type ChangeEvent } from "react";

import { getStoryMentionSuggestions } from "../api/storyApi";
import type {
  RichTextBlock,
  RichTextDocument,
  RichTextInlineNode,
  RichTextMark,
  RichTextTextBlock,
  RichTextTextNode,
} from "../types/story";

type ActiveMentionQuery = {
  blockIndex: number;
  nodeIndex: number;
  start: number;
  end: number;
  prefix: string;
};

type RichTextEditorProps = {
  familySlug: string;
  storyId: string;
  value: RichTextDocument;
  onChange: (document: RichTextDocument) => void;
  vocabulary?: "full" | "comment";
  label: string;
};

function textNode(text = ""): RichTextTextNode {
  return { type: "text", text };
}

function textBlock(
  type: RichTextTextBlock["type"] = "paragraph",
): RichTextTextBlock {
  return { type, content: [textNode()] };
}

function replaceBlock(
  document: RichTextDocument,
  blockIndex: number,
  block: RichTextBlock,
): RichTextDocument {
  return {
    ...document,
    blocks: document.blocks.map((item, index) =>
      index === blockIndex ? block : item,
    ),
  };
}

function replaceInline(
  block: RichTextTextBlock,
  nodeIndex: number,
  nodes: RichTextInlineNode[],
): RichTextTextBlock {
  return {
    ...block,
    content: block.content.flatMap((node, index) =>
      index === nodeIndex ? nodes : [node],
    ),
  };
}

export function RichTextEditor({
  familySlug,
  storyId,
  value,
  onChange,
  vocabulary = "full",
  label,
}: RichTextEditorProps) {
  const [activeText, setActiveText] = useState<{
    blockIndex: number;
    nodeIndex: number;
  } | null>(null);
  const [mentionQuery, setMentionQuery] = useState<ActiveMentionQuery | null>(
    null,
  );
  const [suggestions, setSuggestions] = useState<
    Array<{ id: string; label: string }>
  >([]);

  useEffect(() => {
    if (mentionQuery === null || mentionQuery.prefix === "") {
      return;
    }
    const controller = new AbortController();
    void getStoryMentionSuggestions(
      familySlug,
      storyId,
      mentionQuery.prefix,
      controller.signal,
    )
      .then(setSuggestions)
      .catch(() => {
        if (!controller.signal.aborted) setSuggestions([]);
      });

    return () => {
      controller.abort();
    };
  }, [familySlug, mentionQuery, storyId]);

  function changeText(
    event: ChangeEvent<HTMLTextAreaElement>,
    blockIndex: number,
    nodeIndex: number,
  ) {
    const block = value.blocks[blockIndex];
    if (block.type === "horizontal_rule") return;
    const node = block.content[nodeIndex];
    if (node.type !== "text") return;
    const nextNode = { ...node, text: event.target.value };
    onChange(
      replaceBlock(
        value,
        blockIndex,
        replaceInline(block, nodeIndex, [nextNode]),
      ),
    );
    setActiveText({ blockIndex, nodeIndex });

    const caret = event.target.selectionStart;
    const beforeCaret = event.target.value.slice(0, caret);
    const match = /(?:^|\s)@([^\s@]*)$/.exec(beforeCaret);
    if (match === null) {
      setMentionQuery(null);
      setSuggestions([]);
      return;
    }
    const start = beforeCaret.lastIndexOf("@");
    setMentionQuery({
      blockIndex,
      nodeIndex,
      start,
      end: caret,
      prefix: match[1],
    });
    if (match[1] === "") setSuggestions([]);
  }

  function selectMention(person: { id: string; label: string }) {
    if (mentionQuery === null) return;
    const block = value.blocks[mentionQuery.blockIndex];
    if (block.type === "horizontal_rule") return;
    const node = block.content[mentionQuery.nodeIndex];
    if (node.type !== "text") return;
    const before = node.text.slice(0, mentionQuery.start);
    const after = node.text.slice(mentionQuery.end);
    const replacement: RichTextInlineNode[] = [];
    if (before !== "") replacement.push({ ...node, text: before });
    replacement.push({
      type: "mention",
      person_id: person.id,
      label: person.label,
    });
    replacement.push({ ...node, text: after });
    onChange(
      replaceBlock(
        value,
        mentionQuery.blockIndex,
        replaceInline(block, mentionQuery.nodeIndex, replacement),
      ),
    );
    setMentionQuery(null);
    setSuggestions([]);
  }

  function toggleMark(mark: RichTextMark) {
    if (activeText === null || vocabulary === "comment") return;
    const block = value.blocks[activeText.blockIndex];
    if (block.type === "horizontal_rule") return;
    const node = block.content[activeText.nodeIndex];
    if (node.type !== "text") return;
    const marks = node.marks ?? [];
    const nextMarks = marks.includes(mark)
      ? marks.filter((item) => item !== mark)
      : [...marks, mark];
    const nextNode: RichTextTextNode = { ...node };
    if (nextMarks.length === 0) delete nextNode.marks;
    else nextNode.marks = nextMarks;
    onChange(
      replaceBlock(
        value,
        activeText.blockIndex,
        replaceInline(block, activeText.nodeIndex, [nextNode]),
      ),
    );
  }

  function removeBlock(blockIndex: number) {
    const blocks = value.blocks.filter((_, index) => index !== blockIndex);
    onChange({
      ...value,
      blocks: blocks.length === 0 ? [textBlock()] : blocks,
    });
    setMentionQuery(null);
  }

  return (
    <div aria-label={label}>
      {vocabulary === "full" && (
        <div role="toolbar" aria-label={`${label} formatting`}>
          <button
            type="button"
            onClick={() => {
              toggleMark("bold");
            }}
          >
            Bold
          </button>
          <button
            type="button"
            onClick={() => {
              toggleMark("italic");
            }}
          >
            Italic
          </button>
          <button
            type="button"
            onClick={() => {
              onChange({ ...value, blocks: [...value.blocks, textBlock()] });
            }}
          >
            Paragraph
          </button>
          <button
            type="button"
            onClick={() => {
              onChange({
                ...value,
                blocks: [...value.blocks, textBlock("heading_2")],
              });
            }}
          >
            Heading 2
          </button>
          <button
            type="button"
            onClick={() => {
              onChange({
                ...value,
                blocks: [...value.blocks, textBlock("heading_3")],
              });
            }}
          >
            Heading 3
          </button>
          <button
            type="button"
            onClick={() => {
              onChange({
                ...value,
                blocks: [...value.blocks, { type: "horizontal_rule" }],
              });
            }}
          >
            Divider
          </button>
        </div>
      )}
      {value.blocks.map((block, blockIndex) => (
        <div key={blockIndex}>
          {block.type === "horizontal_rule" ? (
            <hr />
          ) : (
            <>
              {vocabulary === "full" && (
                <select
                  aria-label={`Block ${String(blockIndex + 1)} type`}
                  value={block.type}
                  onChange={(event) => {
                    onChange(
                      replaceBlock(value, blockIndex, {
                        ...block,
                        type: event.target.value as RichTextTextBlock["type"],
                      }),
                    );
                  }}
                >
                  <option value="paragraph">Paragraph</option>
                  <option value="heading_2">Heading 2</option>
                  <option value="heading_3">Heading 3</option>
                </select>
              )}
              {block.content.map((node, nodeIndex) =>
                node.type === "mention" ? (
                  <span
                    key={
                      node.mention_id ??
                      `${node.person_id}-${String(nodeIndex)}`
                    }
                  >
                    @{node.label}
                    <button
                      type="button"
                      aria-label={`Remove mention ${node.label}`}
                      onClick={() => {
                        onChange(
                          replaceBlock(
                            value,
                            blockIndex,
                            replaceInline(block, nodeIndex, []),
                          ),
                        );
                      }}
                    >
                      Remove
                    </button>
                  </span>
                ) : (
                  <textarea
                    key={nodeIndex}
                    aria-label={`Block ${String(blockIndex + 1)} text ${String(nodeIndex + 1)}`}
                    rows={block.type === "paragraph" ? 3 : 1}
                    value={node.text}
                    data-bold={node.marks?.includes("bold") || undefined}
                    data-italic={node.marks?.includes("italic") || undefined}
                    onFocus={() => {
                      setActiveText({ blockIndex, nodeIndex });
                    }}
                    onChange={(event) => {
                      changeText(event, blockIndex, nodeIndex);
                    }}
                  />
                ),
              )}
              <button
                type="button"
                onClick={() => {
                  onChange(
                    replaceBlock(value, blockIndex, {
                      ...block,
                      content: [...block.content, textNode()],
                    }),
                  );
                }}
              >
                Continue writing
              </button>
            </>
          )}
          <button
            type="button"
            onClick={() => {
              removeBlock(blockIndex);
            }}
          >
            Remove block
          </button>
        </div>
      ))}
      {vocabulary === "comment" && (
        <button
          type="button"
          onClick={() => {
            onChange({ ...value, blocks: [...value.blocks, textBlock()] });
          }}
        >
          Add paragraph
        </button>
      )}
      {mentionQuery !== null && mentionQuery.prefix !== "" && (
        <div role="listbox" aria-label="Mention a person">
          {suggestions.map((person) => (
            <button
              key={person.id}
              type="button"
              role="option"
              aria-selected="false"
              onClick={() => {
                selectMention(person);
              }}
            >
              {person.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
