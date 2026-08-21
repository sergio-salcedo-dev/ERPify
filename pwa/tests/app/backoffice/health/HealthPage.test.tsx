import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";
import HealthPage from "@/app/backoffice/health/page";
import { HealthCheck } from "@/context/backoffice/health/domain/HealthCheck";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

const backofficeRun = vi.hoisted(() => vi.fn());
const databaseRun = vi.hoisted(() => vi.fn());

vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeCheckHealth") return { run: backofficeRun };
      if (token === "BackOfficeCheckDatabaseHealth") return { run: databaseRun };
      throw new Error(`Unexpected DI token ${token}`);
    },
  },
}));

const PROBLEM: ProblemDetails = {
  type: "health-check-failed",
  title: "Health check failed",
  status: 0,
  detail: "connection refused",
  instance: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
  "correlation-id": "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5d",
};

describe("HealthPage", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("reads the roll call from the banner's live region once both checks resolve", async () => {
    backofficeRun.mockResolvedValue(
      HealthCheck.fromPrimitives({
        status: "ok",
        service: "backoffice",
        datetime: "2026-06-02T10:00:00+02:00",
      }),
    );
    databaseRun.mockResolvedValue(
      HealthCheck.fromPrimitives({
        status: "ok",
        service: "database",
        datetime: "2026-06-02T10:00:00+02:00",
      }),
    );

    render(<HealthPage />);

    await waitFor(() => {
      expect(screen.getByTestId("backoffice-health__banner")).toHaveTextContent(
        "BackOffice API: Operational. Database: Operational.",
      );
    });
    expect(screen.getByTestId("backoffice-health__component-backoffice")).toHaveTextContent(
      "Operational",
    );
    expect(screen.getByTestId("backoffice-health__component-database")).toHaveTextContent(
      "Operational",
    );
  });

  it("re-runs both checks and shows the checking roll call on refresh", async () => {
    backofficeRun.mockResolvedValue(
      HealthCheck.fromPrimitives({
        status: "ok",
        service: "backoffice",
        datetime: "2026-06-02T10:00:00+02:00",
      }),
    );
    databaseRun.mockResolvedValue(
      HealthCheck.fromPrimitives({
        status: "ok",
        service: "database",
        datetime: "2026-06-02T10:00:00+02:00",
      }),
    );

    render(<HealthPage />);
    await waitFor(() => expect(backofficeRun).toHaveBeenCalledTimes(1));

    fireEvent.click(screen.getByTestId("backoffice-health__refresh"));

    // The default MONITORED_COMPONENTS is fixed, so every refresh reports CHECKING for both
    // — this is the line the roll call's punctuation guard exists for (see SystemStatus.ts).
    expect(screen.getByTestId("backoffice-health__banner")).toHaveTextContent(
      "BackOffice API: Checking. Database: Checking.",
    );
    await waitFor(() => expect(backofficeRun).toHaveBeenCalledTimes(2));
  });

  it("reports a degraded component in the roll call and renders its problem", async () => {
    backofficeRun.mockResolvedValue(
      HealthCheck.fromPrimitives({
        status: "ok",
        service: "backoffice",
        datetime: "2026-06-02T10:00:00+02:00",
      }),
    );
    databaseRun.mockRejectedValue(new HttpError(PROBLEM));

    render(<HealthPage />);

    await waitFor(() => {
      expect(screen.getByTestId("backoffice-health__banner")).toHaveTextContent(
        "BackOffice API: Operational. Database: Disrupted.",
      );
    });
    expect(screen.getByText(PROBLEM.title)).toBeInTheDocument();
  });
});
