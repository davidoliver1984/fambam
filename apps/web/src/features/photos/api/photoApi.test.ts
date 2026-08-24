import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import {
  createPhoto,
  getPhoto,
  getPhotoMetadataProposals,
  getPhotoPersonProposals,
  getPhotoProvenanceProposals,
  getPhotos,
  replacePhotoTags,
  resolvePhotoMetadataProposal,
  resolvePhotoPersonProposal,
  resolvePhotoProvenanceProposal,
  submitPhotoProvenance,
  submitPhotoMetadata,
  submitPhotoPerson,
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
  historical_date: { precision: "decade", value: "1980s" },
  location_description: "Blackpool",
  provenance: {
    photographer: { person: null, description: null },
    scanner: { person: null, description: null },
    physical_owner: { person: null, description: null },
  },
  tags: [{ id: "01K70000000000000000000000", label: "Picnic" }],
  people: [],
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
  it("omits inactive Photo filters from the initial archive request", async () => {
    let query = "not-called";
    server.use(
      http.get(
        `${apiBaseUrl}/api/families/oliver-family/photos`,
        ({ request }) => {
          query = new URL(request.url).search;
          return HttpResponse.json({ data: [] });
        },
      ),
    );

    await expect(
      getPhotos("oliver-family", {
        tag: "",
        location: "",
        historical_year: "",
        without_confirmed_date: false,
      }),
    ).resolves.toEqual([]);

    expect(query).toBe("");
  });

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

  it("owns and unwraps the S03 metadata and Photo Person endpoints", async () => {
    const detail = `${apiBaseUrl}/api/families/oliver-family/photos/${photo.id}`;
    const metadata = {
      id: "01KA0000000000000000000000",
      photo_id: photo.id,
      field: "historical_date" as const,
      date: { precision: "year" as const, value: "1987" },
      location_description: null,
      clears_claim: false,
      status: "pending" as const,
      proposed_by: 2,
      resolved_by: null,
      resolved_at: null,
      created_at: "2026-08-24T11:00:00Z",
    };
    const association = {
      id: "01KB0000000000000000000000",
      photo_id: photo.id,
      person: { id: "01K30000000000000000000000", preferred_name: "Aunt May" },
      proposal_source: "human",
      status: "pending" as const,
      proposed_by: 2,
      resolved_by: null,
      resolved_at: null,
      created_at: "2026-08-24T11:00:00Z",
    };
    server.use(
      http.post(`${detail}/metadata-proposals`, () =>
        HttpResponse.json({ data: metadata }, { status: 201 }),
      ),
      http.get(`${detail}/metadata-proposals`, () =>
        HttpResponse.json({ data: [metadata] }),
      ),
      http.post(`${detail}/metadata-proposals/${metadata.id}/approve`, () =>
        HttpResponse.json({ data: { ...metadata, status: "approved" } }),
      ),
      http.post(`${detail}/people`, () =>
        HttpResponse.json({ data: association }, { status: 201 }),
      ),
      http.get(`${detail}/person-proposals`, () =>
        HttpResponse.json({ data: [association] }),
      ),
      http.post(`${detail}/people/${association.id}/reject`, () =>
        HttpResponse.json({ data: { ...association, status: "rejected" } }),
      ),
    );

    await expect(
      submitPhotoMetadata("oliver-family", photo.id, {
        field: "historical_date",
        date: { precision: "year", value: "1987" },
      }),
    ).resolves.toEqual(metadata);
    await expect(
      getPhotoMetadataProposals("oliver-family", photo.id),
    ).resolves.toEqual([metadata]);
    await expect(
      resolvePhotoMetadataProposal(
        "oliver-family",
        photo.id,
        metadata.id,
        "approve",
      ),
    ).resolves.toMatchObject({ status: "approved" });
    await expect(
      submitPhotoPerson("oliver-family", photo.id, association.person.id),
    ).resolves.toEqual(association);
    await expect(
      getPhotoPersonProposals("oliver-family", photo.id),
    ).resolves.toEqual([association]);
    await expect(
      resolvePhotoPersonProposal(
        "oliver-family",
        photo.id,
        association.id,
        "reject",
      ),
    ).resolves.toMatchObject({ status: "rejected" });
  });
});
