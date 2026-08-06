import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import {
  assignAccountLink,
  getAccountLinkClaims,
  getFamilyMemberships,
  proposeAccountLink,
  removeAccountLink,
  resolveAccountLinkClaim,
} from "./accountLinkApi";

const apiBaseUrl = "http://localhost:8082";
const personId = "01K30000000000000000000000";
const claim = {
  id: "01K40000000000000000000000",
  person_id: personId,
  account: { id: 2, name: "Ada Oliver" },
  status: "pending",
  resolved_at: null,
  created_at: "2026-08-06T12:00:00Z",
} as const;

describe("accountLinkApi", () => {
  it("uses typed family-scoped claim, membership and link endpoints", async () => {
    const requests: string[] = [];
    const base = `${apiBaseUrl}/api/families/oliver-family/people/${personId}`;
    server.use(
      http.get(`${base}/account-link-claims`, () => {
        requests.push("claims");
        return HttpResponse.json({ data: [claim] });
      }),
      http.post(`${base}/account-link-claims`, () => {
        requests.push("propose");
        return HttpResponse.json({ data: claim }, { status: 201 });
      }),
      http.post(`${base}/account-link-claims/${claim.id}/approve`, () => {
        requests.push("approve");
        return HttpResponse.json({
          data: {
            id: "01K50000000000000000000000",
            person_id: personId,
            account: { id: 2, name: "Ada Oliver", is_current_user: false },
            created_at: "2026-08-06T12:05:00Z",
          },
        });
      }),
      http.get(`${apiBaseUrl}/api/families/oliver-family/memberships`, () => {
        requests.push("memberships");
        return HttpResponse.json({
          data: [
            {
              id: "01K60000000000000000000000",
              user: { id: 2, name: "Ada Oliver", email: "ada@example.test" },
              role: "member",
              state: "active",
              removed_at: null,
            },
          ],
        });
      }),
      http.put(`${base}/account-link`, async ({ request }) => {
        requests.push(`assign:${JSON.stringify(await request.json())}`);
        return HttpResponse.json({
          data: {
            id: "01K50000000000000000000000",
            person_id: personId,
            account: { id: 2, name: "Ada Oliver", is_current_user: false },
          },
        });
      }),
      http.delete(`${base}/account-link`, () => {
        requests.push("remove");
        return HttpResponse.json({ data: null });
      }),
    );

    await expect(
      getAccountLinkClaims("oliver-family", personId),
    ).resolves.toEqual([claim]);
    await expect(
      proposeAccountLink("oliver-family", personId),
    ).resolves.toEqual(claim);
    await expect(
      resolveAccountLinkClaim("oliver-family", personId, claim.id, "approve"),
    ).resolves.toMatchObject({ account: { id: 2 } });
    await expect(getFamilyMemberships("oliver-family")).resolves.toHaveLength(
      1,
    );
    await expect(
      assignAccountLink(
        "oliver-family",
        personId,
        "01K60000000000000000000000",
      ),
    ).resolves.toMatchObject({ account: { name: "Ada Oliver" } });
    await expect(
      removeAccountLink("oliver-family", personId),
    ).resolves.toBeNull();
    expect(requests).toEqual([
      "claims",
      "propose",
      "approve",
      "memberships",
      'assign:{"membership_id":"01K60000000000000000000000"}',
      "remove",
    ]);
  });
});
