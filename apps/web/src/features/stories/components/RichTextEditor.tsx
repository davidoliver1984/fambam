/* eslint-disable react-refresh/only-export-components */
import {
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type ChangeEvent,
  type ClipboardEvent,
  type KeyboardEvent,
} from "react";

import { PersonAvatar } from "@/features/family-spaces/components/PersonAvatar";

import { getStoryMentionSuggestions } from "../api/storyApi";
import type {
  RichTextBlock,
  RichTextDocument,
  RichTextInlineNode,
  RichTextMark,
  RichTextMentionNode,
  RichTextTextBlock,
  RichTextTextNode,
} from "../types/story";

import "./RichTextEditor.css";

type MentionOption = { id: string; label: string };

type RichTextEditorProps = {
  familySlug: string;
  storyId?: string;
  value: RichTextDocument;
  onChange: (document: RichTextDocument) => void;
  vocabulary?: "full" | "comment";
  label: string;
  className?: string;
  mentionOptions?: MentionOption[];
};

function textNode(text = ""): RichTextTextNode {
  return { type: "text", text };
}

function textBlock(
  type: RichTextTextBlock["type"] = "paragraph",
): RichTextTextBlock {
  return { type, content: [textNode()] };
}

function escapeHtml(value: string) {
  return value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

function inlineNodeHtml(node: RichTextInlineNode) {
  if (node.type === "mention") {
    const mentionId =
      node.mention_id === undefined
        ? ""
        : ` data-mention-id="${escapeHtml(node.mention_id)}"`;
    return `<span class="rich-text-editor__mention" contenteditable="false" data-person-id="${escapeHtml(node.person_id)}" data-person-label="${escapeHtml(node.label)}"${mentionId}>@${escapeHtml(node.label)}</span>`;
  }
  let content = escapeHtml(node.text).replaceAll("\n", "<br>");
  if (node.marks?.includes("italic")) content = `<em>${content}</em>`;
  if (node.marks?.includes("bold")) content = `<strong>${content}</strong>`;
  return content;
}

export function richTextDocumentToEditorHtml(document: RichTextDocument) {
  return document.blocks
    .map((block) => {
      if (block.type === "horizontal_rule")
        return '<hr data-story-divider="true">';
      const tag =
        block.type === "heading_2"
          ? "h2"
          : block.type === "heading_3"
            ? "h3"
            : "p";
      const content = block.content.map(inlineNodeHtml).join("") || "<br>";
      return `<${tag}>${content}</${tag}>`;
    })
    .join("");
}

function sameMarks(left?: RichTextMark[], right?: RichTextMark[]) {
  return JSON.stringify(left ?? []) === JSON.stringify(right ?? []);
}

function appendTextNode(
  output: RichTextInlineNode[],
  text: string,
  marks: RichTextMark[],
) {
  const normalized = text.replaceAll("\u00a0", " ").replaceAll("\u200b", "");
  if (normalized === "") return;
  const previous = output.at(-1);
  if (
    previous?.type === "text" &&
    sameMarks(previous.marks, marks.length === 0 ? undefined : marks)
  ) {
    previous.text += normalized;
    return;
  }
  output.push({
    type: "text",
    text: normalized,
    ...(marks.length === 0 ? {} : { marks }),
  });
}

function parseInlineNode(
  node: Node,
  marks: RichTextMark[],
  output: RichTextInlineNode[],
) {
  if (node.nodeType === Node.TEXT_NODE) {
    appendTextNode(output, node.textContent ?? "", marks);
    return;
  }
  if (!(node instanceof HTMLElement)) return;
  if (node.dataset.personId !== undefined) {
    const mention: RichTextMentionNode = {
      type: "mention",
      person_id: node.dataset.personId,
      label: node.dataset.personLabel ?? node.textContent.replace(/^@/, ""),
    };
    if (node.dataset.mentionId !== undefined)
      mention.mention_id = node.dataset.mentionId;
    output.push(mention);
    return;
  }
  if (node.tagName === "BR") {
    appendTextNode(output, "\n", marks);
    return;
  }
  const nextMarks = [...marks];
  const weight = node.style.fontWeight;
  if (
    (node.tagName === "B" ||
      node.tagName === "STRONG" ||
      weight === "bold" ||
      Number(weight) >= 600) &&
    !nextMarks.includes("bold")
  )
    nextMarks.push("bold");
  if (
    (node.tagName === "I" ||
      node.tagName === "EM" ||
      node.style.fontStyle === "italic") &&
    !nextMarks.includes("italic")
  )
    nextMarks.push("italic");
  node.childNodes.forEach((child) => {
    parseInlineNode(child, nextMarks, output);
  });
}

function blockFromElement(element: HTMLElement): RichTextBlock {
  if (element.tagName === "HR") return { type: "horizontal_rule" };
  const type: RichTextTextBlock["type"] =
    element.tagName === "H2"
      ? "heading_2"
      : element.tagName === "H3"
        ? "heading_3"
        : "paragraph";
  const content: RichTextInlineNode[] = [];
  element.childNodes.forEach((node) => {
    parseInlineNode(node, [], content);
  });
  return { type, content: content.length === 0 ? [textNode()] : content };
}

export function editorElementToRichTextDocument(
  root: HTMLElement,
): RichTextDocument {
  const blocks: RichTextBlock[] = [];
  let looseContent: RichTextInlineNode[] = [];
  const flushLooseContent = () => {
    if (looseContent.length === 0) return;
    blocks.push({ type: "paragraph", content: looseContent });
    looseContent = [];
  };
  root.childNodes.forEach((node) => {
    if (node.nodeType === Node.TEXT_NODE) {
      parseInlineNode(node, [], looseContent);
      return;
    }
    if (!(node instanceof HTMLElement)) return;
    if (["P", "DIV", "H2", "H3", "HR"].includes(node.tagName)) {
      flushLooseContent();
      blocks.push(blockFromElement(node));
      return;
    }
    parseInlineNode(node, [], looseContent);
  });
  flushLooseContent();
  return {
    schema_version: 1,
    blocks: blocks.length === 0 ? [textBlock()] : blocks,
  };
}

function useMentionSuggestions({
  familySlug,
  storyId,
  query,
  open,
  mentionOptions,
}: {
  familySlug: string;
  storyId?: string;
  query: string;
  open: boolean;
  mentionOptions?: MentionOption[];
}) {
  const [remoteSuggestions, setRemoteSuggestions] = useState<MentionOption[]>(
    [],
  );
  const localSuggestions = useMemo(() => {
    if (!open || mentionOptions === undefined) return [];
    const normalized = query.trim().toLocaleLowerCase();
    return mentionOptions
      .filter((person) => person.label.toLocaleLowerCase().includes(normalized))
      .slice(0, 8);
  }, [mentionOptions, open, query]);
  useEffect(() => {
    if (
      !open ||
      mentionOptions !== undefined ||
      storyId === undefined ||
      query.trim() === ""
    )
      return;
    const controller = new AbortController();
    void getStoryMentionSuggestions(
      familySlug,
      storyId,
      query.trim(),
      controller.signal,
    )
      .then(setRemoteSuggestions)
      .catch(() => {
        if (!controller.signal.aborted) setRemoteSuggestions([]);
      });
    return () => {
      controller.abort();
    };
  }, [familySlug, mentionOptions, open, query, storyId]);
  if (!open) return [];
  if (mentionOptions !== undefined) return localSuggestions;
  if (query.trim() === "") return [];
  return remoteSuggestions;
}

function ContinuousStoryEditor({
  familySlug,
  storyId,
  value,
  onChange,
  label,
  className = "",
  mentionOptions,
}: RichTextEditorProps) {
  const editorRef = useRef<HTMLDivElement>(null);
  const mentionInputRef = useRef<HTMLInputElement>(null);
  const mentionControlRef = useRef<HTMLDivElement>(null);
  const savedRange = useRef<Range | null>(null);
  const lastEmitted = useRef<string | null>(null);
  const [style, setStyle] = useState<"paragraph" | "heading_2" | "heading_3">(
    "paragraph",
  );
  const [bold, setBold] = useState(false);
  const [italic, setItalic] = useState(false);
  const [mentionOpen, setMentionOpen] = useState(false);
  const [mentionQuery, setMentionQuery] = useState("");
  const suggestions = useMentionSuggestions({
    familySlug,
    storyId,
    query: mentionQuery,
    open: mentionOpen,
    mentionOptions,
  });
  const valueKey = JSON.stringify(value);

  useLayoutEffect(() => {
    const editor = editorRef.current;
    if (editor === null || lastEmitted.current === valueKey) return;
    editor.innerHTML = richTextDocumentToEditorHtml(value);
  }, [value, valueKey]);

  useEffect(() => {
    if (mentionOpen) mentionInputRef.current?.focus();
  }, [mentionOpen]);

  useEffect(() => {
    if (!mentionOpen) return;
    const closeOnOutsidePointer = (event: PointerEvent) => {
      if (
        event.target instanceof Node &&
        !mentionControlRef.current?.contains(event.target)
      ) {
        setMentionOpen(false);
        setMentionQuery("");
      }
    };
    document.addEventListener("pointerdown", closeOnOutsidePointer);
    return () => {
      document.removeEventListener("pointerdown", closeOnOutsidePointer);
    };
  }, [mentionOpen]);

  function updateToolbarState() {
    const editor = editorRef.current;
    const selection = window.getSelection();
    if (
      editor === null ||
      selection === null ||
      selection.rangeCount === 0 ||
      !editor.contains(selection.anchorNode)
    )
      return;
    savedRange.current = selection.getRangeAt(0).cloneRange();
    const range = selection.getRangeAt(0);
    let element: HTMLElement | null =
      range.startContainer instanceof HTMLElement
        ? range.startContainer
        : range.startContainer.parentElement;
    let nextBold = false;
    let nextItalic = false;
    let nextStyle: typeof style = "paragraph";
    while (element !== null && element !== editor) {
      if (element.tagName === "B" || element.tagName === "STRONG")
        nextBold = true;
      if (element.tagName === "I" || element.tagName === "EM")
        nextItalic = true;
      if (element.tagName === "H2") nextStyle = "heading_2";
      if (element.tagName === "H3") nextStyle = "heading_3";
      element = element.parentElement;
    }
    setBold(nextBold);
    setItalic(nextItalic);
    setStyle(nextStyle);
  }

  useEffect(() => {
    document.addEventListener("selectionchange", updateToolbarState);
    return () => {
      document.removeEventListener("selectionchange", updateToolbarState);
    };
  });

  function emitDocument() {
    const editor = editorRef.current;
    if (editor === null) return;
    const next = editorElementToRichTextDocument(editor);
    lastEmitted.current = JSON.stringify(next);
    onChange(next);
    updateToolbarState();
  }

  function restoreSelection() {
    const editor = editorRef.current;
    if (editor === null) return false;
    editor.focus();
    const selection = window.getSelection();
    if (selection === null) return false;
    selection.removeAllRanges();
    if (savedRange.current !== null) selection.addRange(savedRange.current);
    else {
      const range = document.createRange();
      range.selectNodeContents(editor);
      range.collapse(false);
      selection.addRange(range);
    }
    return true;
  }

  function setSelection(range: Range) {
    const selection = window.getSelection();
    if (selection === null) return;
    selection.removeAllRanges();
    selection.addRange(range);
    savedRange.current = range.cloneRange();
  }

  function toggleInlineMark(tagName: "strong" | "em") {
    if (!restoreSelection()) return;
    const selection = window.getSelection();
    if (selection === null || selection.rangeCount === 0) return;
    const range = selection.getRangeAt(0);
    const mark: RichTextMark = tagName === "strong" ? "bold" : "italic";
    const markSelector = tagName === "strong" ? "strong, b" : "em, i";
    const origin =
      range.startContainer instanceof HTMLElement
        ? range.startContainer
        : range.startContainer.parentElement;
    const activeMark = origin?.closest(markSelector);

    const marker = document.createElement(tagName);
    if (range.collapsed) {
      if (
        activeMark instanceof HTMLElement &&
        editorRef.current?.contains(activeMark)
      ) {
        const tailRange = document.createRange();
        tailRange.setStart(range.startContainer, range.startOffset);
        tailRange.setEnd(activeMark, activeMark.childNodes.length);
        const tail = tailRange.extractContents();
        const unmarkedCaret = document.createTextNode("\u200b");
        activeMark.after(unmarkedCaret);
        if (tail.textContent !== "" && tail.textContent !== "\u200b") {
          const trailingMark = activeMark.cloneNode(false) as HTMLElement;
          trailingMark.append(tail);
          unmarkedCaret.after(trailingMark);
        }
        if (
          activeMark.textContent === "" ||
          activeMark.textContent === "\u200b"
        )
          activeMark.remove();
        const caret = document.createRange();
        caret.setStart(unmarkedCaret, 1);
        caret.collapse(true);
        setSelection(caret);
        emitDocument();
        return;
      }
      const placeholder = document.createTextNode("\u200b");
      marker.append(placeholder);
      range.insertNode(marker);
      const caret = document.createRange();
      caret.setStart(placeholder, 0);
      caret.collapse(true);
      setSelection(caret);
      emitDocument();
      return;
    }

    const startBlock = origin?.closest("p, h2, h3");
    const endOrigin =
      range.endContainer instanceof HTMLElement
        ? range.endContainer
        : range.endContainer.parentElement;
    const endBlock = endOrigin?.closest("p, h2, h3");
    if (
      !(startBlock instanceof HTMLElement) ||
      startBlock !== endBlock ||
      !editorRef.current?.contains(startBlock)
    )
      return;

    const beforeStart = document.createRange();
    beforeStart.selectNodeContents(startBlock);
    beforeStart.setEnd(range.startContainer, range.startOffset);
    const beforeEnd = document.createRange();
    beforeEnd.selectNodeContents(startBlock);
    beforeEnd.setEnd(range.endContainer, range.endOffset);
    const startOffset = beforeStart.toString().length;
    const endOffset = beforeEnd.toString().length;
    const sourceBlock = blockFromElement(startBlock);
    if (sourceBlock.type === "horizontal_rule") return;
    let cursor = 0;
    const content: RichTextInlineNode[] = [];
    sourceBlock.content.forEach((node) => {
      const length =
        node.type === "mention" ? node.label.length + 1 : node.text.length;
      const nodeStart = cursor;
      const nodeEnd = cursor + length;
      cursor = nodeEnd;
      if (
        node.type === "mention" ||
        nodeEnd <= startOffset ||
        nodeStart >= endOffset
      ) {
        content.push(node);
        return;
      }
      const selectedStart = Math.max(startOffset, nodeStart) - nodeStart;
      const selectedEnd = Math.min(endOffset, nodeEnd) - nodeStart;
      const before = node.text.slice(0, selectedStart);
      const selectedText = node.text.slice(selectedStart, selectedEnd);
      const after = node.text.slice(selectedEnd);
      if (before !== "") content.push({ ...node, text: before });
      const nextMarks = (node.marks ?? []).filter((item) => item !== mark);
      if (!(tagName === "strong" ? bold : italic)) nextMarks.push(mark);
      content.push({
        type: "text",
        text: selectedText,
        ...(nextMarks.length === 0 ? {} : { marks: nextMarks }),
      });
      if (after !== "") content.push({ ...node, text: after });
    });

    const replacementHost = document.createElement("div");
    replacementHost.innerHTML = richTextDocumentToEditorHtml({
      schema_version: 1,
      blocks: [{ ...sourceBlock, content }],
    });
    const replacement = replacementHost.firstElementChild;
    if (!(replacement instanceof HTMLElement)) return;
    startBlock.replaceWith(replacement);

    const textNodes: Text[] = [];
    const walker = document.createTreeWalker(replacement, NodeFilter.SHOW_TEXT);
    let current = walker.nextNode();
    while (current !== null) {
      textNodes.push(current as Text);
      current = walker.nextNode();
    }
    const boundaryAt = (offset: number, edge: "start" | "end") => {
      let remaining = offset;
      for (const [index, node] of textNodes.entries()) {
        if (remaining < node.data.length)
          return { node: node as Node, offset: remaining };
        if (remaining === node.data.length) {
          const next = textNodes.at(index + 1);
          if (edge === "start" && next !== undefined)
            return { node: next as Node, offset: 0 };
          return { node: node as Node, offset: remaining };
        }
        remaining -= node.data.length;
      }
      const fallback = textNodes.at(-1);
      return fallback === undefined
        ? { node: replacement as Node, offset: 0 }
        : { node: fallback as Node, offset: fallback.data.length };
    };
    const startBoundary = boundaryAt(startOffset, "start");
    const endBoundary = boundaryAt(endOffset, "end");
    const marked = document.createRange();
    marked.setStart(startBoundary.node, startBoundary.offset);
    marked.setEnd(endBoundary.node, endBoundary.offset);
    setSelection(marked);
    emitDocument();
  }

  function applyBlockStyle(tagName: "p" | "h2" | "h3") {
    if (!restoreSelection()) return;
    const editor = editorRef.current;
    const range = savedRange.current;
    if (editor === null || range === null) return;
    const origin =
      range.startContainer instanceof HTMLElement
        ? range.startContainer
        : range.startContainer.parentElement;
    const block = origin?.closest("p, h2, h3");
    if (!(block instanceof HTMLElement) || !editor.contains(block)) return;
    const replacement = document.createElement(tagName);
    while (block.firstChild !== null) replacement.append(block.firstChild);
    block.replaceWith(replacement);
    const nextRange = document.createRange();
    nextRange.selectNodeContents(replacement);
    nextRange.collapse(false);
    setSelection(nextRange);
    emitDocument();
  }

  function insertDivider() {
    if (!restoreSelection()) return;
    const editor = editorRef.current;
    const range = savedRange.current;
    if (editor === null || range === null) return;
    const origin =
      range.startContainer instanceof HTMLElement
        ? range.startContainer
        : range.startContainer.parentElement;
    const block = origin?.closest("p, h2, h3");
    const divider = document.createElement("hr");
    divider.dataset.storyDivider = "true";
    const paragraph = document.createElement("p");
    paragraph.append(document.createElement("br"));
    if (block instanceof HTMLElement && editor.contains(block))
      block.after(divider, paragraph);
    else editor.append(divider, paragraph);
    const nextRange = document.createRange();
    nextRange.selectNodeContents(paragraph);
    nextRange.collapse(true);
    setSelection(nextRange);
    emitDocument();
  }

  function insertPlainText(text: string) {
    if (!restoreSelection()) return;
    const selection = window.getSelection();
    if (selection === null || selection.rangeCount === 0) return;
    const range = selection.getRangeAt(0);
    range.deleteContents();
    const node = document.createTextNode(text);
    range.insertNode(node);
    const nextRange = document.createRange();
    nextRange.setStartAfter(node);
    nextRange.collapse(true);
    setSelection(nextRange);
    emitDocument();
  }

  function insertMention(person: MentionOption) {
    if (!restoreSelection()) return;
    const selection = window.getSelection();
    if (selection === null || selection.rangeCount === 0) return;
    const range = selection.getRangeAt(0);
    range.deleteContents();
    const mention = document.createElement("span");
    mention.className = "rich-text-editor__mention";
    mention.contentEditable = "false";
    mention.dataset.personId = person.id;
    mention.dataset.personLabel = person.label;
    mention.textContent = `@${person.label}`;
    const previousCharacter =
      range.startContainer.nodeType === Node.TEXT_NODE && range.startOffset > 0
        ? (range.startContainer.textContent ?? "").at(range.startOffset - 1)
        : undefined;
    const leadingSpacer =
      previousCharacter === undefined || /\s/.test(previousCharacter)
        ? null
        : document.createTextNode(" ");
    const spacer = document.createTextNode(" ");
    const fragment = document.createDocumentFragment();
    if (leadingSpacer !== null) fragment.append(leadingSpacer);
    fragment.append(mention, spacer);
    range.insertNode(fragment);
    range.setStartAfter(spacer);
    range.collapse(true);
    selection.removeAllRanges();
    selection.addRange(range);
    savedRange.current = range.cloneRange();
    setMentionOpen(false);
    setMentionQuery("");
    emitDocument();
  }

  function handleInput() {
    emitDocument();
    const selection = window.getSelection();
    if (selection === null || selection.rangeCount === 0) return;
    const range = selection.getRangeAt(0);
    if (range.startContainer.nodeType !== Node.TEXT_NODE) return;
    const beforeCaret = (range.startContainer.textContent ?? "").slice(
      0,
      range.startOffset,
    );
    const match = /(?:^|\s)@([^\s@]+)$/.exec(beforeCaret);
    if (match === null) return;
    const mentionRange = range.cloneRange();
    mentionRange.setStart(
      range.startContainer,
      range.startOffset - match[1].length - 1,
    );
    savedRange.current = mentionRange;
    setMentionQuery(match[1]);
    setMentionOpen(true);
  }

  function handleKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    if (!(event.metaKey || event.ctrlKey)) return;
    if (event.key.toLowerCase() === "b") {
      event.preventDefault();
      toggleInlineMark("strong");
    }
    if (event.key.toLowerCase() === "i") {
      event.preventDefault();
      toggleInlineMark("em");
    }
  }

  function handlePaste(event: ClipboardEvent<HTMLDivElement>) {
    event.preventDefault();
    insertPlainText(event.clipboardData.getData("text/plain"));
  }

  return (
    <div
      className={`rich-text-editor rich-text-editor--continuous ${className}`.trim()}
    >
      <div
        className="rich-text-editor__toolbar"
        role="toolbar"
        aria-label={`${label} formatting`}
      >
        <label className="rich-text-editor__style-control">
          <span>Text style</span>
          <select
            aria-label="Text style"
            value={style}
            onChange={(event) => {
              const next = event.target.value as typeof style;
              setStyle(next);
              applyBlockStyle(
                next === "heading_2" ? "h2" : next === "heading_3" ? "h3" : "p",
              );
            }}
          >
            <option value="paragraph">Paragraph</option>
            <option value="heading_2">Heading 2</option>
            <option value="heading_3">Heading 3</option>
          </select>
        </label>
        <span className="rich-text-editor__toolbar-separator" />
        <button
          type="button"
          className={bold ? "is-active" : undefined}
          aria-label="Bold"
          aria-pressed={bold}
          title="Bold (Ctrl/Cmd+B)"
          onClick={() => {
            toggleInlineMark("strong");
          }}
        >
          <b>B</b>
        </button>
        <button
          type="button"
          className={italic ? "is-active" : undefined}
          aria-label="Italic"
          aria-pressed={italic}
          title="Italic (Ctrl/Cmd+I)"
          onClick={() => {
            toggleInlineMark("em");
          }}
        >
          <i>I</i>
        </button>
        <button
          type="button"
          aria-label="Insert divider"
          title="Insert divider"
          onClick={() => {
            insertDivider();
          }}
        >
          <span aria-hidden="true">―</span>
          <span className="rich-text-editor__button-label">Divider</span>
        </button>
        <div
          ref={mentionControlRef}
          className="rich-text-editor__mention-control"
        >
          <button
            type="button"
            aria-label="Mention a person"
            aria-expanded={mentionOpen}
            onClick={() => {
              setMentionOpen((current) => !current);
              setMentionQuery("");
            }}
          >
            <span aria-hidden="true">@</span>
            <span className="rich-text-editor__button-label">Person</span>
          </button>
          {mentionOpen && (
            <div
              className="rich-text-editor__mention-picker"
              role="dialog"
              aria-label="Mention a person"
            >
              <label>
                <span>Find a person</span>
                <input
                  ref={mentionInputRef}
                  type="search"
                  value={mentionQuery}
                  placeholder="Search People…"
                  onChange={(event) => {
                    setMentionQuery(event.target.value);
                  }}
                  onKeyDown={(event) => {
                    if (event.key === "Escape") setMentionOpen(false);
                  }}
                />
              </label>
              <div role="listbox" aria-label="People">
                {suggestions.map((person) => (
                  <button
                    key={person.id}
                    type="button"
                    role="option"
                    aria-selected="false"
                    onClick={() => {
                      insertMention(person);
                    }}
                  >
                    <PersonAvatar
                      name={person.label}
                      className="rich-text-editor__mention-avatar"
                    />
                    <span>{person.label}</span>
                  </button>
                ))}
                {mentionQuery !== "" && suggestions.length === 0 && (
                  <p>No matching People.</p>
                )}
              </div>
            </div>
          )}
        </div>
      </div>
      <div
        ref={editorRef}
        className="rich-text-editor__surface"
        contentEditable
        suppressContentEditableWarning
        tabIndex={0}
        role="textbox"
        aria-label={label}
        aria-multiline="true"
        data-placeholder="Start writing your Story…"
        onInput={handleInput}
        onKeyDown={handleKeyDown}
        onPaste={handlePaste}
      />
    </div>
  );
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

function CommentEditor({
  familySlug,
  storyId,
  value,
  onChange,
  label,
  className = "",
}: RichTextEditorProps) {
  const [query, setQuery] = useState<{
    nodeIndex: number;
    start: number;
    end: number;
    prefix: string;
  } | null>(null);
  const suggestions = useMentionSuggestions({
    familySlug,
    storyId,
    query: query?.prefix ?? "",
    open: query !== null,
  });
  const block =
    value.blocks.find(
      (item): item is RichTextTextBlock => item.type !== "horizontal_rule",
    ) ?? textBlock();

  function changeText(
    event: ChangeEvent<HTMLTextAreaElement>,
    nodeIndex: number,
  ) {
    const node = block.content[nodeIndex];
    if (node.type !== "text") return;
    const text = event.target.value;
    onChange({
      schema_version: 1,
      blocks: [replaceInline(block, nodeIndex, [{ ...node, text }])],
    });
    const beforeCaret = text.slice(0, event.target.selectionStart);
    const match = /(?:^|\s)@([^\s@]*)$/.exec(beforeCaret);
    setQuery(
      match === null || match[1] === ""
        ? null
        : {
            nodeIndex,
            start: beforeCaret.length - match[1].length - 1,
            end: beforeCaret.length,
            prefix: match[1],
          },
    );
  }

  function selectMention(person: MentionOption) {
    if (query === null) return;
    const node = block.content[query.nodeIndex];
    if (node.type !== "text") return;
    onChange({
      schema_version: 1,
      blocks: [
        replaceInline(block, query.nodeIndex, [
          { ...node, text: node.text.slice(0, query.start) },
          { type: "mention", person_id: person.id, label: person.label },
          textNode(node.text.slice(query.end)),
        ]),
      ],
    });
    setQuery(null);
  }

  return (
    <div
      className={`rich-text-editor rich-text-editor--comment ${className}`.trim()}
      aria-label={label}
    >
      <div className="rich-text-editor__document">
        <div className="rich-text-editor__block">
          {block.content.map((node, nodeIndex) =>
            node.type === "mention" ? (
              <span className="rich-text-editor__mention" key={nodeIndex}>
                @{node.label}
                <button
                  type="button"
                  aria-label={`Remove mention ${node.label}`}
                  onClick={() => {
                    onChange({
                      schema_version: 1,
                      blocks: [
                        {
                          ...block,
                          content: block.content.filter(
                            (_, index) => index !== nodeIndex,
                          ),
                        },
                      ],
                    });
                  }}
                >
                  Remove
                </button>
              </span>
            ) : (
              <textarea
                className="rich-text-editor__text"
                key={nodeIndex}
                aria-label={`Block 1 text ${String(nodeIndex + 1)}`}
                placeholder={
                  nodeIndex === 0 ? "Add to the conversation…" : undefined
                }
                rows={1}
                value={node.text}
                onChange={(event) => {
                  changeText(event, nodeIndex);
                }}
              />
            ),
          )}
        </div>
      </div>
      {query !== null && (
        <div
          className="rich-text-editor__suggestions"
          role="listbox"
          aria-label="Mention a person"
        >
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

export function RichTextEditor(props: RichTextEditorProps) {
  return props.vocabulary === "comment" ? (
    <CommentEditor {...props} />
  ) : (
    <ContinuousStoryEditor {...props} />
  );
}
