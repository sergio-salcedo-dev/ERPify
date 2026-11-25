import { describe, expect, it } from "vitest";
import { render } from "@testing-library/react";
import BackOfficeLoading from "@/app/backoffice/loading";

/**
 * The segment fallback is decorative: the destination page announces its own state, so
 * the placeholder must stay out of the accessibility tree and must not be a live region
 * (it would speak on every transition).
 */
describe("BackOfficeLoading", () => {
  it("renders a hidden, busy placeholder that is not a live region", () => {
    const { container } = render(<BackOfficeLoading />);
    const root = container.firstElementChild;

    expect(root).toHaveAttribute("aria-hidden", "true");
    expect(root).toHaveAttribute("aria-busy", "true");
    expect(root).not.toHaveAttribute("role");
    expect(root?.querySelectorAll(".bg-muted").length).toBeGreaterThan(0);
  });
});
