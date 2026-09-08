import "@testing-library/jest-dom/vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { FaceCluster } from "../types/faceRecognition";
import { FaceClusterCard } from "./FaceClusterCard";

const person = {
  id: "person-1",
  preferred_name: "Alex",
};
const observation = {
  id: "observation-1",
  face_index: 0,
  bounds: { x: 0.1, y: 0.2, width: 0.3, height: 0.4 },
  media_upload_id: "upload-1",
  image_width: 1,
  image_height: 1,
  image_url: "http://localhost:8082/canonical",
};
const cluster: FaceCluster = {
  id: "cluster-1",
  generation_id: "generation-1",
  status: "active",
  members: [
    { id: "member-1", observation },
    {
      id: "member-2",
      observation: { ...observation, id: "observation-2", face_index: 1 },
    },
  ],
};

describe("FaceClusterCard", () => {
  it("supports manual proposal, authoritative naming and functional splitting", async () => {
    const propose = vi.fn();
    const name = vi.fn();
    const split = vi.fn();
    const user = userEvent.setup();
    const view = render(
      <FaceClusterCard
        cluster={cluster}
        people={[person]}
        canResolve
        pending={false}
        selected={false}
        onSelectionChange={vi.fn()}
        onName={name}
        onProposeFace={propose}
        onFindSuggestions={vi.fn()}
        recognitionProcessingEnabled
        suggestionErrorObservationId={null}
        suggestionErrorMessage={null}
        suggestion={{
          observation_id: "observation-1",
          band: "shortlist",
          assignment_id: null,
          candidates: [person],
        }}
        onSplit={split}
      />,
    );

    await user.selectOptions(screen.getAllByLabelText("Person")[0], "person-1");
    await user.click(
      screen.getAllByRole("button", { name: "Propose identity" })[0],
    );
    await user.click(screen.getByRole("button", { name: "Propose Alex" }));
    await user.selectOptions(
      screen.getByLabelText("Name this group"),
      "person-1",
    );
    await user.click(
      screen.getByRole("button", { name: "Confirm group identity" }),
    );
    await user.click(
      screen.getByRole("button", {
        name: "Split first face into a separate group",
      }),
    );

    expect(propose).toHaveBeenCalledTimes(2);
    expect(propose).toHaveBeenCalledWith("observation-1", "person-1");
    expect(name).toHaveBeenCalledWith("person-1");
    expect(split).toHaveBeenCalledWith([["observation-1"], ["observation-2"]]);
    expect(
      within(view.container).getAllByRole("button", {
        name: "Find possible identities",
      })[0],
    ).toBeEnabled();
  });

  it("disables automatic suggestions with a clear explanation when processing is off", () => {
    const view = render(
      <FaceClusterCard
        cluster={cluster}
        people={[person]}
        canResolve
        pending={false}
        selected={false}
        onSelectionChange={vi.fn()}
        onName={vi.fn()}
        onProposeFace={vi.fn()}
        onFindSuggestions={vi.fn()}
        recognitionProcessingEnabled={false}
        suggestionErrorObservationId={null}
        suggestionErrorMessage={null}
        suggestion={null}
        onSplit={vi.fn()}
      />,
    );

    expect(
      within(view.container).getAllByRole("button", {
        name: "Find possible identities",
      })[0],
    ).toBeDisabled();
    expect(
      within(view.container).getAllByText(
        "Recognition suggestions are not enabled for this family yet.",
      )[0],
    ).toBeVisible();
  });

  it("shows the structured disabled response beside the affected face", () => {
    const view = render(
      <FaceClusterCard
        cluster={cluster}
        people={[person]}
        canResolve
        pending={false}
        selected={false}
        onSelectionChange={vi.fn()}
        onName={vi.fn()}
        onProposeFace={vi.fn()}
        onFindSuggestions={vi.fn()}
        recognitionProcessingEnabled
        suggestionErrorObservationId="observation-1"
        suggestionErrorMessage="Recognition suggestions are not enabled for this family yet."
        suggestion={null}
        onSplit={vi.fn()}
      />,
    );

    expect(within(view.container).getByRole("alert")).toHaveTextContent(
      "Recognition suggestions are not enabled for this family yet.",
    );
  });
});
