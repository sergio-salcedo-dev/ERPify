import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { SystemStatusBanner } from "@/app/backoffice/health/_components/SystemStatusBanner";
import { SystemStatus } from "@/context/shared/system-status/domain/SystemStatus";

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

  it("shows the partial-disruption headline and an 'as of' subline when degraded", () => {
    render(
      <SystemStatusBanner
        status={SystemStatus.DEGRADED}
        datetime="2026-06-02T10:00:00+02:00"
        testId="x-banner"
      />,
    );
    const banner = screen.getByTestId("x-banner");
    expect(banner).toHaveTextContent("Partial Service Disruption");
    expect(banner).toHaveTextContent(/as of/i);
    expect(banner).not.toHaveTextContent(/trouble reaching this service/i);
  });

  it("shows a disruption message and no datetime when disrupted", () => {
    render(
      <SystemStatusBanner status={SystemStatus.DISRUPTED} datetime={null} testId="x-banner" />,
    );
    const banner = screen.getByTestId("x-banner");
    expect(banner).toHaveTextContent("Service Disruption");
    expect(banner).toHaveTextContent(/trouble reaching this service/i);
    expect(banner).not.toHaveTextContent(/as of/i);
  });

  it("carries the detail inside its own live region, visually hidden", () => {
    // Inside, not beside: a second live region would announce over this one, which is what the
    // per-row regions did before they were removed.
    render(
      <SystemStatusBanner
        status={SystemStatus.DISRUPTED}
        datetime={null}
        detail="BackOffice API: Disrupted. Database: Degraded."
        testId="x-banner"
      />,
    );
    const banner = screen.getByTestId("x-banner");
    const detail = screen.getByTestId("x-banner__detail");
    expect(banner).toContainElement(detail);
    expect(detail).toHaveClass("sr-only");
    expect(detail).toHaveTextContent("Database: Degraded.");
  });

  it("renders no detail element when the caller passes none", () => {
    render(
      <SystemStatusBanner status={SystemStatus.OPERATIONAL} datetime={null} testId="x-banner" />,
    );
    expect(screen.queryByTestId("x-banner__detail")).not.toBeInTheDocument();
  });

  it("renders an aria-hidden icon", () => {
    const { container } = render(
      <SystemStatusBanner status={SystemStatus.OPERATIONAL} datetime={null} testId="x-banner" />,
    );
    const svg = container.querySelector("svg");
    expect(svg).toHaveAttribute("aria-hidden", "true");
  });
});
