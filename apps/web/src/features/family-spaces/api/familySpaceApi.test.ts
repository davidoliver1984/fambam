import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { toAppError } from "@/api/errors";
import { server } from "@/test/msw/server";

import {
  createFamilySpace,
  getFamilySpace,
  getFamilySpaces,
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
