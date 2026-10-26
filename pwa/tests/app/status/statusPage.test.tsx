import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { AuthStatus } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { HealthCheck } from "@/context/frontoffice/health/domain/HealthCheck";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { telemetry } from "@/context/shared/observability/infrastructure";
import StatusPage from "@/app/status/page";

const run = vi.hoisted(() => vi.fn());
const push = vi.hoisted(() => vi.fn());

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, refresh: vi.fn(), back: vi.fn() }),
}));

vi.mock("@/context/shared/access/application/useSession", () => ({
  useSession: () => ({ status: AuthStatus.AUTHENTICATED, refresh: vi.fn() }),
}));

vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "FrontOfficeCheckHealth") return { run };
      throw new Error(`Unexpected DI token ${token}`);
    },
  },
}));

vi.mock("@/context/shared/observability/infrastructure", () => ({
  telemetry: { warn: vi.fn(), error: vi.fn() },
}));

const HEALTHY = HealthCheck.fromPrimitives({
  status: "ok",
  service: "frontoffice",
  datetime: "2031-04-07T09:15:00+00:00",
});

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((r) => {
    resolve = r;
  });
  return { promise, resolve };
}

describe("StatusPage", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    // A queued `mockReturnValueOnce` survives `clearAllMocks`; reset so a test
    // that never consumed its pending check cannot hand it to the next one.
    run.mockReset();
  });

  it("reports every component operational once the health check succeeds", async () => {
    run.mockResolvedValue(HEALTHY);
    render(<StatusPage />);

    await waitFor(() =>
      expect(screen.getByTestId("status-page__banner")).toHaveTextContent(
        "All Systems Operational",
      ),
    );
    expect(screen.getByTestId("status-page__component-frontoffice")).toHaveTextContent(
      "Operational",
    );
    expect(screen.getByTestId("status-page__refresh")).toHaveTextContent("Refresh");
    expect(screen.getByTestId("status-page__refresh")).toBeEnabled();
    expect(telemetry.warn).not.toHaveBeenCalled();
  });

  it("reports a disruption and logs the cause through the telemetry port when the check fails", async () => {
    const failure = new Error("connection refused");
    run.mockRejectedValue(failure);
    render(<StatusPage />);

    await waitFor(() =>
      expect(screen.getByTestId("status-page__banner")).toHaveTextContent("Service Disruption"),
    );
    expect(screen.getByTestId("status-page__component-frontoffice")).toHaveTextContent("Disrupted");
    expect(telemetry.warn).toHaveBeenCalledWith("FrontOffice health check failed", {
      scope: "api:frontoffice-health",
      cause: failure,
    });
    expect(screen.getByTestId("status-page__refresh")).toBeEnabled();
  });

  it("re-runs the check on Refresh, showing Checking… and disabling the button while it runs", async () => {
    run.mockRejectedValueOnce(new Error("down"));
    render(<StatusPage />);
    await waitFor(() =>
      expect(screen.getByTestId("status-page__banner")).toHaveTextContent("Service Disruption"),
    );

    const pending = deferred<HealthCheck>();
    run.mockReturnValueOnce(pending.promise);
    fireEvent.click(screen.getByTestId("status-page__refresh"));

    const refresh = screen.getByTestId("status-page__refresh");
    await waitFor(() => expect(refresh).toHaveTextContent("Checking…"));
    expect(refresh).toBeDisabled();
    expect(run).toHaveBeenCalledTimes(2);

    pending.resolve(HEALTHY);

    await waitFor(() =>
      expect(screen.getByTestId("status-page__banner")).toHaveTextContent(
        "All Systems Operational",
      ),
    );
    expect(refresh).toHaveTextContent("Refresh");
    expect(refresh).toBeEnabled();
  });

  it("sends the navbar's back-office action to the back-office route", async () => {
    run.mockResolvedValue(HEALTHY);
    render(<StatusPage />);
    await waitFor(() => expect(screen.getByTestId("status-page__refresh")).toBeEnabled());

    fireEvent.click(screen.getByTestId("navbar__go-to-backoffice-button"));

    expect(push).toHaveBeenCalledWith(Routes.BACKOFFICE);
  });
});
