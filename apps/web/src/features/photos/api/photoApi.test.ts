import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import {
  authorizePhotoPresentationDownload,
  createPhoto,
  getPhoto,
  getPhotoAlbumHistory,
  getPhotoMetadataProposals,
  getPhotoPersonProposals,
  getPhotoProvenanceProposals,
  getPhotos,
  getPromotableMediaUploads,
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
  do_not_resurface: false,
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
    can_flag_duplicate: false,
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
  it("requests an authorised active Photo presentation download", async () => {
    const authorization = {
      url: "https://storage.test/signed-presentation",
      expires_at: "2026-09-17T12:00:00Z",
      photo_version_id: "01K80000000000000000000000",
    };
    server.use(
      http.get(
        `${apiBaseUrl}/api/families/oliver-family/photos/${photo.id}/download`,
        () => HttpResponse.json({ data: authorization }),
      ),
    );

    await expect(
      authorizePhotoPresentationDownload("oliver-family", photo.id),
    ).resolves.toEqual(authorization);
  });
  it("fetches typed promotable upload summaries from the Photo feature", async () => {
    const upload = {
      id: "01K50000000000000000000001",
      client_filename: "nan-at-seaside.jpg",
      byte_size: 123_456,
      uploaded_at: "2026-08-24T09:30:00Z",
    };
    server.use(
      http.get(
        `${apiBaseUrl}/api/families/oliver-family/photos/promotable-uploads`,
        () => HttpResponse.json({ data: [upload] }),
      ),
    );

    await expect(getPromotableMediaUploads("oliver-family")).resolves.toEqual([
      upload,
    ]);
  });

  it("fetches the Photo Album history read model in one request", async () => {
    const history = [
      {
        event_type: "added" as const,
        album: { id: "01KH0000000000000000000000", name: "Blackpool, 1986" },
        actor: {
          display_name: "David Mercer",
          person_id: "01KP0000000000000000000000",
          initials: "DM",
          portrait_thumbnail_url: null,
        },
        created_at: "2026-09-14T10:00:00+00:00",
        is_current: true,
      },
    ];
    server.use(
      http.get(
        `${apiBaseUrl}/api/families/oliver-family/photos/${photo.id}/album-history`,
        () => HttpResponse.json({ data: history }),
      ),
    );

    await expect(
      getPhotoAlbumHistory("oliver-family", photo.id),
    ).resolves.toEqual(history);
  });

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
