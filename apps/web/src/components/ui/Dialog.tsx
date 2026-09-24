import { useEffect, useId, useRef, type ReactNode } from "react";

type DialogProps = {
  open: boolean;
  title: string;
  eyebrow?: ReactNode;
  description?: string;
  className?: string;
  children: ReactNode;
  pending?: boolean;
  onClose: () => void;
};

export function Dialog({
  open,
  title,
  eyebrow,
  description,
  className = "",
  children,
  pending = false,
  onClose,
}: DialogProps) {
  const dialog = useRef<HTMLElement>(null);
  const titleId = useId();
  const descriptionId = useId();

  useEffect(() => {
    if (!open) return;
    const previous = document.activeElement as HTMLElement | null;
    const focusableSelector =
      'button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])';
    const initialFocus =
      dialog.current?.querySelector<HTMLElement>("[data-autofocus]") ??
      dialog.current?.querySelector<HTMLElement>(focusableSelector);
    initialFocus?.focus();
    const handleKeyboard = (event: KeyboardEvent) => {
      if (event.key === "Escape" && !pending) {
        onClose();
        return;
      }
      if (event.key !== "Tab") return;
      const focusable =
        dialog.current?.querySelectorAll<HTMLElement>(focusableSelector);
      if (!focusable || focusable.length === 0) return;
      const firstFocusable = focusable[0];
      const lastFocusable = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === firstFocusable) {
        event.preventDefault();
        lastFocusable.focus();
      } else if (!event.shiftKey && document.activeElement === lastFocusable) {
        event.preventDefault();
        firstFocusable.focus();
      }
    };
    document.addEventListener("keydown", handleKeyboard);
    return () => {
      document.removeEventListener("keydown", handleKeyboard);
      previous?.focus();
    };
  }, [onClose, open, pending]);

  if (!open) return null;

  return (
    <div
      className="ui-dialog-backdrop"
      role="presentation"
      tabIndex={-1}
      onMouseDown={(event) => {
        if (event.target === event.currentTarget && !pending) onClose();
      }}
      onKeyDown={() => undefined}
    >
      <section
        ref={dialog}
        tabIndex={-1}
        className={`ui-dialog ${className}`.trim()}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={description === undefined ? undefined : descriptionId}
      >
        <div className="ui-dialog__header">
          <div>
            {eyebrow !== undefined && (
              <p className="ui-eyebrow ui-dialog__eyebrow">{eyebrow}</p>
            )}
            <h2 id={titleId}>{title}</h2>
            {description !== undefined && (
              <p id={descriptionId}>{description}</p>
            )}
          </div>
          <button
            type="button"
            className="ui-dialog__close"
            aria-label="Close"
            disabled={pending}
            onClick={onClose}
          >
            <svg
              aria-hidden="true"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.8"
            >
              <path d="M18 6 6 18M6 6l12 12" />
            </svg>
          </button>
        </div>
        {children}
      </section>
    </div>
  );
}
