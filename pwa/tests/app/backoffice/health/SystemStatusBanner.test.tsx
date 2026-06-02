import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { SystemStatusBanner } from "@/app/backoffice/health/_components/SystemStatusBanner";
import { SystemStatus } from "@/lib/systemStatus";

describe("SystemStatusBanner", () => {
  it("shows the operational headline and an 'as of' subline with the datetime", () => {
    render(
      <SystemStatusBanner
        status={SystemStatus.OPERATIONAL}
        datetime="2026-06-02T10:00:00+02:00"
        testId="x-banner"
      />,
    );
    const banner = screen.getByTestId("x-banner");
    expect(banner).toHaveTextContent("All Systems Operational");
    expect(banner).toHaveTextContent(/as of/i);
  });

  it("exposes role=status with aria-live and is aria-busy only while checking", () => {
    const { rerender } = render(
      <SystemStatusBanner status={SystemStatus.CHECKING} datetime={null} testId="x-banner" />,
    );
    const banner = screen.getByTestId("x-banner");
    expect(banner).toHaveAttribute("role", "status");
    expect(banner).toHaveAttribute("aria-live", "polite");
    expect(banner).toHaveAttribute("aria-busy", "true");

    rerender(
      <SystemStatusBanner status={SystemStatus.OPERATIONAL} datetime={null} testId="x-banner" />,
    );
    expect(screen.getByTestId("x-banner")).toHaveAttribute("aria-busy", "false");
  });

  it("shows a disruption message and no datetime when disrupted", () => {
    render(<SystemStatusBanner status={SystemStatus.DISRUPTED} datetime={null} testId="x-banner" />);
    const banner = screen.getByTestId("x-banner");
    expect(banner).toHaveTextContent("Service Disruption");
    expect(banner).toHaveTextContent(/trouble reaching this service/i);
    expect(banner).not.toHaveTextContent(/as of/i);
  });

  it("renders an aria-hidden icon", () => {
    const { container } = render(
      <SystemStatusBanner status={SystemStatus.OPERATIONAL} datetime={null} testId="x-banner" />,
    );
    const svg = container.querySelector("svg");
    expect(svg).toHaveAttribute("aria-hidden", "true");
  });
});
