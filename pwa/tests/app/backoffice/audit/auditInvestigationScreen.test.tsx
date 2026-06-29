import { describe, expect, it, vi, beforeEach } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { AuditInvestigationScreen } from "@/app/backoffice/audit/_components/AuditInvestigationScreen";
import { ViewStatus } from "@/context/shared/view-state/domain/ViewState";
import type { AuditEntry } from "@/context/backoffice/audit/domain/AuditEntry";
import type { AuditTimelineState } from "@/context/backoffice/audit/application/useAuditTimeline";

const replaceMock = vi.fn();
let searchParamsStr = "";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: replaceMock }),
  usePathname: () => "/backoffice/audit",
  useSearchParams: () => new URLSearchParams(searchParamsStr),
}));

let timelineState: AuditTimelineState;
vi.mock("@/context/backoffice/audit/application/useAuditTimeline", () => ({
  useAuditTimeline: () => timelineState,
}));

const ENTRY: AuditEntry = {
  id: "019f0691-2b5b-731e-9509-3470576159d6",
  occurredOn: "2026-06-12T12:00:00.000000+00:00",
  level: "security",
  action: "ACCESS_DENIED",
  actorType: "api_key",
  actorId: "019f0427-61f7-71d6-ae8f-b91d41227c29",
  correlationId: "019f0691-2aeb-7377-ba2d-1c9666c9ab90",
  resourceType: "BankAccount",
  resourceId: "019f0360-f3a4-7864-b0cc-0d41a56bf855",
  actorErased: false,
};

function stateWith(overrides: Partial<AuditTimelineState>): AuditTimelineState {
  return {
    state: ViewStatus.READY,
    entries: [ENTRY],
    pagination: null,
    paginationActions: { hasPrev: false, hasNext: false, goPrev: vi.fn(), goNext: vi.fn() },
    problem: null,
    reload: vi.fn(),
    ...overrides,
  };
}

beforeEach(() => {
  replaceMock.mockReset();
  searchParamsStr = "";
});

describe("AuditInvestigationScreen", () => {
  it("renders the screen header and the timeline when data is ready", () => {
    timelineState = stateWith({});
    render(<AuditInvestigationScreen />);
    expect(screen.getByRole("heading", { name: "Auditoría" })).toBeInTheDocument();
    expect(screen.getByTestId("audit-timeline")).toBeInTheDocument();
    expect(screen.getByTestId(`audit-timeline__row-${ENTRY.id}`)).toBeInTheDocument();
  });

  it("deep-links a row open via ?entry by writing the URL param", () => {
    timelineState = stateWith({});
    render(<AuditInvestigationScreen />);
    fireEvent.click(screen.getByTestId(`audit-timeline__row-${ENTRY.id}`));
    expect(replaceMock).toHaveBeenCalledTimes(1);
    expect(replaceMock.mock.calls[0][0]).toContain(`entry=${ENTRY.id}`);
  });

  it("opens the drawer for the row named by ?entry", () => {
    searchParamsStr = `entry=${ENTRY.id}`;
    timelineState = stateWith({});
    render(<AuditInvestigationScreen />);
    expect(screen.getByTestId("audit-entry-drawer")).toBeInTheDocument();
    expect(screen.getAllByText("Acceso denegado").length).toBeGreaterThan(0);
  });

  it("shows the filtered-to-zero empty state when a ready result is empty", () => {
    searchParamsStr = "level=security";
    timelineState = stateWith({ entries: [] });
    render(<AuditInvestigationScreen />);
    expect(screen.getByText("Ningún resultado")).toBeInTheDocument();
  });

  it("shows the first-run empty state when the log is genuinely empty", () => {
    timelineState = stateWith({ state: ViewStatus.EMPTY, entries: [] });
    render(<AuditInvestigationScreen />);
    expect(screen.getByText("Sin actividad registrada")).toBeInTheDocument();
  });

  it("gates the Jornada toggle until an actor is fixed", () => {
    timelineState = stateWith({});
    render(<AuditInvestigationScreen />);
    expect(screen.getByTestId("audit-view-toggle__journey")).toHaveAttribute(
      "aria-disabled",
      "true",
    );
    expect(screen.getByTestId("audit-view-toggle__hint")).toBeInTheDocument();
  });

  it("reconstructs the jornada grouped by correlation when an actor is fixed and view=journey", () => {
    searchParamsStr = `actorType=api_key&actorId=${ENTRY.actorId}&view=journey`;
    timelineState = stateWith({});
    render(<AuditInvestigationScreen />);
    // Jornada is now reachable (no hint) and the session header summarises the correlation.
    expect(screen.queryByTestId("audit-view-toggle__hint")).toBeNull();
    expect(screen.getByText(/1 entrada/)).toBeInTheDocument();
    expect(screen.getByTestId(`audit-timeline__row-${ENTRY.id}`)).toBeInTheDocument();
  });

  it("renders an error panel with a retry action", () => {
    timelineState = stateWith({
      state: ViewStatus.ERROR,
      entries: [],
      problem: {
        type: "about:blank",
        title: "Error de carga",
        status: 500,
        detail: "boom",
        instance: "i",
        "correlation-id": "019f0691-2aeb-7377-ba2d-1c9666c9ab90",
      },
    });
    render(<AuditInvestigationScreen />);
    expect(screen.getByText("Error de carga")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /reintentar/i })).toBeInTheDocument();
  });
});
