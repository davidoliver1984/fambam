import type { ActionNoticeMessage } from "@/components/ui";

const invitationWelcomeKey = "fambam.invitation-welcome";

export function storeInvitationWelcomeNotice(): void {
  window.sessionStorage.setItem(invitationWelcomeKey, "1");
}

export function takeInvitationWelcomeNotice(): ActionNoticeMessage | null {
  if (window.sessionStorage.getItem(invitationWelcomeKey) === null) return null;

  window.sessionStorage.removeItem(invitationWelcomeKey);

  return {
    title: "Welcome to Fambam",
    description: "This is your family newsfeed.",
  };
}
