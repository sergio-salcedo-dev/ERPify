import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { ComponentStatusRow } from "@/app/status/_components/ComponentStatusRow";
import { SystemStatus } from "@/app/status/_components/systemStatus";

describe("ComponentStatusRow", () => {
  it("renders the component name and its operational label", () => {
    render(
      <ComponentStatusRow
        name="FrontOffice API"
        status={SystemStatus.OPERATIONAL}
        testId="x-row"
      />,
    );
    const row = screen.getByTestId("x-row");
    expect(row).toHaveTextContent("FrontOffice API");
    expect(row).toHaveTextContent("Operational");
  });

  it("shows the disrupted label when the component is down", () => {
    render(
      <ComponentStatusRow name="FrontOffice API" status={SystemStatus.DISRUPTED} testId="x-row" />,
    );
    expect(screen.getByTestId("x-row")).toHaveTextContent("Disrupted");
  });

  it("renders the status dot as decorative (aria-hidden)", () => {
    const { container } = render(
      <ComponentStatusRow
        name="FrontOffice API"
        status={SystemStatus.OPERATIONAL}
        testId="x-row"
      />,
    );
    const dot = container.querySelector(".component-status-row__dot");
    expect(dot).toHaveAttribute("aria-hidden", "true");
  });
});
