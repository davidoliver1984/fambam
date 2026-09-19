import type { ReactNode } from "react";

type StatusTone = "loading" | "error" | "success" | "empty";

type StatusPanelProps = {
  tone: StatusTone;
  title: string;
  children?: ReactNode;
  actions?: ReactNode;
};

export function StatusPanel({
  tone,
  title,
  children,
  actions,
}: StatusPanelProps) {
  const role = tone === "error" ? "alert" : "status";
  return (
    <div className={`ui-status ui-status--${tone}`} role={role}>
      <strong>{title}</strong>
      {children}
      {actions && <div className="ui-inline-actions">{actions}</div>}
    </div>
  );
}
