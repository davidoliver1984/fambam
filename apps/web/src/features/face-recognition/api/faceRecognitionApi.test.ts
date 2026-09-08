import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import {
  approveFaceIdentityAssignment,
  getFaceClusters,
  getFaceIdentityAssignments,
  getFaceIdentitySuppressions,
  generateFaceIdentitySuggestions,
  mergeFaceClusters,
  nameFaceCluster,
  proposeFaceIdentity,
  rejectFaceIdentityAssignment,
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
    expect(paths).toEqual([
      "/api/families/family-archive/face-identity-assignments",
      "/api/families/family-archive/face-observations/observation-1/identity-assignments",
      "/api/families/family-archive/face-observations/observation-1/identity-suggestions",
      "/api/families/family-archive/face-identity-assignments/assignment-1/approve",
      "/api/families/family-archive/face-identity-assignments/assignment-1/reject",
      "/api/families/family-archive/face-identity-suppressions",
      "/api/families/family-archive/face-identity-suppressions/suppression-1/reopen",
      "/api/families/family-archive/face-clusters",
      "/api/families/family-archive/face-clusters/cluster-1/name",
      "/api/families/family-archive/face-clusters/merge",
      "/api/families/family-archive/face-clusters/cluster-1/split",
    ]);
  });
});
