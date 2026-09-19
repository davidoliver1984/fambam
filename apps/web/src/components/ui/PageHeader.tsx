import type { ReactNode } from "react";

type PageHeaderProps = {
  eyebrow?: string;
  title: string;
  description?: string;
  actions?: ReactNode;
  id?: string;
};

export function PageHeader({
  eyebrow,
  title,
  description,
  actions,
  id = "page-title",
}: PageHeaderProps) {
  return (
    <header className="ui-page-header">
      <div className="ui-page-header__copy">
        {eyebrow && <p className="ui-eyebrow">{eyebrow}</p>}
        <h1 className="ui-page-title" id={id}>
          {title}
        </h1>
        {description && <p className="ui-page-description">{description}</p>}
      </div>
      {actions && <div className="ui-page-header__actions">{actions}</div>}
    </header>
  );
}

type SectionHeaderProps = {
  eyebrow?: string;
  title: string;
  description?: string;
  actions?: ReactNode;
  id?: string;
};

export function SectionHeader({
  eyebrow,
  title,
  description,
  actions,
  id,
}: SectionHeaderProps) {
  return (
    <header className="ui-page-header">
      <div className="ui-page-header__copy">
        {eyebrow && <p className="ui-eyebrow">{eyebrow}</p>}
        <h2 className="ui-section-title" id={id}>
          {title}
        </h2>
        {description && <p className="ui-page-description">{description}</p>}
      </div>
      {actions && <div className="ui-page-header__actions">{actions}</div>}
    </header>
  );
}
