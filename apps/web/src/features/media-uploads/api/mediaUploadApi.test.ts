import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it, vi } from "vitest";

import { server } from "@/test/msw/server";

import { uploadMediaFile } from "./mediaUploadApi";

const apiBaseUrl = "http://localhost:8082";

afterEach(() => vi.restoreAllMocks());

describe("mediaUploadApi", () => {
  it("uploads directly with bounded headers before signalling completion", async () => {
    const calls: string[] = [];
    server.use(
      http.get(`${apiBaseUrl}/sanctum/csrf-cookie`, () =>
        HttpResponse.json({}),
      ),
      http.post(
        `${apiBaseUrl}/api/families/oliver-family/media-uploads`,
        async ({ request }) => {
          calls.push("initiate");
          expect(request.headers.get("Idempotency-Key")).toBe("stable-key");
          expect(await request.json()).toEqual({
            client_filename: "family.jpg",
            client_mime_type: "image/jpeg",
          });

          return HttpResponse.json(
            {
              data: {
                id: "01KUPLOAD00000000000000000",
                state: "initiated",
                client_filename: "family.jpg",
                byte_size: null,
                uploaded_at: null,
                upload_authorization: {
                  url: "https://storage.test/staging-object",
                  method: "PUT",
                  headers: { "If-None-Match": "*" },
                  expires_at: "2026-08-10T12:15:00+00:00",
                },
              },
            },
            { status: 201 },
          );
        },
      ),
      http.post(
        `${apiBaseUrl}/api/families/oliver-family/media-uploads/01KUPLOAD00000000000000000/complete`,
        () => {
          calls.push("complete");
          return HttpResponse.json({
            data: {
              id: "01KUPLOAD00000000000000000",
              state: "uploaded",
              client_filename: "family.jpg",
              byte_size: 4,
              uploaded_at: "2026-08-10T12:01:00+00:00",
              upload_authorization: null,
            },
          });
        },
      ),
    );
    vi.spyOn(globalThis, "fetch").mockImplementation((input, init) => {
      const url =
        input instanceof Request
          ? input.url
          : input instanceof URL
            ? input.href
            : input;
      expect(url).toBe("https://storage.test/staging-object");
      expect(init?.method).toBe("PUT");
      expect(init?.headers).toEqual({ "If-None-Match": "*" });
      calls.push("storage");
      return Promise.resolve(new Response(null, { status: 200 }));
    });

    const result = await uploadMediaFile(
      "oliver-family",
      new File(["data"], "family.jpg", { type: "image/jpeg" }),
      "stable-key",
    );

    expect(result.state).toBe("uploaded");
    expect(calls).toEqual(["initiate", "storage", "complete"]);
  });

  it("does not signal completion when object storage rejects the write", async () => {
    let completionCalled = false;
    server.use(
      http.get(`${apiBaseUrl}/sanctum/csrf-cookie`, () =>
        HttpResponse.json({}),
      ),
      http.post(`${apiBaseUrl}/api/families/oliver-family/media-uploads`, () =>
        HttpResponse.json({
          data: {
            id: "01KUPLOAD00000000000000000",
            state: "initiated",
            client_filename: "family.jpg",
            byte_size: null,
            uploaded_at: null,
            upload_authorization: {
              url: "https://storage.test/staging-object",
              method: "PUT",
              headers: { "If-None-Match": "*" },
              expires_at: "2026-08-10T12:15:00+00:00",
            },
          },
        }),
      ),
      http.post(
        `${apiBaseUrl}/api/families/oliver-family/media-uploads/01KUPLOAD00000000000000000/complete`,
        () => {
          completionCalled = true;
          return HttpResponse.json({ data: {} });
        },
      ),
    );
    vi.spyOn(globalThis, "fetch").mockResolvedValue(
      new Response(null, { status: 412 }),
    );

    await expect(
      uploadMediaFile(
        "oliver-family",
        new File(["data"], "family.jpg", { type: "image/jpeg" }),
        "stable-key",
      ),
    ).rejects.toThrow("Object storage rejected");
    expect(completionCalled).toBe(false);
  });
});
