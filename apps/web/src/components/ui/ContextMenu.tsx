import {
  useEffect,
  useLayoutEffect,
  useRef,
  useState,
  type CSSProperties,
  type ReactNode,
} from "react";
import { createPortal } from "react-dom";

type ContextMenuProps = {
  label: string;
  trigger?: ReactNode;
  children: ReactNode;
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
  placement?: "auto" | "bottom-start" | "bottom-end";
};

export function ContextMenu({
  label,
  trigger: triggerContent,
  children,
  open: controlledOpen,
  onOpenChange,
  placement = "auto",
}: ContextMenuProps) {
  const [internalOpen, setInternalOpen] = useState(false);
  const open = controlledOpen ?? internalOpen;
  const wrapper = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const [panelStyle, setPanelStyle] = useState<CSSProperties>({
    visibility: "hidden",
  });

  const close = () => {
    onOpenChange?.(false);
    setInternalOpen(false);
  };

  useEffect(() => {
    if (!open) return;
    const dismiss = (event: MouseEvent) => {
      const target = event.target as Node;
      if (
        !wrapper.current?.contains(target) &&
        !panelRef.current?.contains(target)
      )
        close();
    };
    const escape = (event: KeyboardEvent) => {
      if (event.key !== "Escape") return;
      close();
      triggerRef.current?.focus();
    };
    document.addEventListener("mousedown", dismiss);
    document.addEventListener("keydown", escape);
    return () => {
      document.removeEventListener("mousedown", dismiss);
      document.removeEventListener("keydown", escape);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useLayoutEffect(() => {
    if (!open) return;
    const positionPanel = () => {
      const trigger = triggerRef.current;
      const panel = panelRef.current;
      if (trigger === null || panel === null) return;
      const triggerRect = trigger.getBoundingClientRect();
      const panelRect = panel.getBoundingClientRect();
      const gutter = 8;
      const gap = 8;
      const below = triggerRect.bottom + gap;
      const above = triggerRect.top - panelRect.height - gap;
      const top =
        placement === "auto"
          ? below + panelRect.height <= window.innerHeight - gutter ||
            above < gutter
            ? below
            : above
          : below;
      const preferredLeft =
        placement === "bottom-start"
          ? triggerRect.left
          : triggerRect.right - panelRect.width;
      const left = Math.min(
        Math.max(gutter, preferredLeft),
        window.innerWidth - panelRect.width - gutter,
      );
      setPanelStyle({
        top: Math.max(gutter, top),
        left,
        visibility: "visible",
      });
    };
    positionPanel();
    const resizeObserver =
      typeof ResizeObserver === "undefined"
        ? undefined
        : new ResizeObserver(positionPanel);
    if (resizeObserver !== undefined && panelRef.current !== null)
      resizeObserver.observe(panelRef.current);
    window.addEventListener("resize", positionPanel);
    window.addEventListener("scroll", positionPanel, true);
    return () => {
      resizeObserver?.disconnect();
      window.removeEventListener("resize", positionPanel);
      window.removeEventListener("scroll", positionPanel, true);
    };
  }, [open, placement]);

  useEffect(() => {
    if (!open) return;
    const items = panelRef.current?.querySelectorAll<HTMLElement>(
      ":scope > a[href], :scope > button:not(:disabled)",
    );
    items?.forEach((item, index) => {
      item.setAttribute("role", "menuitem");
      item.tabIndex = index === 0 ? 0 : -1;
    });
    items?.[0]?.focus();
  }, [open]);

  return (
    <div className="ui-context-menu" ref={wrapper}>
      <button
        ref={triggerRef}
        className={`ui-button ui-button--ghost${triggerContent === undefined ? " ui-button--icon" : ""}`}
        type="button"
        aria-label={triggerContent === undefined ? label : undefined}
        aria-expanded={open}
        aria-haspopup="menu"
        onClick={() => {
          const next = !open;
          onOpenChange?.(next);
          setInternalOpen(next);
        }}
      >
        {triggerContent ?? (
          <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            fill="currentColor"
            className="h-4 w-4"
          >
            <circle cx="5" cy="12" r="1.8" />
            <circle cx="12" cy="12" r="1.8" />
            <circle cx="19" cy="12" r="1.8" />
          </svg>
        )}
      </button>
      {open &&
        createPortal(
          <div
            ref={panelRef}
            className="ui-context-menu__panel"
            role="menu"
            tabIndex={-1}
            style={panelStyle}
            onClick={(event) => {
              const action = (event.target as HTMLElement).closest("a, button");
              if (action !== null && action.closest("form") === null) close();
            }}
            onKeyDown={(event) => {
              const items = Array.from(
                panelRef.current?.querySelectorAll<HTMLElement>(
                  ":scope > a[href], :scope > button:not(:disabled)",
                ) ?? [],
              );
              if (items.length === 0) return;
              const current = items.indexOf(
                document.activeElement as HTMLElement,
              );
              let next = current;
              if (event.key === "ArrowDown")
                next = (current + 1) % items.length;
              else if (event.key === "ArrowUp")
                next = (current - 1 + items.length) % items.length;
              else if (event.key === "Home") next = 0;
              else if (event.key === "End") next = items.length - 1;
              else return;
              event.preventDefault();
              items.forEach((item, index) => {
                item.tabIndex = index === next ? 0 : -1;
              });
              items[next]?.focus();
            }}
          >
            {children}
          </div>,
          document.body,
        )}
    </div>
  );
}
