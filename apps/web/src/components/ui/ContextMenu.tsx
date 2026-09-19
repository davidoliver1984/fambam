import { useEffect, useRef, useState, type ReactNode } from "react";

type ContextMenuProps = {
  label: string;
  children: ReactNode;
};

export function ContextMenu({ label, children }: ContextMenuProps) {
  const [open, setOpen] = useState(false);
  const wrapper = useRef<HTMLDivElement>(null);
  const trigger = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!open) return;
    const dismiss = (event: MouseEvent) => {
      if (!wrapper.current?.contains(event.target as Node)) setOpen(false);
    };
    const escape = (event: KeyboardEvent) => {
      if (event.key !== "Escape") return;
      setOpen(false);
      trigger.current?.focus();
    };
    document.addEventListener("mousedown", dismiss);
    document.addEventListener("keydown", escape);
    return () => {
      document.removeEventListener("mousedown", dismiss);
      document.removeEventListener("keydown", escape);
    };
  }, [open]);

  return (
    <div className="ui-context-menu" ref={wrapper}>
      <button
        ref={trigger}
        className="ui-button ui-button--ghost ui-button--icon"
        type="button"
        aria-label={label}
        aria-expanded={open}
        aria-haspopup="menu"
        onClick={() => {
          setOpen((current) => !current);
        }}
      >
        <span aria-hidden="true">•••</span>
      </button>
      {open && (
        <div className="ui-context-menu__panel" role="menu">
          {children}
        </div>
      )}
    </div>
  );
}
