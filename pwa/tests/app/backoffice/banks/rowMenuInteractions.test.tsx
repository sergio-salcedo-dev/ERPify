import { useState } from "react";
import { describe, expect, it } from "vitest";
import { render } from "@testing-library/react";
import { openRowDeleteItem } from "./_interactions";

const SURFACE = "fake-table";
const ID = "1";

/**
 * Stand-in for a row overflow menu that loses the open race: the item only renders
 * once the trigger has been clicked more than `opensBeforeItemRenders` times,
 * mirroring a Base UI popup that closes again before its content mounts.
 */
function FlakyRowMenu({ opensBeforeItemRenders }: Readonly<{ opensBeforeItemRenders: number }>) {
  const [opens, setOpens] = useState(0);

  return (
    <div>
      <button
        type="button"
        data-testid={`${SURFACE}__actions-${ID}`}
        onClick={() => setOpens((count) => count + 1)}
      >
        More actions
      </button>
      {opens > opensBeforeItemRenders ? (
        <button type="button" data-testid={`${SURFACE}__delete-${ID}`}>
          Delete
        </button>
      ) : null}
    </div>
  );
}

describe("openRowDeleteItem", () => {
  it("retries the open when the first one renders no items", async () => {
    render(<FlakyRowMenu opensBeforeItemRenders={1} />);

    expect(await openRowDeleteItem(SURFACE, ID)).toBeInTheDocument();
  });

  it("still fails when the item never renders, so a real defect is not masked", async () => {
    render(<FlakyRowMenu opensBeforeItemRenders={Number.POSITIVE_INFINITY} />);

    await expect(openRowDeleteItem(SURFACE, ID)).rejects.toThrow();
  });
});
