import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { HealthComponentRow } from "@/app/backoffice/health/_components/HealthComponentRow";
import { SystemStatus } from "@/lib/systemStatus";

describe("HealthComponentRow", () => {
  it("renders the component name and its operational label", () => {
    render(
      <HealthComponentRow name="BackOffice API" status={SystemStatus.OPERATIONAL} testId="x-row" />,
    );
    const row = screen.getByTestId("x-row");
    expect(row).toHaveTextContent("BackOffice API");
    expect(row).toHaveTextContent("Operational");
  });

  it("shows the disrupted label when the component is down", () => {
    render(
      <HealthComponentRow name="BackOffice API" status={SystemStatus.DISRUPTED} testId="x-row" />,
    );
    expect(screen.getByTestId("x-row")).toHaveTextContent("Disrupted");
  });
});
