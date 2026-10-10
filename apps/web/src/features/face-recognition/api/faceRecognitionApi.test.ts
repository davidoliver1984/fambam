import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";
import type { FaceReviewSession } from "../types/faceRecognition";

import {
  approveFaceIdentityAssignment,
  getFaceClusters,
  getFaceIdentityAssignments,
  getFaceIdentitySuppressions,
  getFaceReview,
  generateFaceIdentitySuggestions,
  mergeFaceClusters,
  nameFaceCluster,
  parseFaceReviewSession,
  proposeFaceIdentity,
  rejectFaceIdentityAssignment,
  leaveFaceUnidentified,
  reopenFaceIdentitySuppression,
  splitFaceCluster,
} from "./faceRecognitionApi";

const observation = {
  id: "observation-1",
  face_index: 0,
  bounds: { x: 0.1, y: 0.2, width: 0.3, height: 0.4 },
  photo_id: "photo-1",
  media_upload_id: "upload-1",
  image_width: 1,
  image_height: 1,
};
const assignment = {
  id: "assignment-1",
  status: "pending" as const,
  proposal_source: "automatic_suggestion",
  person: { id: "person-1", preferred_name: "Alex" },
  observation,
  created_at: "2026-09-07T10:00:00Z",
};
const suppression = {
  id: "suppression-1",
  person: assignment.person,
  observation,
  decided_at: "2026-09-07T10:00:00Z",
};
const cluster = {
  id: "cluster-1",
  generation_id: "generation-1",
  status: "active" as const,
  members: [{ id: "member-1", observation }],
};

describe("faceRecognitionApi", () => {
  it("rejects ambiguous or inconsistent face-review presentation states", () => {
    expect(() =>
      parseFaceReviewSession({
        scope: { upload_batch_id: null, photo_id: "photo-1", person_id: null },
        summary: {} as never,
        pagination: { page: 1, limit: 1, has_more: false },
        photos: [
          {
            photo_id: "photo-1",
            upload_batch_id: null,
            caption: null,
            display_label: "Photo",
            media: {} as never,
            analysis: {
              state: "succeeded",
              review_state: "succeeded_with_unresolved_faces",
              succeeded_with_zero_faces: false,
            },
            detected_face_count: 1,
            reviewed_count: 0,
            remaining_count: 1,
            observations: [
              {
                id: "observation-1",
                face_index: 0,
                bounds: { x: 0, y: 0, width: 1, height: 1 },
                review_state: "automatic_suggestion",
                reviewed: true,
                suggested_people: [],
                current_proposal: null,
                current_identity: null,
                identity_assignment: null,
                permissions: {
                  can_assign: true,
                  can_change: true,
                  can_leave_unidentified: true,
                  can_approve: false,
                  can_reject: false,
                },
              },
            ],
          },
        ],
      }),
    ).toThrow("Inconsistent face observation review progress.");
  });

  it("rejects read models that escape their requested review scope", () => {
    const photoScoped: FaceReviewSession = {
      scope: { upload_batch_id: null, photo_id: "photo-1", person_id: null },
      summary: {
        total_photos: 1,
        analysis: {
          pending: 0,
          processing: 0,
          succeeded: 1,
          failed: 0,
          succeeded_with_zero_faces: 1,
          succeeded_with_unresolved_faces: 0,
          succeeded_with_all_faces_resolved: 0,
        },
        total_faces: 0,
        reviewed_count: 0,
        remaining_count: 0,
        reviewable_photo_count: 0,
        current_photo_id: null,
        next_photo_id: null,
      },
      pagination: { page: 1, limit: 1, has_more: false },
      photos: [],
    };

    expect(() =>
      parseFaceReviewSession(
        {
          ...photoScoped,
          scope: {
            upload_batch_id: null,
            photo_id: "photo-2",
            person_id: null,
          },
        },
        { photoId: "photo-1" },
      ),
    ).toThrow("Photo-scoped face review escaped its requested Photo.");
    expect(() =>
      parseFaceReviewSession(
        {
          ...photoScoped,
          scope: {
            upload_batch_id: "batch-2",
            photo_id: null,
            person_id: null,
          },
        },
        { uploadBatchId: "batch-1" },
      ),
    ).toThrow("Batch-scoped face review escaped its upload batch.");
    expect(() =>
      parseFaceReviewSession(
        {
          ...photoScoped,
          scope: {
            upload_batch_id: null,
            photo_id: null,
            person_id: "person-2",
          },
        },
        { personId: "person-1" },
      ),
    ).toThrow("Person-scoped face review escaped its requested Person.");
  });

  it("owns all Phase 10 functional-review endpoint paths and image mapping", async () => {
    const base = "http://localhost:8082/api/families/family-archive";
    const paths: string[] = [];
    const respond =
      (data: unknown) =>
      ({ request }: { request: Request }) => {
        paths.push(new URL(request.url).pathname);
        return HttpResponse.json({ data });
      };
    server.use(
      http.get(`${base}/face-identity-assignments`, respond([assignment])),
      http.post(
        `${base}/face-observations/observation-1/identity-assignments`,
        respond(assignment),
      ),
      http.post(
        `${base}/face-observations/observation-1/identity-suggestions`,
        respond({
          observation_id: "observation-1",
          band: "shortlist",
          assignment_id: null,
          candidates: [assignment.person],
        }),
      ),
      http.post(
        `${base}/face-identity-assignments/assignment-1/approve`,
        respond(assignment),
      ),
      http.post(
        `${base}/face-identity-assignments/assignment-1/reject`,
        respond({ id: "suppression-1", status: "suppressed" }),
      ),
      http.get(`${base}/face-identity-suppressions`, respond([suppression])),
      http.get(`${base}/face-review`, ({ request }) => {
        const url = new URL(request.url);
        paths.push(`${url.pathname}?${url.searchParams.toString()}`);
        return HttpResponse.json({
          data: {
            scope: {
              upload_batch_id: "batch-1",
              photo_id: null,
              person_id: null,
            },
            summary: {
              total_photos: 1,
              analysis: {
                pending: 0,
                processing: 0,
                succeeded: 1,
                failed: 0,
                succeeded_with_zero_faces: 0,
              },
              total_faces: 1,
              reviewed_count: 0,
              remaining_count: 1,
              reviewable_photo_count: 1,
              current_photo_id: "photo-1",
              next_photo_id: null,
            },
            photos: [],
            pagination: { page: 1, limit: 25, has_more: false },
          },
        });
      }),
      http.put(
        `${base}/face-observations/observation-1/review/left-unidentified`,
        respond({
          observation_id: "observation-1",
          review_state: "left_unidentified",
          reviewed_at: "2026-10-02T10:00:00Z",
        }),
      ),
      http.post(
        `${base}/face-identity-suppressions/suppression-1/reopen`,
        respond({ id: "suppression-1", status: "reopened" }),
      ),
      http.get(`${base}/face-clusters`, ({ request }) => {
        paths.push(new URL(request.url).pathname);
        return HttpResponse.json({
          data: [cluster],
          recognition_processing_enabled: false,
        });
      }),
      http.post(
        `${base}/face-clusters/cluster-1/name`,
        respond({
          cluster_id: "cluster-1",
          assignment_count: 1,
          status: "confirmed",
        }),
      ),
      http.post(`${base}/face-clusters/merge`, respond(cluster)),
      http.post(`${base}/face-clusters/cluster-1/split`, respond([cluster])),
    );

    const assignments = await getFaceIdentityAssignments("family-archive");
    await proposeFaceIdentity("family-archive", "observation-1", "person-1");
    await generateFaceIdentitySuggestions("family-archive", "observation-1");
    await approveFaceIdentityAssignment("family-archive", "assignment-1");
    await rejectFaceIdentityAssignment("family-archive", "assignment-1");
    await getFaceIdentitySuppressions("family-archive");
    const review = await getFaceReview("family-archive", {
      uploadBatchId: "batch-1",
      limit: 25,
    });
    const leftUnidentified = await leaveFaceUnidentified(
      "family-archive",
      "observation-1",
    );
    await reopenFaceIdentitySuppression("family-archive", "suppression-1");
    const clusters = await getFaceClusters("family-archive");
    await nameFaceCluster("family-archive", "cluster-1", "person-1", true);
    await mergeFaceClusters("family-archive", ["cluster-1", "cluster-2"]);
    await splitFaceCluster("family-archive", "cluster-1", [
      ["observation-1"],
      ["observation-2"],
    ]);

    expect(assignments[0]?.observation.image_url).toBe(
      `${base}/media-uploads/upload-1/canonical`,
    );
    expect(clusters.recognition_processing_enabled).toBe(false);
    expect(clusters.clusters).toHaveLength(1);
    expect(review.summary.remaining_count).toBe(1);
    expect(leftUnidentified.review_state).toBe("left_unidentified");
    expect(paths).toEqual([
      "/api/families/family-archive/face-identity-assignments",
      "/api/families/family-archive/face-observations/observation-1/identity-assignments",
      "/api/families/family-archive/face-observations/observation-1/identity-suggestions",
      "/api/families/family-archive/face-identity-assignments/assignment-1/approve",
      "/api/families/family-archive/face-identity-assignments/assignment-1/reject",
      "/api/families/family-archive/face-identity-suppressions",
      "/api/families/family-archive/face-review?upload_batch_id=batch-1&limit=25",
      "/api/families/family-archive/face-observations/observation-1/review/left-unidentified",
      "/api/families/family-archive/face-identity-suppressions/suppression-1/reopen",
      "/api/families/family-archive/face-clusters",
      "/api/families/family-archive/face-clusters/cluster-1/name",
      "/api/families/family-archive/face-clusters/merge",
      "/api/families/family-archive/face-clusters/cluster-1/split",
    ]);
  });
});
