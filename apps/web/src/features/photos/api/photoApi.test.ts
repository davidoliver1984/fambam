import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import {
  createPhoto,
  getPhoto,
  getPhotoProvenanceProposals,
  getPhotos,
  replacePhotoTags,
  resolvePhotoProvenanceProposal,
  submitPhotoProvenance,
  updatePhoto,
} from "./photoApi";
import type { CreatePhotoInput, Photo } from "../types/photo";

const apiBaseUrl = "http://localhost:8082";
const photo: Photo = {
  id: "01K60000000000000000000000",
  media_upload: {
    id: "01K50000000000000000000000",
    client_filename: "family.jpg",
    uploader: { id: 1, name: "David" },
  },
  created_by: 1,
  visibility: "family_space",
  caption: "Family picnic",
  description: null,
  archive_source_description: "Green album",
  provenance: {
    photographer: { person: null, description: null },
    scanner: { person: null, description: null },
    physical_owner: { person: null, description: null },
  },
  tags: [{ id: "01K70000000000000000000000", label: "Picnic" }],
  created_at: "2026-08-24T10:00:00Z",
  updated_at: "2026-08-24T10:00:00Z",
  permissions: {
    can_update: true,
    can_propose_provenance: true,
    can_resolve_provenance: true,
    can_manage_tags: true,
  },
};
const input: CreatePhotoInput = {
  media_upload_id: photo.media_upload.id,
  visibility: "family_space",
  caption: photo.caption,
  description: null,
  archive_source_description: photo.archive_source_description,
  tags: ["Picnic"],
};

describe("photoApi", () => {
  it("owns and unwraps every Phase 6 S02 Photo endpoint", async () => {
    const requests: string[] = [];
    const path = `${apiBaseUrl}/api/families/oliver-family/photos`;
    const detail = `${path}/${photo.id}`;
    const proposal = {
      id: "01K80000000000000000000000",
      photo_id: photo.id,
      role: "photographer" as const,
      person: null,
      description: "Unknown studio",
      clears_claim: false,
      status: "pending" as const,
      proposed_by: 2,
      resolved_by: null,
      resolved_at: null,
      created_at: "2026-08-24T11:00:00Z",
    };
    server.use(
      http.get(path, () => {
        requests.push("list");
        return HttpResponse.json({ data: [photo] });
      }),
      http.get(detail, () => {
        requests.push("show");
        return HttpResponse.json({ data: photo });
      }),
      http.post(path, () => {
        requests.push("create");
        return HttpResponse.json({ data: photo }, { status: 201 });
      }),
      http.patch(detail, () => {
        requests.push("update");
        return HttpResponse.json({ data: photo });
      }),
      http.put(`${detail}/tags`, () => {
        requests.push("tags");
        return HttpResponse.json({ data: photo });
      }),
      http.post(`${detail}/provenance-proposals`, () => {
        requests.push("propose");
        return HttpResponse.json({ data: proposal }, { status: 201 });
      }),
      http.get(`${detail}/provenance-proposals`, () => {
        requests.push("proposals");
        return HttpResponse.json({ data: [proposal] });
      }),
      http.post(`${detail}/provenance-proposals/${proposal.id}/approve`, () => {
        requests.push("approve");
        return HttpResponse.json({ data: { ...proposal, status: "approved" } });
      }),
    );

    await expect(getPhotos("oliver-family")).resolves.toEqual([photo]);
    await expect(getPhoto("oliver-family", photo.id)).resolves.toEqual(photo);
    await expect(createPhoto("oliver-family", input)).resolves.toEqual(photo);
    await expect(
      updatePhoto("oliver-family", photo.id, input),
    ).resolves.toEqual(photo);
    await expect(
      replacePhotoTags("oliver-family", photo.id, ["Picnic"]),
    ).resolves.toEqual(photo);
    await expect(
      submitPhotoProvenance("oliver-family", photo.id, {
        role: "photographer",
        description: "Unknown studio",
      }),
    ).resolves.toMatchObject({ status: "pending" });
    await expect(
      getPhotoProvenanceProposals("oliver-family", photo.id),
    ).resolves.toEqual([proposal]);
    await expect(
      resolvePhotoProvenanceProposal(
        "oliver-family",
        photo.id,
        proposal.id,
        "approve",
      ),
    ).resolves.toMatchObject({ status: "approved" });
    expect(requests).toEqual([
      "list",
      "show",
      "create",
      "update",
      "tags",
      "propose",
      "proposals",
      "approve",
    ]);
  });
});
