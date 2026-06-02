import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { StatusBanner } from "@/app/status/_components/StatusBanner";
import { SystemStatus } from "@/app/status/_components/systemStatus";

describe("StatusBanner", () => {
  it("shows the operational headline and an 'as of' subline with the datetime", () => {
    render(
      <StatusBanner
        status={SystemStatus.OPERATIONAL}
        datetime="2026-06-02T10:00:00+02:00"
        testId="x-banner"
      />,
    );
    const banner = screen.getByTestId("x-banner");
    expect(banner).toHaveTextContent("All Systems Operational");
    expect(banner).toHaveTextContent(/as of/i);
  });

  it("exposes role=status with aria-live for assistive tech", () => {
    render(<StatusBanner status={SystemStatus.CHECKING} datetime={null} testId="x-banner" />);
    const banner = screen.getByTestId("x-banner");
    expect(banner).toHaveAttribute("role", "status");
    expect(banner).toHaveAttribute("aria-live", "polite");
  });

  it("shows a friendly disruption message and no datetime when disrupted", () => {
    render(<StatusBanner status={SystemStatus.DISRUPTED} datetime={null} testId="x-banner" />);
    const banner = screen.getByTestId("x-banner");
    expect(banner).toHaveTextContent("Service Disruption");
    expect(banner).toHaveTextContent(/trouble reaching this service/i);
    expect(banner).not.toHaveTextContent(/as of/i);
  });

  it("renders an aria-hidden icon", () => {
    const { container } = render(
      <StatusBanner status={SystemStatus.OPERATIONAL} datetime={null} testId="x-banner" />,
    );
    const svg = container.querySelector("svg");
    expect(svg).toHaveAttribute("aria-hidden", "true");
  });

  it("shows the degraded headline with an 'as of' subline", () => {
    render(
      <StatusBanner
        status={SystemStatus.DEGRADED}
        datetime="2026-06-02T10:00:00+02:00"
        testId="x-banner"
      />,
    );
    const banner = screen.getByTestId("x-banner");
    expect(banner).toHaveTextContent("Partial Service Disruption");
    expect(banner).toHaveTextContent(/as of/i);
  });

  it("marks the banner aria-busy only while checking", () => {
    const { rerender } = render(
      <StatusBanner status={SystemStatus.CHECKING} datetime={null} testId="x-banner" />,
    );
    expect(screen.getByTestId("x-banner")).toHaveAttribute("aria-busy", "true");

    rerender(<StatusBanner status={SystemStatus.OPERATIONAL} datetime={null} testId="x-banner" />);
    expect(screen.getByTestId("x-banner")).toHaveAttribute("aria-busy", "false");
  });
});
