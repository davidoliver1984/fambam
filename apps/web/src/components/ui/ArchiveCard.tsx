import type { ReactNode } from "react";

import { EntityLink, type EntityKind } from "./EntityLink";

type ArchiveCardProps = {
  entity: EntityKind;
  title: string;
  to: string;
  media?: ReactNode;
  eyebrow?: ReactNode;
  meta?: ReactNode;
  actions?: ReactNode;
  children?: ReactNode;
  className?: string;
};

export function ArchiveCard({
  entity,
  title,
  to,
  media,
  eyebrow,
  meta,
  actions,
  children,
  className = "",
}: ArchiveCardProps) {
  return (
    <article className={`ui-archive-card ${className}`.trim()}>
      {media && (
        <EntityLink className="ui-archive-card__media" entity={entity} to={to}>
          {media}
        </EntityLink>
      )}
      <div className="ui-archive-card__body">
        <div className="ui-archive-card__heading">
          <div>
            {eyebrow && <p className="ui-eyebrow">{eyebrow}</p>}
            <h3>
              <EntityLink entity={entity} to={to}>
                {title}
              </EntityLink>
            </h3>
          </div>
          {actions}
        </div>
        {children}
        {meta && <div className="ui-archive-card__meta">{meta}</div>}
      </div>
    </article>
  );
}
