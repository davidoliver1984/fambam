import { useEffect, useId, useRef, type ReactNode } from "react";

import { Button } from "./Button";

type ConfirmDialogProps = {
  open: boolean;
  title: string;
  children: ReactNode;
  confirmLabel: string;
  destructive?: boolean;
  pending?: boolean;
  onCancel: () => void;
  onConfirm: () => void;
};

export function ConfirmDialog({
  open,
  title,
  children,
  confirmLabel,
  destructive = false,
  pending = false,
  onCancel,
  onConfirm,
}: ConfirmDialogProps) {
  const cancel = useRef<HTMLButtonElement>(null);
  const dialog = useRef<HTMLElement>(null);
  const titleId = useId();

  useEffect(() => {
    if (!open) return;
    const previous = document.activeElement as HTMLElement | null;
    cancel.current?.focus();
    const handleKeyboard = (event: KeyboardEvent) => {
      if (event.key === "Escape" && !pending) {
        onCancel();
        return;
      }
      if (event.key !== "Tab") return;
      const focusable = dialog.current?.querySelectorAll<HTMLElement>(
        'button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])',
      );
      if (!focusable || focusable.length === 0) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };
    document.addEventListener("keydown", handleKeyboard);
    return () => {
      document.removeEventListener("keydown", handleKeyboard);
      previous?.focus();
    };
  }, [onCancel, open, pending]);

  if (!open) return null;

  return (
    <div className="ui-dialog-backdrop">
      <section
        ref={dialog}
        className="ui-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
      >
        <h2 id={titleId}>{title}</h2>
        <div>{children}</div>
        <div className="ui-inline-actions">
          <Button ref={cancel} disabled={pending} onClick={onCancel}>
            Cancel
          </Button>
          <Button
            variant={destructive ? "danger" : "primary"}
            disabled={pending}
            onClick={onConfirm}
          >
            {confirmLabel}
          </Button>
        </div>
      </section>
    </div>
  );
}
