import type {
  CSSProperties,
  FocusEventHandler,
  MouseEventHandler,
  ReactNode,
} from "react";
import { Link } from "react-router";

export type EntityKind =
  "album" | "collection" | "event" | "person" | "photo" | "story";

type EntityLinkProps = {
  children: ReactNode;
  entity: EntityKind;
  to: string;
  className?: string;
  style?: CSSProperties;
  "aria-label"?: string;
  tabIndex?: number;
  onMouseEnter?: MouseEventHandler<HTMLAnchorElement>;
  onMouseLeave?: MouseEventHandler<HTMLAnchorElement>;
  onFocus?: FocusEventHandler<HTMLAnchorElement>;
  onBlur?: FocusEventHandler<HTMLAnchorElement>;
};

export function EntityLink({
  children,
  entity,
  to,
  className = "",
  style,
  "aria-label": ariaLabel,
  tabIndex,
  onMouseEnter,
  onMouseLeave,
  onFocus,
  onBlur,
}: EntityLinkProps) {
  return (
    <Link
      className={`ui-entity-link ui-entity-link--${entity} ${className}`.trim()}
      data-entity-kind={entity}
      aria-label={ariaLabel}
      tabIndex={tabIndex}
      style={style}
      to={to}
      onMouseEnter={onMouseEnter}
      onMouseLeave={onMouseLeave}
      onFocus={onFocus}
      onBlur={onBlur}
    >
      {children}
    </Link>
  );
}
