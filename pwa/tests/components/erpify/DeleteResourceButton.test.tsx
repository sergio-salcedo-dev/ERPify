import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { DeleteResourceButton } from "@/components/erpify/DeleteResourceButton";

describe("DeleteResourceButton", () => {
  /**
   * The confirmation reads as one sentence, so the resource label owns the space before it and the
   * question mark sits flush against it. Asserted on `textContent` rather than through
   * `toHaveTextContent`, which collapses whitespace and would pass over the exact defect this guards:
   * the label and the mark live in different JSX children, and a stray space between them is invisible
   * to every other check in the suite.
   */
  it("renders the confirmation as one sentence, with the mark flush against the label", async () => {
    render(
      <DeleteResourceButton
        id="bank-1"
        resourceLabel="Acme Bank"
        entityNoun="bank"
        testIdPrefix="banks-table"
        onConfirmDelete={vi.fn().mockResolvedValue(undefined)}
        onError={vi.fn()}
      />,
    );

    fireEvent.click(screen.getByTestId("banks-table__delete-button"));

    const description = await screen.findByText(/Are you sure you want to delete/);

    expect(description.textContent).toBe(
      "Are you sure you want to delete Acme Bank? This cannot be undone.",
    );
  });
});
