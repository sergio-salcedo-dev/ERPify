import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import DocsFlowPage from "@/app/backoffice/docs/flow/page";

describe("DocsFlowPage legend", () => {
  /**
   * Each legend entry is a rule followed by its label, and the gap between them belongs to the flex
   * container. A text space there would double it, so the label's `textContent` must carry none —
   * `toHaveTextContent` normalises whitespace and cannot see that.
   */
  it.each(["Your request's path (there and back)", "In the background (asynchronous)"])(
    "separates %s from its rule by layout, never by a text space",
    (label) => {
      render(<DocsFlowPage />);

      const labels = screen.getAllByText(label);

      expect(labels.length).toBeGreaterThan(0);
      labels.forEach((node) => expect(node.textContent).toBe(label));
    },
  );
});
