import type { ReactNode } from "react";

type ArchiveToolbarProps = {
  search: ReactNode;
  children?: ReactNode;
  label?: string;
};

export function ArchiveToolbar({
  search,
  children,
  label = "Archive controls",
}: ArchiveToolbarProps) {
  return (
    <div className="ui-archive-toolbar" role="group" aria-label={label}>
      <div className="ui-archive-toolbar__search">{search}</div>
      {children}
    </div>
  );
}

type FieldProps = {
  id: string;
  label: string;
  children: ReactNode;
};

export function ToolbarField({ id, label, children }: FieldProps) {
  return (
    <div>
      <label className="ui-field-label" htmlFor={id}>
        {label}
      </label>
      {children}
    </div>
  );
}
