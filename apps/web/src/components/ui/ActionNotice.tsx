export type ActionNoticeMessage = {
  title: string;
  description?: string;
};

type ActionNoticeProps = ActionNoticeMessage & {
  onDismiss: () => void;
};

function NoticeGlyph() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
    >
      <circle cx="12" cy="12" r="8" />
      <path d="m9 12 2 2 4-4" />
    </svg>
  );
}

function CloseGlyph() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
    >
      <path d="m6 6 12 12M18 6 6 18" />
    </svg>
  );
}

export function ActionNotice({
  title,
  description,
  onDismiss,
}: ActionNoticeProps) {
  return (
    <div className="ui-action-notice" role="status">
      <span className="ui-action-notice__icon">
        <NoticeGlyph />
      </span>
      <span className="ui-action-notice__copy">
        <b>{title}</b>
        {description && <span>{description}</span>}
      </span>
      <button
        type="button"
        aria-label="Dismiss notification"
        onClick={onDismiss}
      >
        <CloseGlyph />
      </button>
    </div>
  );
}
