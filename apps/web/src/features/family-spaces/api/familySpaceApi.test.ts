import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { toAppError } from "@/api/errors";
import { server } from "@/test/msw/server";

import {
  createFamilySpace,
  cancelFamilySpaceDeletion,
  getFamilySpace,
  getFamilySpaces,
  leaveFamilySpace,
  requestFamilySpaceDeletion,
  transferFamilySpaceOwnership,
  updateFamilySpace,
} from "./familySpaceApi";

const apiBaseUrl = "http://localhost:8082";

describe("familySpaceApi", () => {
  it("lists and creates Family Spaces through typed endpoints", async () => {
    server.use(
      http.get(`${apiBaseUrl}/api/family-spaces`, () =>
        HttpResponse.json({
          data: [
            {
              id: "01K1ZZZZZZZZZZZZZZZZZZZZZZ",
              slug: "oliver-family",
              name: "Oliver Family",
              status: "active",
              role: "owner",
            },
          ],
        }),
      ),
      http.post(`${apiBaseUrl}/api/family-spaces`, async ({ request }) => {
        const input = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(
          {
            data: {
              id: "01K20000000000000000000000",
              status: "active",
              role: "owner",
              ...input,
            },
          },
          { status: 201 },
        );
      }),
      http.get(`${apiBaseUrl}/api/families/oliver-family`, () =>
        HttpResponse.json({
          data: {
            id: "01K1ZZZZZZZZZZZZZZZZZZZZZZ",
            slug: "oliver-family",
            name: "Oliver Family",
            status: "active",
            role: "owner",
          },
        }),
      ),
    );

    await expect(getFamilySpaces()).resolves.toHaveLength(1);
    await expect(getFamilySpace("oliver-family")).resolves.toMatchObject({
      slug: "oliver-family",
    });
    await expect(
      createFamilySpace({ name: "New Family", slug: "new-family" }),
    ).resolves.toMatchObject({ slug: "new-family", role: "owner" });
  });

  it("requests and cancels deletion through the Family Space endpoint", async () => {
    server.use(
      http.post(`${apiBaseUrl}/api/families/oliver-family/deletion`, () =>
        HttpResponse.json({
          data: {
            id: "01K1ZZZZZZZZZZZZZZZZZZZZZZ",
            slug: "oliver-family",
            name: "Oliver Family",
            status: "deletion_requested",
            role: "owner",
          },
        }),
      ),
      http.delete(`${apiBaseUrl}/api/families/oliver-family/deletion`, () =>
        HttpResponse.json({
          data: {
            id: "01K1ZZZZZZZZZZZZZZZZZZZZZZ",
            slug: "oliver-family",
            name: "Oliver Family",
            status: "active",
            role: "owner",
          },
        }),
      ),
    );

    await expect(
      requestFamilySpaceDeletion("oliver-family"),
    ).resolves.toMatchObject({
      status: "deletion_requested",
    });
    await expect(
      cancelFamilySpaceDeletion("oliver-family"),
    ).resolves.toMatchObject({
      status: "active",
    });
  });

  it("updates settings, transfers ownership, and leaves through bounded family actions", async () => {
    server.use(
      http.patch(
        `${apiBaseUrl}/api/families/oliver-family`,
        async ({ request }) => {
          const input = (await request.json()) as Record<string, unknown>;
          return HttpResponse.json({
            data: {
              id: "01K1ZZZZZZZZZZZZZZZZZZZZZZ",
              slug: "oliver-family",
              status: "active",
              role: "owner",
              ...input,
            },
          });
        },
      ),
      http.post(
        `${apiBaseUrl}/api/families/oliver-family/ownership-transfer`,
        async ({ request }) => {
          const input = (await request.json()) as { membership_id: string };
          expect(input.membership_id).toBe("01K20000000000000000000000");
          return HttpResponse.json({
            data: {
              id: "01K1ZZZZZZZZZZZZZZZZZZZZZZ",
              slug: "oliver-family",
              name: "Oliver Family",
              status: "active",
              role: "administrator",
            },
          });
        },
      ),
      http.post(
        `${apiBaseUrl}/api/families/oliver-family/leave`,
        () => new HttpResponse(null, { status: 204 }),
      ),
    );

    await expect(
      updateFamilySpace("oliver-family", {
        name: "The Oliver Family",
        description: null,
        default_visibility: "private",
      }),
    ).resolves.toMatchObject({
      name: "The Oliver Family",
      default_visibility: "private",
    });
    await expect(
      transferFamilySpaceOwnership(
        "oliver-family",
        "01K20000000000000000000000",
      ),
    ).resolves.toMatchObject({ role: "administrator" });
    await expect(leaveFamilySpace("oliver-family")).resolves.toBeUndefined();
  });

  it("preserves a forbidden creation response", async () => {
    server.use(
      http.post(`${apiBaseUrl}/api/family-spaces`, () =>
        HttpResponse.json({ message: "Forbidden." }, { status: 403 }),
      ),
    );

    try {
      await createFamilySpace({ name: "New Family", slug: "new-family" });
      throw new Error("Expected creation to be forbidden");
    } catch (error) {
      expect(toAppError(error).status).toBe(403);
    }
  });
});
