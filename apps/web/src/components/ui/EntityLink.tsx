import type { ReactNode } from "react";
import { Link } from "react-router";

export type EntityKind =
  "album" | "collection" | "event" | "person" | "photo" | "story";

type EntityLinkProps = {
  children: ReactNode;
  entity: EntityKind;
  to: string;
  className?: string;
};

export function EntityLink({
  children,
  entity,
  to,
  className = "",
}: EntityLinkProps) {
  return (
    <Link
      className={`ui-entity-link ui-entity-link--${entity} ${className}`.trim()}
      data-entity-kind={entity}
      to={to}
    >
      {children}
    </Link>
  );
}
