import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import {
  dismissDuplicateCandidate,
  flagPhotoDuplicate,
  getDuplicateCandidates,
  getDuplicateDecisions,
  reopenDuplicateDecision,
} from "./duplicateApi";

describe("duplicateApi", () => {
  it("owns review, dismissal, reopening and member-flag endpoint paths", async () => {
    const base = "http://localhost:8082/api/families/family-archive";
    const photo = {
      id: "photo-1",
      media_upload_id: "upload-1",
      caption: "Family lunch",
      client_filename: "lunch.jpg",
      visibility: "family_space",
      created_at: "2026-08-26T10:00:00Z",
    };
    const candidate = {
      id: "candidate-1",
      source: "perceptual",
      algorithm: "dhash-luma-64",
      processing_version: 1,
      score: 8,
      photo,
      candidate_photo: { ...photo, id: "photo-2", media_upload_id: "upload-2" },
      created_at: "2026-08-26T10:00:00Z",
    };
    const decision = {
      id: "decision-1",
      source: "perceptual_review",
      photo,
      candidate_photo: { ...photo, id: "photo-2", media_upload_id: "upload-2" },
      decided_at: "2026-08-26T11:00:00Z",
    };
    server.use(
      http.get(`${base}/duplicate-candidates`, () =>
        HttpResponse.json({ data: [candidate] }),
      ),
      http.get(`${base}/duplicate-decisions`, () =>
        HttpResponse.json({ data: [decision] }),
      ),
      http.post(`${base}/duplicate-candidates/candidate-1/dismiss`, () =>
        HttpResponse.json({ data: decision }),
      ),
      http.post(`${base}/duplicate-decisions/decision-1/reopen`, () =>
        HttpResponse.json({ data: { id: "decision-1", status: "reopened" } }),
      ),
      http.post(`${base}/photos/photo-1/duplicate-flags`, () =>
        HttpResponse.json({ data: { id: "candidate-1" } }, { status: 201 }),
      ),
    );

    await expect(
      getDuplicateCandidates("family-archive"),
    ).resolves.toMatchObject([
      {
        id: "candidate-1",
        photo: { image_url: `${base}/media-uploads/upload-1/canonical` },
      },
    ]);
    await expect(getDuplicateDecisions("family-archive")).resolves.toHaveLength(
      1,
    );
    await expect(
      dismissDuplicateCandidate("family-archive", "candidate-1"),
    ).resolves.toBeUndefined();
    await expect(
      reopenDuplicateDecision("family-archive", "decision-1"),
    ).resolves.toBeUndefined();
    await expect(
      flagPhotoDuplicate("family-archive", "photo-1", "photo-2"),
    ).resolves.toBeUndefined();
  });
});
