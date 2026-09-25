type PersonAvatarProps = {
  name: string;
  initials?: string;
  portraitUrl?: string;
  className?: string;
};

function initialsFor(value: string) {
  return value
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join("");
}

export function PersonAvatar({
  name,
  initials,
  portraitUrl,
  className = "",
}: PersonAvatarProps) {
  return (
    <span
      className={`shell-avatar shell-search-avatar ${className}`.trim()}
      aria-hidden="true"
    >
      {portraitUrl ? (
        <img src={portraitUrl} alt="" />
      ) : (
        initials || initialsFor(name)
      )}
    </span>
  );
}
