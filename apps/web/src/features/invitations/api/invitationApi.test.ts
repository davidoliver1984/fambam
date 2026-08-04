import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import {
  getInvitations,
  issueInvitation,
  transitionInvitation,
} from "./invitationApi";

const apiBaseUrl = "http://localhost:8082";
const invitation = {
  id: 7,
  family_space_id: "01K1ZZZZZZZZZZZZZZZZZZZZZZ",
  email: "relative@example.test",
  role: "member",
  status: "pending",
  expires_at: "2026-08-09T12:00:00Z",
  accepted_at: null,
  revoked_at: null,
  acceptable: true,
};

describe("invitationApi tenant routes", () => {
  it("keeps list, issue and transitions inside the URL-derived Family Space", async () => {
    const paths: string[] = [];
    server.use(
      http.get(
        `${apiBaseUrl}/api/families/oliver-family/invitations`,
        ({ request }) => {
          paths.push(new URL(request.url).pathname);
          return HttpResponse.json({ data: [invitation] });
        },
      ),
      http.post(
        `${apiBaseUrl}/api/families/oliver-family/invitations`,
        async ({ request }) => {
          paths.push(new URL(request.url).pathname);
          const input = (await request.json()) as Record<string, unknown>;
          return HttpResponse.json(
            { data: { ...invitation, ...input } },
            { status: 201 },
          );
        },
      ),
      http.post(
        `${apiBaseUrl}/api/families/oliver-family/invitations/7/revoke`,
        ({ request }) => {
          paths.push(new URL(request.url).pathname);
          return HttpResponse.json({
            data: { ...invitation, status: "revoked", acceptable: false },
          });
        },
      ),
    );

    await expect(getInvitations("oliver-family")).resolves.toHaveLength(1);
    await expect(
      issueInvitation("oliver-family", {
        email: "relative@example.test",
        role: "member",
      }),
    ).resolves.toMatchObject({ family_space_id: invitation.family_space_id });
    await expect(
      transitionInvitation("oliver-family", 7, "revoke"),
    ).resolves.toMatchObject({ status: "revoked" });
    expect(paths).toEqual([
      "/api/families/oliver-family/invitations",
      "/api/families/oliver-family/invitations",
      "/api/families/oliver-family/invitations/7/revoke",
    ]);
  });
});
