import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import {
  getPersonMergeProposals,
  getPersonMerges,
  mergePerson,
  proposePersonMerge,
  resolvePersonMergeProposal,
  reversePersonMerge,
} from "./mergeApi";

const base = "http://localhost:8082/api/families/oliver-family";
const personId = "01K30000000000000000000000";
const survivorId = "01K30000000000000000000001";
const proposalId = "01K40000000000000000000000";
const mergeId = "01K50000000000000000000000";
const proposal = {
  id: proposalId,
  survivor: { id: survivorId, preferred_name: "Beth" },
  absorbed: { id: personId, preferred_name: "Ada" },
  context: "Same birth record",
  status: "pending" as const,
  person_merge_id: null,
  created_at: "2026-08-06T10:00:00Z",
};
const merge = {
  id: mergeId,
  survivor: proposal.survivor,
  absorbed: proposal.absorbed,
  status: "active" as const,
  merged_at: "2026-08-06T11:00:00Z",
  reversed_at: null,
};

describe("merge API module", () => {
  it("unwraps family-scoped query and mutation endpoints", async () => {
    server.use(
      http.get(`${base}/people/${personId}/merges`, () =>
        HttpResponse.json({ data: [merge] }),
      ),
      http.get(`${base}/people/${personId}/merge-proposals`, () =>
        HttpResponse.json({ data: [proposal] }),
      ),
      http.post(`${base}/people/${personId}/merge`, () =>
        HttpResponse.json({ data: merge }, { status: 201 }),
      ),
      http.post(`${base}/people/${personId}/merge-proposals`, () =>
        HttpResponse.json({ data: proposal }, { status: 201 }),
      ),
      http.post(
        `${base}/people/${personId}/merge-proposals/${proposalId}/approve`,
        () =>
          HttpResponse.json({
            data: { ...proposal, status: "approved", person_merge_id: mergeId },
          }),
      ),
      http.post(`${base}/person-merges/${mergeId}/reverse`, () =>
        HttpResponse.json({
          data: { ...merge, status: "reversed", reversed_at: "now" },
        }),
      ),
    );

    await expect(getPersonMerges("oliver-family", personId)).resolves.toEqual([
      merge,
    ]);
    await expect(
      getPersonMergeProposals("oliver-family", personId),
    ).resolves.toEqual([proposal]);
    await expect(
      mergePerson("oliver-family", personId, {
        survivor_person_id: survivorId,
      }),
    ).resolves.toEqual(merge);
    await expect(
      proposePersonMerge("oliver-family", personId, {
        survivor_person_id: survivorId,
        context: proposal.context,
      }),
    ).resolves.toEqual(proposal);
    await expect(
      resolvePersonMergeProposal(
        "oliver-family",
        personId,
        proposalId,
        "approve",
        "keep_survivor",
      ),
    ).resolves.toMatchObject({ status: "approved" });
    await expect(
      reversePersonMerge("oliver-family", mergeId),
    ).resolves.toMatchObject({ status: "reversed" });
  });
});
